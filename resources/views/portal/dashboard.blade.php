@extends('layouts.portal')

@section('title', 'Client Portal Overview')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold mb-1" style="color: #1e3a5f;">Welcome, {{ $client->name }}</h1>
        <p class="text-muted small mb-0">{{ $client->client_code }} · Assigned CA: <strong>{{ $client->assignedCa?->name ?? 'Practice Desk' }}</strong></p>
    </div>
    <a href="{{ route('portal.documents') }}" class="btn btn-sm btn-primary"><i class="bi bi-cloud-upload me-1"></i>Upload Documents</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><x-kpi-card title="Pending Document Requests" :value="$pendingDocs->count()" icon="bi-folder-exclamation" tone="warning" :href="route('portal.documents')" /></div>
    <div class="col-6 col-md-3"><x-kpi-card title="GST Filings Tracked" :value="$gstReturns->count()" icon="bi-receipt-cutoff" tone="info" :href="route('portal.filings')" /></div>
    <div class="col-6 col-md-3"><x-kpi-card title="Upcoming Deadlines" :value="$deadlines->count()" icon="bi-calendar-event" tone="primary" :href="route('portal.filings')" /></div>
    <div class="col-6 col-md-3"><x-kpi-card title="Outstanding Balance" :value="'₹' . number_format($outstandingBalance, 2)" icon="bi-wallet2" tone="danger" :href="route('portal.invoices')" /></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <h2 class="h6 fw-bold mb-3">Pending Document Submissions</h2>
            @forelse($pendingDocs as $doc)
                <div class="border rounded p-2 mb-2 bg-light d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold small">{{ $doc->title }}</div>
                        <div class="text-muted" style="font-size: 0.74rem;">{{ $doc->categoryLabel() }} · Due: {{ $doc->due_date?->format('d M Y') ?? 'As soon as possible' }}</div>
                        @if($doc->rejection_reason)
                            <div class="text-danger small">Re-upload requested: {{ $doc->rejection_reason }}</div>
                        @endif
                    </div>
                    <a href="{{ route('portal.documents') }}" class="btn btn-sm btn-primary text-nowrap">Upload</a>
                </div>
            @empty
                <div class="text-muted small text-center py-4">No pending document requests.</div>
            @endforelse
        </div>
    </div>

    <div class="col-lg-6">
        <div class="ca-card p-3 h-100">
            <h2 class="h6 fw-bold mb-3">Recent Invoices</h2>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Invoice #</th><th>Date</th><th>Total</th><th>Status</th><th>PDF</th></tr></thead>
                    <tbody>
                        @forelse($recentInvoices as $inv)
                            <tr>
                                <td class="fw-semibold">{{ $inv->invoice_number }}</td>
                                <td class="small">{{ $inv->invoice_date->format('d M Y') }}</td>
                                <td class="small">₹{{ number_format((float) $inv->total_amount, 2) }}</td>
                                <td><x-status-badge :status="$inv->status" :label="$inv->statusLabel()" /></td>
                                <td><a href="{{ route('portal.invoices.pdf', $inv) }}" class="btn btn-sm btn-light border"><i class="bi bi-file-pdf text-danger"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center py-3">No invoices found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

