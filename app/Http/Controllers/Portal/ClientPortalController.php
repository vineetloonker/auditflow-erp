<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\FirmSetting;
use App\Services\ClientAccessService;
use App\Services\CommunicationService;
use App\Services\DocumentStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortalController extends Controller
{
    public function __construct(
        private readonly ClientAccessService $clientAccess,
        private readonly DocumentStorageService $storageService,
        private readonly CommunicationService $communicationService
    ) {}

    public function dashboard(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());

        $pendingDocs = $client->documents()->whereIn('status', ['requested', 'rejected'])->orderBy('due_date')->get();
        $gstReturns = $client->gstReturns()->orderByDesc('due_date')->limit(5)->get();
        $itrFilings = $client->itrFilings()->orderByDesc('assessment_year')->limit(5)->get();
        $deadlines = $client->deadlines()->where('status', '!=', 'completed')->orderBy('due_date')->limit(6)->get();
        $outstandingBalance = $client->outstandingBalance();
        $recentInvoices = $client->invoices()->latest('invoice_date')->limit(5)->get();

        return view('portal.dashboard', compact(
            'client',
            'pendingDocs',
            'gstReturns',
            'itrFilings',
            'deadlines',
            'outstandingBalance',
            'recentInvoices'
        ));
    }

    public function profile(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $client->load('assignedCa');

        return view('portal.profile', compact('client'));
    }

    public function documents(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $documents = $client->documents()
            ->where('is_shared_with_client', true)
            ->with(['versions', 'requester'])
            ->latest()
            ->paginate(15);

        return view('portal.documents', compact('client', 'documents'));
    }

    public function uploadDocument(Request $request): RedirectResponse
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());

        if ($request->filled('document_id')) {
            $request->validate([
                'document_id' => ['required', 'integer'],
                'file' => ['required', 'file', 'max:20480'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $doc = $client->documents()->where('id', $request->input('document_id'))->firstOrFail();
            $this->storageService->storeUpload($doc, $request->file('file'), $request->user()->id, $request->input('notes'));

            return back()->with('success', "Uploaded '{$doc->title}' successfully.");
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(Document::CATEGORIES))],
            'file' => ['required', 'file', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $doc = Document::create([
            'client_id' => $client->id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => 'uploaded',
            'is_shared_with_client' => true,
        ]);

        $this->storageService->storeUpload($doc, $request->file('file'), $request->user()->id, $validated['notes'] ?? null);

        return back()->with('success', 'Document uploaded to your CA vault.');
    }

    public function downloadDocument(Request $request, Document $document): StreamedResponse
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        abort_unless((int) $document->client_id === (int) $client->id && $document->is_shared_with_client, 403);

        return $this->storageService->download($document);
    }

    public function filings(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $gstReturns = $client->gstReturns()->orderByDesc('due_date')->get();
        $itrFilings = $client->itrFilings()->orderByDesc('assessment_year')->get();
        $tdsReturns = $client->tdsReturns()->orderByDesc('due_date')->get();
        $deadlines = $client->deadlines()->orderBy('due_date')->get();

        return view('portal.filings', compact('client', 'gstReturns', 'itrFilings', 'tdsReturns', 'deadlines'));
    }

    public function invoices(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $invoices = $client->invoices()->with('payments')->orderByDesc('invoice_date')->paginate(15);
        $outstandingBalance = $client->outstandingBalance();

        return view('portal.invoices', compact('client', 'invoices', 'outstandingBalance'));
    }

    public function downloadInvoicePdf(Request $request, Invoice $invoice): Response
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        abort_unless((int) $invoice->client_id === (int) $client->id, 403);

        $invoice->load(['client', 'items', 'payments']);
        $firm = FirmSetting::allAsMap();

        return Pdf::loadView('billing.pdf', compact('invoice', 'firm'))
            ->setPaper('a4')
            ->download($invoice->invoice_number.'.pdf');
    }

    public function messages(Request $request): View
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $messages = $client->communications()->with('user')->latest()->paginate(20);

        return view('portal.messages', compact('client', 'messages'));
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $client = $this->clientAccess->resolveClientForPortalUser($request->user());
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $this->communicationService->send($client, [
            'channel' => 'portal',
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ], $request->user()->id, 'client');

        return back()->with('success', 'Message sent to your CA firm.');
    }
}

