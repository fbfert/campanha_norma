<?php

namespace App\Http\Controllers\Admin\WhatsApp;

use App\Enums\ContactStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\ClearWhatsAppSessionRequest;
use App\Http\Requests\WhatsApp\WhatsAppTestMessageRequest;
use App\Models\Contact;
use App\Services\AuditLogger;
use App\Services\WhatsApp\WhatsAppConnectionService;
use App\Services\WhatsApp\WhatsAppTestMessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppConnectionController extends Controller
{
    public function show(Request $request, WhatsAppConnectionService $service, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('whatsapp.connection.view'), 403);

        $connection = $service->connection($request->user());
        $statusError = null;
        $qr = null;

        try {
            $service->refreshStatus($request->user());
            $connection->refresh();
        } catch (WhatsAppServiceException $exception) {
            $statusError = $exception->userMessage();
            if ($request->session()->get('error') === $statusError) {
                $statusError = null;
            }
        }

        $audit->log('whatsapp.connection_viewed', 'Tela de conexao WhatsApp visualizada.', $connection, null, null, $request->user(), $request);

        return view('admin.whatsapp.connection', [
            'connection' => $connection->load('events.user'),
            'statusError' => $statusError,
            'qr' => $qr,
            'contacts' => Contact::query()
                ->where('status', ContactStatus::Active)
                ->where('do_not_contact', false)
                ->whereNotNull('phone_normalized')
                ->orderBy('name')
                ->limit(100)
                ->get(),
        ]);
    }

    public function connect(Request $request, WhatsAppConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->can('whatsapp.connection.manage'), 403);

        try {
            $service->connect($request->user(), $request);

            return back()->with('success', 'Inicializacao da conexao solicitada.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function qrCode(Request $request, WhatsAppConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->can('whatsapp.connection.manage'), 403);

        try {
            $result = $service->requestQr($request->user(), $request);

            return back()->with('whatsapp_qr', [
                'qr_code' => $result->qrCode,
                'generated_at' => $result->generatedAt?->format('d/m/Y H:i:s'),
                'expires_at' => $result->expiresAt?->format('d/m/Y H:i:s'),
                'status' => $result->status->label(),
            ])->with('success', 'QR Code consultado.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function refreshStatus(Request $request, WhatsAppConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->can('whatsapp.connection.view'), 403);

        try {
            $service->refreshStatus($request->user());

            return back()->with('success', 'Status atualizado.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function reconnect(Request $request, WhatsAppConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->can('whatsapp.connection.manage'), 403);

        try {
            $service->reconnect($request->user(), $request);

            return back()->with('success', 'Reconexao solicitada.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function disconnect(Request $request, WhatsAppConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->can('whatsapp.connection.disconnect'), 403);

        try {
            $service->disconnect($request->user(), $request);

            return back()->with('success', 'Desconexao solicitada.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function clearSession(ClearWhatsAppSessionRequest $request, WhatsAppConnectionService $service): RedirectResponse
    {
        try {
            $service->clearSession($request->user(), $request);

            return back()->with('success', 'Exclusao da sessao solicitada. Um novo QR Code sera necessario.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }

    public function sendTestMessage(WhatsAppTestMessageRequest $request, WhatsAppTestMessageService $service): RedirectResponse
    {
        $contact = Contact::query()->findOrFail((int) $request->validated('contact_id'));

        try {
            $service->send($contact, $request->user(), (string) $request->validated('message'), $request);

            return back()->with('success', 'Mensagem individual de teste enviada.');
        } catch (WhatsAppServiceException $exception) {
            return back()->with('error', $exception->userMessage());
        }
    }
}
