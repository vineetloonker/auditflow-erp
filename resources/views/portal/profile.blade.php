@extends('layouts.portal')

@section('title', 'My Profile')

@section('content')
<h1 class="h4 fw-bold mb-3" style="color: #1e3a5f;">My Entity &amp; Tax Profile</h1>
<div class="ca-card p-4">
    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted">Client Code</dt><dd class="col-sm-9 fw-semibold">{{ $client->client_code }}</dd>
        <dt class="col-sm-3 text-muted">Name</dt><dd class="col-sm-9">{{ $client->name }}</dd>
        <dt class="col-sm-3 text-muted">Category</dt><dd class="col-sm-9">{{ ucfirst($client->category) }}</dd>
        <dt class="col-sm-3 text-muted">Email / Mobile</dt><dd class="col-sm-9">{{ $client->email }} · {{ $client->mobile ?? '—' }}</dd>
        <dt class="col-sm-3 text-muted">PAN</dt><dd class="col-sm-9">{{ $client->pan ?? '—' }}</dd>
        <dt class="col-sm-3 text-muted">GSTIN</dt><dd class="col-sm-9">{{ $client->gstin ?? '—' }}</dd>
        <dt class="col-sm-3 text-muted">TAN / CIN</dt><dd class="col-sm-9">{{ $client->tan ?? '—' }} / {{ $client->cin ?? '—' }}</dd>
        <dt class="col-sm-3 text-muted">Address</dt><dd class="col-sm-9">{{ trim(implode(', ', array_filter([$client->address, $client->city, $client->state, $client->pincode]))) ?: '—' }}</dd>
        <dt class="col-sm-3 text-muted">Assigned CA</dt><dd class="col-sm-9">{{ $client->assignedCa?->name ?? 'Practice Desk' }}</dd>
    </dl>
</div>
@endsection

