@extends('layouts.app')

@section('title', 'Practice Dashboard')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold mb-1" style="color: #1e3a5f;">Practice Overview</h1>
        <p class="text-muted small mb-0">Welcome back, {{ auth()->user()->name }}. Live compliance, client, task &amp; billing telemetry.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('clients.create') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Add Client</a>
        <a href="{{ route('billing.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i>New Invoice</a>
    </div>
</div>

<!-- 12 KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Active Clients" :value="$kpis['active_clients']" icon="bi-buildings" tone="primary" :href="route('clients.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="GST Clients" :value="$kpis['gst_clients']" icon="bi-receipt-cutoff" tone="info" :href="route('gst.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="ITR Clients" :value="$kpis['itr_clients']" icon="bi-file-earmark-check" tone="success" :href="route('itr.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Pending GST" :value="$kpis['pending_gst']" icon="bi-hourglass-split" tone="warning" :href="route('gst.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Pending ITR" :value="$kpis['pending_itr']" icon="bi-file-earmark-text" tone="warning" :href="route('itr.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Pending TDS" :value="$kpis['pending_tds']" icon="bi-percent" tone="warning" :href="route('tds.index')" />
    </div>

    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Upcoming Deadlines" :value="$kpis['upcoming_deadlines']" icon="bi-calendar-event" tone="info" subtitle="Next 30 days" :href="route('calendar.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Overdue Tasks" :value="$kpis['overdue_tasks']" icon="bi-exclamation-octagon" tone="danger" :href="route('tasks.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Awaiting Docs" :value="$kpis['awaiting_documents']" icon="bi-folder2-open" tone="warning" subtitle="From clients" :href="route('documents.index', ['status' => 'requested'])" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Outstanding Fees" :value="'₹' . number_format($kpis['outstanding_fees'], 0)" icon="bi-wallet2" tone="danger" :href="route('billing.index')" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Monthly Revenue" :value="'₹' . number_format($kpis['monthly_revenue'], 0)" icon="bi-cash-stack" tone="success" subtitle="{{ now()->format('M Y') }}" :href="route('reports.index', ['type' => 'monthly_revenue'])" />
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <x-kpi-card title="Task Completion" :value="$kpis['task_completion_rate'] . '%'" icon="bi-check2-circle" tone="primary" subtitle="{{ $kpis['completed_tasks'] }}/{{ $kpis['total_tasks'] }} done" :href="route('staff.index')" />
    </div>
</div>

<!-- 4 Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0">Monthly Revenue Trend (INR)</h2>
                <span class="badge badge-soft-success">Last 6 Months</span>
            </div>
            <div style="height: 250px;"><canvas id="revenueChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0">Compliance Completion Status</h2>
                <span class="badge badge-soft-info">GST · ITR · TDS · Audit</span>
            </div>
            <div style="height: 250px;"><canvas id="complianceChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0">Client Portfolio Growth</h2>
                <span class="badge badge-soft-primary">Cumulative Clients</span>
            </div>
            <div style="height: 240px;"><canvas id="clientGrowthChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0">Workload by Employee</h2>
                <span class="badge badge-soft-warning">Active vs Completed</span>
            </div>
            <div style="height: 240px;"><canvas id="workloadChart"></canvas></div>
        </div>
    </div>
</div>

<!-- Upcoming Deadlines & Recent Activity -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-calendar3 me-2 text-primary"></i>Upcoming Compliance Deadlines</h2>
                <a href="{{ route('calendar.index') }}" class="btn btn-sm btn-outline-primary">Open Calendar</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Deadline</th>
                            <th>Type</th>
                            <th>Client</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($upcomingDeadlines as $deadline)
                            <tr>
                                <td class="fw-medium">{{ $deadline->title }}</td>
                                <td><span class="badge badge-soft-info">{{ $deadline->typeLabel() }}</span></td>
                                <td class="small">{{ $deadline->client?->name ?? 'Firm-wide' }}</td>
                                <td class="small {{ $deadline->due_date->isPast() ? 'text-danger fw-semibold' : '' }}">{{ $deadline->due_date->format('d M Y') }}</td>
                                <td><x-status-badge :status="$deadline->status" :label="$deadline->statusLabel()" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No upcoming compliance deadlines.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ca-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-activity me-2 text-success"></i>Recent Practice Activity</h2>
                @if(auth()->user()->isAdminOrPartner())
                    <a href="{{ route('settings.index') }}" class="small text-decoration-none">Audit Logs</a>
                @endif
            </div>
            <div class="list-group list-group-flush">
                @forelse($recentActivities as $log)
                    <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="small fw-medium">{{ $log->description }}</div>
                                <div class="text-muted" style="font-size: 0.74rem;">
                                    {{ $log->user?->name ?? 'System' }} · {{ ucfirst($log->module) }}
                                </div>
                            </div>
                            <span class="text-muted text-nowrap" style="font-size: 0.72rem;">{{ $log->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4 small">No activity logged yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const charts = @json($charts);

    new window.Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: charts.monthly_revenue.labels,
            datasets: [{
                label: 'Collections (INR)',
                data: charts.monthly_revenue.values,
                backgroundColor: 'rgba(13, 148, 136, 0.78)',
                borderColor: '#0d9488',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    new window.Chart(document.getElementById('complianceChart'), {
        type: 'bar',
        data: {
            labels: charts.compliance_completion.labels,
            datasets: [
                { label: 'Completed / Filed', data: charts.compliance_completion.completed, backgroundColor: '#0d9488', borderRadius: 5 },
                { label: 'Pending / In Progress', data: charts.compliance_completion.pending, backgroundColor: '#93c5fd', borderRadius: 5 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
    });

    new window.Chart(document.getElementById('clientGrowthChart'), {
        type: 'line',
        data: {
            labels: charts.client_growth.labels,
            datasets: [{
                label: 'Total Clients',
                data: charts.client_growth.values,
                borderColor: '#1e3a5f',
                backgroundColor: 'rgba(30, 58, 95, 0.1)',
                fill: true,
                tension: 0.3
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new window.Chart(document.getElementById('workloadChart'), {
        type: 'bar',
        data: {
            labels: charts.employee_workload.labels,
            datasets: [
                { label: 'Active Tasks', data: charts.employee_workload.active, backgroundColor: '#0284c7', borderRadius: 5 },
                { label: 'Completed Tasks', data: charts.employee_workload.completed, backgroundColor: '#cbd5e1', borderRadius: 5 }
            ]
        },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true } } }
    });
});
</script>
@endpush

