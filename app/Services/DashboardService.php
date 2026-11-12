<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ComplianceDeadline;
use App\Models\Document;
use App\Models\GstReturn;
use App\Models\Invoice;
use App\Models\ItrFiling;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TdsReturn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates real database metrics, charts, and activity feeds for the practice dashboard.
 */
class DashboardService
{
    public function __construct(private readonly ClientAccessService $clientAccess) {}

    public function getDashboardData(User $user): array
    {
        $today = Carbon::today();
        $visibleClientIds = $this->clientAccess->visibleClientsQuery($user)->pluck('id');

        $totalActiveClients = Client::whereIn('id', $visibleClientIds)->where('status', 'active')->count();
        $totalGstClients = Client::whereIn('id', $visibleClientIds)->where('is_gst_registered', true)->count();
        $totalItrClients = Client::whereIn('id', $visibleClientIds)->where('is_itr_applicable', true)->count();

        $pendingGstReturns = GstReturn::whereIn('client_id', $visibleClientIds)
            ->where('status', '!=', 'filed')
            ->count();

        $pendingItrFilings = ItrFiling::whereIn('client_id', $visibleClientIds)
            ->whereNotIn('filing_status', ['filed', 'verified'])
            ->count();

        $pendingTdsReturns = TdsReturn::whereIn('client_id', $visibleClientIds)
            ->where('status', '!=', 'filed')
            ->count();

        $upcomingDeadlinesCount = ComplianceDeadline::query()
            ->where(function ($q) use ($visibleClientIds) {
                $q->whereNull('client_id')->orWhereIn('client_id', $visibleClientIds);
            })
            ->where('status', '!=', 'completed')
            ->whereBetween('due_date', [$today, $today->copy()->addDays(30)])
            ->count();

        $taskScope = Task::query()->where(function ($q) use ($visibleClientIds, $user) {
            $q->whereIn('client_id', $visibleClientIds)->orWhere('assigned_to', $user->id);
        });

        $overdueTasksCount = (clone $taskScope)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', '<', $today)
            ->count();

        $awaitingDocsCount = Document::whereIn('client_id', $visibleClientIds)
            ->where('status', 'requested')
            ->count();

        $outstandingFees = (float) Invoice::whereIn('client_id', $visibleClientIds)
            ->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])
            ->sum('balance_amount');

        $monthlyRevenue = (float) Payment::whereIn('client_id', $visibleClientIds)
            ->whereBetween('payment_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
            ->sum('amount');

        $totalTasks = (clone $taskScope)->count();
        $completedTasks = (clone $taskScope)->where('status', 'completed')->count();
        $taskCompletionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

        $upcomingDeadlines = ComplianceDeadline::with(['client', 'assignee'])
            ->where(function ($q) use ($visibleClientIds) {
                $q->whereNull('client_id')->orWhereIn('client_id', $visibleClientIds);
            })
            ->where('status', '!=', 'completed')
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        $recentActivities = ActivityLog::with('user')
            ->latest()
            ->limit(10)
            ->get();

        return [
            'kpis' => [
                'active_clients' => $totalActiveClients,
                'gst_clients' => $totalGstClients,
                'itr_clients' => $totalItrClients,
                'pending_gst' => $pendingGstReturns,
                'pending_itr' => $pendingItrFilings,
                'pending_tds' => $pendingTdsReturns,
                'upcoming_deadlines' => $upcomingDeadlinesCount,
                'overdue_tasks' => $overdueTasksCount,
                'awaiting_documents' => $awaitingDocsCount,
                'outstanding_fees' => $outstandingFees,
                'monthly_revenue' => $monthlyRevenue,
                'task_completion_rate' => $taskCompletionRate,
                'completed_tasks' => $completedTasks,
                'total_tasks' => $totalTasks,
            ],
            'charts' => [
                'monthly_revenue' => $this->monthlyRevenueChart($visibleClientIds),
                'compliance_completion' => $this->complianceCompletionChart($visibleClientIds),
                'client_growth' => $this->clientGrowthChart($visibleClientIds),
                'employee_workload' => $this->employeeWorkloadChart(),
            ],
            'upcomingDeadlines' => $upcomingDeadlines,
            'recentActivities' => $recentActivities,
        ];
    }

    private function monthlyRevenueChart($visibleClientIds): array
    {
        $labels = [];
        $values = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $labels[] = $monthStart->format('M Y');
            $values[] = round((float) Payment::whereIn('client_id', $visibleClientIds)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount'), 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function complianceCompletionChart($visibleClientIds): array
    {
        $gstTotal = GstReturn::whereIn('client_id', $visibleClientIds)->count();
        $gstDone = GstReturn::whereIn('client_id', $visibleClientIds)->where('status', 'filed')->count();

        $itrTotal = ItrFiling::whereIn('client_id', $visibleClientIds)->count();
        $itrDone = ItrFiling::whereIn('client_id', $visibleClientIds)->whereIn('filing_status', ['filed', 'verified'])->count();

        $tdsTotal = TdsReturn::whereIn('client_id', $visibleClientIds)->count();
        $tdsDone = TdsReturn::whereIn('client_id', $visibleClientIds)->where('status', 'filed')->count();

        $auditTotal = Audit::whereIn('client_id', $visibleClientIds)->count();
        $auditDone = Audit::whereIn('client_id', $visibleClientIds)->whereIn('status', ['approved', 'completed'])->count();

        return [
            'labels' => ['GST Returns', 'ITR Filings', 'TDS Returns', 'Audits'],
            'completed' => [$gstDone, $itrDone, $tdsDone, $auditDone],
            'pending' => [
                max(0, $gstTotal - $gstDone),
                max(0, $itrTotal - $itrDone),
                max(0, $tdsTotal - $tdsDone),
                max(0, $auditTotal - $auditDone),
            ],
        ];
    }

    private function clientGrowthChart($visibleClientIds): array
    {
        $labels = [];
        $values = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $labels[] = $monthEnd->format('M Y');
            $values[] = Client::whereIn('id', $visibleClientIds)
                ->where('created_at', '<=', $monthEnd)
                ->count();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function employeeWorkloadChart(): array
    {
        $staff = User::staff()->active()
            ->withCount([
                'assignedTasks as active_tasks_count' => fn ($q) => $q->where('status', '!=', 'completed'),
                'assignedTasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->orderByDesc('active_tasks_count')
            ->limit(7)
            ->get();

        return [
            'labels' => $staff->pluck('name')->values()->toArray(),
            'active' => $staff->pluck('active_tasks_count')->values()->toArray(),
            'completed' => $staff->pluck('completed_tasks_count')->values()->toArray(),
        ];
    }
}

