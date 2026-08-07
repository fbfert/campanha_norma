<?php

namespace App\Http\Controllers\Admin\MessageProcessing;

use App\Http\Controllers\Controller;
use App\Services\MessageProcessing\ReciprocityGuard;
use App\Http\Requests\MessageProcessing\SendingSettingRequest;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\SendingSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageSettingsController extends Controller
{
    public function edit(Request $request, SendingSettingsService $service): View
    {
        abort_unless($request->user()->can('message_processing.manage_settings'), 403);

        return view('admin.message-processing.settings', [
            'settings' => $service->current(),
            // Número real ao lado do campo: escolher um teto sem saber onde a
            // contagem está hoje é chutar. Com ele na tela, dá para ver se o
            // valor digitado tranca o envio no próximo clique.
            'emSilencio' => app(ReciprocityGuard::class)->silentContacts(),
        ]);
    }

    public function update(SendingSettingRequest $request, SendingSettingsService $service, AuditLogger $audit): RedirectResponse
    {
        $settings = $service->current();
        $old = $settings->only(array_keys($request->validated()));
        $data = $request->validated();
        $data['pause_when_disconnected'] = $request->boolean('pause_when_disconnected');
        $data['updated_by'] = $request->user()->id;
        $settings->update($data);

        $audit->log('message_settings.updated', 'Configurações de envio atualizadas.', $settings, $old, $settings->fresh()->only(array_keys($data)), $request->user());

        return redirect()->route('admin.message-settings.edit')->with('success', 'Configurações de envio atualizadas.');
    }
}
