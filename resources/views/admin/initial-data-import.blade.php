@extends('layouts.app')

@section('title', 'Initial Data Import')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h4 fw-bold mb-1" style="color:#1e3a5f;">Initial Data Import</h1>
        <p class="text-muted small mb-0">One-time import of clients, ROC / LLP compliance records and tasks.</p>
    </div>
    <span class="badge bg-warning text-dark">Admin only</span>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">Please correct the following:</div>
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

@if(isset($preview))
    <div class="ca-card p-3 mb-3">
        <h2 class="h6 fw-bold mb-3">Import preview</h2>
        <p class="small text-muted mb-3">File: {{ $preview['filename'] ?? 'Uploaded workbook' }}</p>
        <div class="row g-2 mb-3">
            @foreach(['clients' => 'Clients', 'compliances' => 'Compliance records', 'tasks' => 'Tasks'] as $key => $label)
                <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">{{ $label }}</div><div class="h4 mb-0">{{ $preview['counts'][$key] ?? 0 }}</div></div></div>
            @endforeach
        </div>
        @if(!empty($preview['errors']))
            <div class="alert alert-danger mb-0">
                <div class="fw-semibold">Import blocked — {{ count($preview['errors']) }} issue(s) found.</div>
                <ul class="mb-0 mt-2">@foreach($preview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @else
            <div class="alert alert-success">Validation passed. Review the counts, then confirm the import.</div>
            <form method="POST" action="{{ route('admin.initial-import.confirm') }}" onsubmit="return confirm('Import these records into AuditFlow? This action cannot be automatically undone.');">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <button class="btn btn-primary"><i class="bi bi-database-check me-1"></i>Confirm and Import</button>
                <a href="{{ route('admin.initial-import.index') }}" class="btn btn-outline-secondary ms-1">Cancel</a>
            </form>
        @endif
    </div>
@endif

<div class="ca-card p-4 mb-3">
    <h2 class="h6 fw-bold">Upload completed workbook</h2>
    <p class="small text-muted">Use the Excel template shared for this setup. Keep the three worksheet names and column headings unchanged. Upload the completed .xlsx file below.</p>
    <form method="POST" action="{{ route('admin.initial-import.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">Excel workbook (.xlsx)</label>
            <input type="file" name="workbook" class="form-control" accept=".xlsx" required>
            <div class="form-text">Maximum file size: 10 MB. Up to 500 rows per sheet.</div>
        </div>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Validate and Preview</button>
    </form>
</div>

<div class="alert alert-info small">
    <strong>Important:</strong> This utility creates client records and initial compliance / task entries. It does not file forms with the MCA, create client portal logins, or decide statutory applicability. Confirm due dates and applicability before uploading.
</div>

@if(isset($logs) && $logs->isNotEmpty())
<div class="ca-card p-3 mt-3">
    <h2 class="h6 fw-bold mb-3">Recent import history</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Date</th><th>File</th><th>Clients</th><th>Compliance</th><th>Tasks</th></tr></thead>
            <tbody>
            @foreach($logs as $log)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y, h:i A') }}</td>
                    <td>{{ $log->filename }}</td>
                    <td>{{ $log->clients_imported }}</td>
                    <td>{{ $log->compliances_imported }}</td>
                    <td>{{ $log->tasks_imported }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
