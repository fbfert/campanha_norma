<?php

namespace App\Http\Controllers\Admin\Histories;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Queries\Histories\MessageHistoryQuery;
use App\Services\AuditLogger;
use App\Services\Reports\ErrorClassificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageHistoryController extends Controller
{
    public function index(Request $request, MessageHistoryQuery $query, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('histories.view'), 403);
        $audit->log('history.viewed', 'Histórico consolidado de mensagens visualizado.', null, null, $request->only(['q', 'status', 'message_batch_id']));

        return view('admin.histories.messages.index', [
            'recipients' => $query->build($request)->paginate(25)->withQueryString(),
            'batches' => MessageBatch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, MessageBatchRecipient $recipient, ErrorClassificationService $errors, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('histories.view'), 403);
        $recipient->load(['batch.template', 'batch.creator', 'contact', 'attempts', 'processingEvents']);
        $audit->log('history.viewed', 'Detalhe de envio visualizado.', $recipient, null, ['recipient_id' => $recipient->id]);

        return view('admin.histories.messages.show', [
            'recipient' => $recipient,
            'classification' => $errors->classify($recipient->error_code),
        ]);
    }

    public function contact(Request $request, Contact $contact, MessageHistoryQuery $query): View
    {
        abort_unless($request->user()->can('histories.view'), 403);

        return view('admin.histories.contacts.show', [
            'contact' => $contact,
            'recipients' => $query->build(['contact_id' => $contact->id])->paginate(20),
            'summary' => [
                'total' => MessageBatchRecipient::query()->where('contact_id', $contact->id)->count(),
                'sent' => MessageBatchRecipient::query()->where('contact_id', $contact->id)->where('processing_status', 'sent')->count(),
                'failed' => MessageBatchRecipient::query()->where('contact_id', $contact->id)->whereIn('processing_status', ['failed_temporary', 'failed_permanent'])->count(),
                'cancelled' => MessageBatchRecipient::query()->where('contact_id', $contact->id)->where('processing_status', 'cancelled')->count(),
            ],
        ]);
    }
}
