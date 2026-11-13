<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ComplianceDeadline;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\InitialDataImport\XlsxWorkbookReader;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class InitialDataImportController extends Controller
{
    private const MAX_ROWS = 500;

    public function index(): View
    {
        $logs = DB::table('initial_data_import_logs')->latest()->limit(10)->get();
        return view('admin.initial-data-import', compact('logs'));
    }

    public function preview(Request $request, XlsxWorkbookReader $reader): View|RedirectResponse
    {
        $request->validate([
            'workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        try {
            $sheets = $reader->read($request->file('workbook')->getRealPath());
            $payload = $this->validateWorkbook($sheets);
        } catch (Throwable $exception) {
            return back()->withErrors(['workbook' => $exception->getMessage()]);
        }

        $payload['filename'] = $request->file('workbook')->getClientOriginalName();
        $payload['token'] = (string) Str::uuid();
        session(['initial_import_preview' => $payload]);

        return view('admin.initial-data-import', ['preview' => $payload]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $payload = session('initial_import_preview');
        abort_unless($payload && hash_equals((string) ($payload['token'] ?? ''), (string) $request->input('token')), 419);
        abort_if(! empty($payload['errors']), 422, 'Correct the workbook errors and upload it again before importing.');

        $user = $request->user();
        $counts = ['clients' => 0, 'compliances' => 0, 'tasks' => 0];

        DB::transaction(function () use ($payload, $user, &$counts) {
            $clientMap = [];

            foreach ($payload['rows']['clients'] as $row) {
                $entityType = strtolower(trim($row['Entity Type']));
                $isLlp = str_contains($entityType, 'llp') || str_contains($entityType, 'limited liability partnership');
                $identifier = strtoupper(trim($isLlp ? $row['LLPIN'] : $row['CIN']));

                $client = Client::create([
                    'client_code' => $this->nextClientCode(),
                    'category' => 'business',
                    'name' => trim($row['Client Name']),
                    'email' => $this->nullable($row['Contact Email']),
                    'mobile' => $this->nullable($row['Contact Phone']),
                    'address' => $this->nullable($row['Registered Address']),
                    'pan' => $this->nullableUpper($row['PAN']),
                    'gstin' => $this->nullableUpper($row['GSTIN']),
                    'tan' => $this->nullableUpper($row['TAN']),
                    'cin' => $isLlp ? null : $this->nullableUpper($row['CIN']),
                    'llpin' => $isLlp ? $this->nullableUpper($row['LLPIN']) : null,
                    'business_type' => $this->nullable($row['Business Type']) ?? $this->nullable($row['Entity Type']),
                    'registration_date' => $this->dateValue($row['Registration Date']),
                    'status' => 'active',
                ]);

                if ($identifier !== '') {
                    $clientMap[$identifier] = $client->id;
                }
                $counts['clients']++;
            }

            foreach ($payload['rows']['compliances'] as $row) {
                $clientId = $this->resolveClientId($row['Client CIN/LLPIN'], $clientMap);
                $type = strtolower(trim($row['Compliance Type'])) === 'llp' ? 'llp' : 'roc';
                $status = $this->complianceStatus($row['Status']);

                ComplianceDeadline::create([
                    'title' => trim($row['Compliance/Form Name']).($this->nullable($row['Financial Year']) ? ' - FY '.$row['Financial Year'] : ''),
                    'compliance_type' => $type,
                    'client_id' => $clientId,
                    'assigned_to' => $this->resolveAssignee($row['Assigned To (Username/Email)']),
                    'due_date' => $this->dateValue($row['Due Date']),
                    'reminder_date' => $this->dateValue($row['Reminder Date']),
                    'status' => $status,
                    'completed_at' => $status === 'completed' ? now() : null,
                    'priority' => $this->priorityValue($row['Priority']),
                    'description' => $this->nullable($row['Description / Notes']),
                    'srn' => $this->nullable($row['SRN']),
                    'filing_date' => $this->dateValue($row['Filing Date']),
                    'acknowledgement_reference' => $this->nullable($row['Acknowledgement Reference']),
                    'financial_year' => $this->nullable($row['Financial Year']),
                ]);
                $counts['compliances']++;
            }

            foreach ($payload['rows']['tasks'] as $row) {
                $identifier = strtoupper(trim($row['Client CIN/LLPIN']));
                $clientId = $identifier !== '' ? $this->resolveClientId($identifier, $clientMap) : null;
                $assignee = $this->resolveAssignee($row['Assigned To (Username/Email)']);

                $task = Task::create([
                    'title' => trim($row['Task Title']),
                    'description' => $this->nullable($row['Task Description']),
                    'client_id' => $clientId,
                    'assigned_to' => $assignee,
                    'created_by' => $user->id,
                    'category' => $this->taskCategory($row['Category']),
                    'priority' => $this->priorityValue($row['Priority']),
                    'status' => $this->taskStatus($row['Status']),
                    'due_date' => $this->dateValue($row['Due Date']),
                    'completed_at' => strtolower(trim($row['Status'])) === 'completed' ? now() : null,
                ]);

                TaskActivity::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'action' => 'created',
                    'details' => 'Task imported through initial data import.',
                ]);
                $counts['tasks']++;
            }

            DB::table('initial_data_import_logs')->insert([
                'user_id' => $user->id,
                'filename' => $payload['filename'],
                'clients_imported' => $counts['clients'],
                'compliances_imported' => $counts['compliances'],
                'tasks_imported' => $counts['tasks'],
                'details' => json_encode(['counts' => $counts], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        session()->forget('initial_import_preview');

        return redirect()->route('admin.initial-import.index')
            ->with('success', 'Import completed: '.$counts['clients'].' clients, '.$counts['compliances'].' compliance records and '.$counts['tasks'].' tasks.');
    }

    private function validateWorkbook(array $sheets): array
    {
        $definitions = [
            'Client Master' => ['key' => 'clients', 'required' => ['Client Name', 'Entity Type'], 'headers' => ['Client Name', 'Entity Type', 'CIN', 'LLPIN', 'PAN', 'GSTIN', 'TAN', 'Registration Date', 'Business Type', 'Registered Address', 'Contact Person', 'Contact Email', 'Contact Phone']],
            'Compliance Register' => ['key' => 'compliances', 'required' => ['Client CIN/LLPIN', 'Compliance/Form Name', 'Compliance Type', 'Due Date', 'Applicable (Yes/No)'], 'headers' => ['Client CIN/LLPIN', 'Client Name (for reference)', 'Compliance/Form Name', 'Compliance Type', 'Financial Year', 'Due Date', 'Reminder Date', 'Assigned To (Username/Email)', 'Priority', 'Status', 'Applicable (Yes/No)', 'Description / Notes', 'SRN', 'Filing Date', 'Acknowledgement Reference']],
            'Task Register' => ['key' => 'tasks', 'required' => ['Task Title', 'Category', 'Due Date', 'Priority'], 'headers' => ['Client CIN/LLPIN', 'Client Name (for reference)', 'Task Title', 'Task Description', 'Category', 'Assigned To (Username/Email)', 'Due Date', 'Priority', 'Status']],
        ];

        $result = ['rows' => ['clients' => [], 'compliances' => [], 'tasks' => []], 'errors' => [], 'counts' => []];
        $knownIdentifiers = [];

        foreach ($definitions as $sheetName => $definition) {
            if (! isset($sheets[$sheetName])) {
                $result['errors'][] = "Missing worksheet: {$sheetName}.";
                continue;
            }

            $rawRows = $sheets[$sheetName];
            $headerRow = null;
            foreach ($rawRows as $row) {
                if (($row[1] ?? '') === $definition['headers'][0]) {
                    $headerRow = $row;
                    break;
                }
            }
            if (! $headerRow) {
                $result['errors'][] = "Could not find the expected header row in {$sheetName}. Do not change the template headings.";
                continue;
            }

            $columns = [];
            foreach ($definition['headers'] as $index => $header) {
                $columns[$header] = $index + 1;
            }

            $mappedRows = [];
            $headerPosition = array_search($headerRow, $rawRows, true);
            foreach (array_slice($rawRows, $headerPosition + 1) as $offset => $rawRow) {
                $mapped = [];
                foreach ($definition['headers'] as $header) {
                    $mapped[$header] = trim((string) ($rawRow[$columns[$header]] ?? ''));
                }
                if (count(array_filter($mapped, fn ($value) => $value !== '')) === 0) {
                    continue;
                }

                $line = $offset + 5;
                foreach ($definition['required'] as $required) {
                    if ($mapped[$required] === '') {
                        $result['errors'][] = "{$sheetName}, row {$line}: {$required} is required.";
                    }
                }
                if (count($mappedRows) >= self::MAX_ROWS) {
                    $result['errors'][] = "{$sheetName}: maximum ".self::MAX_ROWS." data rows are allowed.";
                    break;
                }

                if ($definition['key'] === 'clients') {
                    $entity = strtolower($mapped['Entity Type']);
                    $isLlp = str_contains($entity, 'llp') || str_contains($entity, 'limited liability partnership');
                    $idField = $isLlp ? 'LLPIN' : 'CIN';
                    $identifier = strtoupper($mapped[$idField]);
                    if ($identifier === '') {
                        $result['errors'][] = "{$sheetName}, row {$line}: {$idField} is required for this entity type.";
                    } elseif (isset($knownIdentifiers[$identifier])) {
                        $result['errors'][] = "{$sheetName}, row {$line}: duplicate CIN/LLPIN {$identifier} in this workbook.";
                    } else {
                        $knownIdentifiers[$identifier] = true;
                    }
                    if ($identifier !== '' && Client::withTrashed()->where($isLlp ? 'llpin' : 'cin', $identifier)->exists()) {
                        $result['errors'][] = "{$sheetName}, row {$line}: CIN/LLPIN {$identifier} already exists in AuditFlow.";
                    }
                }
                if ($definition['key'] === 'clients') {
                    if ($mapped['Contact Email'] !== '' && ! filter_var($mapped['Contact Email'], FILTER_VALIDATE_EMAIL)) {
                        $result['errors'][] = "{$sheetName}, row {$line}: Contact Email is invalid.";
                    }
                    if ($mapped['Registration Date'] !== '' && ! $this->validDate($mapped['Registration Date'])) {
                        $result['errors'][] = "{$sheetName}, row {$line}: Registration Date is invalid.";
                    }
                }
                if ($definition['key'] === 'compliances') {
                    foreach (['Due Date', 'Reminder Date', 'Filing Date'] as $dateField) {
                        if ($mapped[$dateField] !== '' && ! $this->validDate($mapped[$dateField])) {
                            $result['errors'][] = "{$sheetName}, row {$line}: {$dateField} is invalid.";
                        }
                    }
                    $applicable = strtoupper($mapped['Applicable (Yes/No)']);
                    if (! in_array($applicable, ['YES', 'NO'], true)) {
                        $result['errors'][] = "{$sheetName}, row {$line}: Applicable (Yes/No) must be Yes or No.";
                    }
                    if ($applicable !== 'YES') {
                        continue;
                    }
                }
                $mappedRows[] = $mapped;
            }

            $result['rows'][$definition['key']] = $mappedRows;
            $result['counts'][$definition['key']] = count($mappedRows);
        }

        $complianceKeys = [];
        foreach ($result['rows']['compliances'] as $index => $row) {
            $identifier = strtoupper(trim($row['Client CIN/LLPIN']));
            $complianceKey = $identifier.'|'.strtolower(trim($row['Compliance/Form Name'])).'|'.trim($row['Financial Year']).'|'.trim($row['Due Date']);
            if (isset($complianceKeys[$complianceKey])) {
                $result['errors'][] = 'Compliance Register, record '.($index + 1).': duplicate compliance record in this workbook.';
            }
            $complianceKeys[$complianceKey] = true;
            $existingClientId = Client::where(fn ($q) => $q->where('cin', $identifier)->orWhere('llpin', $identifier))->value('id');
            $complianceTitle = trim($row['Compliance/Form Name']).($this->nullable($row['Financial Year']) ? ' - FY '.$row['Financial Year'] : '');
            if ($existingClientId && $this->validDate($row['Due Date']) && ComplianceDeadline::where('client_id', $existingClientId)->where('title', $complianceTitle)->whereDate('due_date', $this->dateValue($row['Due Date']))->exists()) {
                $result['errors'][] = 'Compliance Register, record '.($index + 1).': this compliance record already exists in AuditFlow.';
            }
            if ($identifier !== '' && ! isset($knownIdentifiers[$identifier]) && ! Client::where(fn ($q) => $q->where('cin', $identifier)->orWhere('llpin', $identifier))->exists()) {
                $result['errors'][] = 'Compliance Register, record '.($index + 1).": client identifier {$identifier} was not found in Client Master or AuditFlow.";
            }
            if (! $this->validDate($row['Due Date'])) {
                $result['errors'][] = 'Compliance Register, record '.($index + 1).': Due Date is invalid.';
            }
            if (! in_array(strtolower($row['Compliance Type']), ['roc', 'llp'], true)) {
                $result['errors'][] = 'Compliance Register, record '.($index + 1).': Compliance Type must be ROC or LLP.';
            }
        }

        $taskKeys = [];
        foreach ($result['rows']['tasks'] as $index => $row) {
            $identifier = strtoupper(trim($row['Client CIN/LLPIN']));
            $taskKey = $identifier.'|'.strtolower(trim($row['Task Title'])).'|'.trim($row['Due Date']);
            if (isset($taskKeys[$taskKey])) {
                $result['errors'][] = 'Task Register, record '.($index + 1).': duplicate task in this workbook.';
            }
            $taskKeys[$taskKey] = true;
            $existingClientId = $identifier !== '' ? Client::where(fn ($q) => $q->where('cin', $identifier)->orWhere('llpin', $identifier))->value('id') : null;
            if ($existingClientId && $this->validDate($row['Due Date']) && Task::where('client_id', $existingClientId)->where('title', trim($row['Task Title']))->whereDate('due_date', $this->dateValue($row['Due Date']))->exists()) {
                $result['errors'][] = 'Task Register, record '.($index + 1).': this task already exists in AuditFlow.';
            }
            if ($identifier !== '' && ! isset($knownIdentifiers[$identifier]) && ! Client::where(fn ($q) => $q->where('cin', $identifier)->orWhere('llpin', $identifier))->exists()) {
                $result['errors'][] = 'Task Register, record '.($index + 1).": client identifier {$identifier} was not found in Client Master or AuditFlow.";
            }
            if (! $this->validDate($row['Due Date'])) {
                $result['errors'][] = 'Task Register, record '.($index + 1).': Due Date is invalid.';
            }
        }

        foreach (array_merge($result['rows']['compliances'], $result['rows']['tasks']) as $row) {
            $assignee = trim($row['Assigned To (Username/Email)'] ?? '');
            if ($assignee !== '' && ! $this->findAssignee($assignee)) {
                $result['errors'][] = "Unknown staff member '{$assignee}'. Use an existing staff email or employee code.";
            }
        }

        return $result;
    }

    private function validDate(string $value): bool
    {
        if ($value === '') return false;
        try { $this->dateValue($value); return true; } catch (Throwable) { return false; }
    }

    private function dateValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }
        return Carbon::parse($value)->toDateString();
    }

    private function findAssignee(?string $value): ?User
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        return User::staff()->active()->where(fn ($q) => $q->where('email', $value)->orWhere('employee_code', $value))->first();
    }

    private function resolveAssignee(?string $value): ?int
    {
        return $this->findAssignee($value)?->id;
    }

    private function resolveClientId(string $identifier, array $newClients): ?int
    {
        $identifier = strtoupper(trim($identifier));
        if ($identifier === '') return null;
        if (isset($newClients[$identifier])) return $newClients[$identifier];
        return Client::where(fn ($q) => $q->where('cin', $identifier)->orWhere('llpin', $identifier))->value('id');
    }

    private function nextClientCode(): string
    {
        $number = Client::withTrashed()->count() + 1;
        do {
            $code = sprintf('CA-CL-%04d', $number++);
        } while (Client::withTrashed()->where('client_code', $code)->exists());
        return $code;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableUpper(?string $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : strtoupper($value);
    }

    private function priorityValue(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'low' => 'low',
            'high' => 'high',
            'urgent' => 'urgent',
            default => 'medium',
        };
    }

    private function complianceStatus(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'completed' => 'completed',
            'in progress' => 'in_progress',
            'overdue' => 'overdue',
            default => 'upcoming',
        };
    }

    private function taskStatus(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'completed' => 'completed',
            'in progress' => 'in_progress',
            'under review' => 'under_review',
            default => 'todo',
        };
    }

    private function taskCategory(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'roc / mca' => 'roc',
            'llp compliance' => 'llp',
            'gst' => 'gst',
            'tax' => 'advisory',
            'audit' => 'audit',
            'general', 'other' => 'general',
            default => 'general',
        };
    }
}
