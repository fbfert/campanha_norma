<?php

namespace App\Http\Controllers\Admin\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MetaSettingsUpdateRequest;
use App\Services\AuditLogger;
use App\Services\WhatsApp\MetaSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de configuração da API oficial da Meta.
 *
 * Reúne numa página o que antes exigia editar o arquivo de ambiente e reiniciar
 * o serviço. Os dados que faltam aqui só existem depois que alguém termina o
 * cadastro no painel da Meta, e essa pessoa não é necessariamente quem tem
 * acesso ao servidor.
 *
 * O token e o segredo do app entram cifrados e nunca mais saem. Nenhuma ação
 * daqui devolve credencial para a tela, para o log ou para a auditoria.
 */
class MetaSettingsController extends Controller
{
    public function edit(Request $request, MetaSettings $settings): View
    {
        abort_unless($request->user()->can('whatsapp.meta.manage'), 403);

        return view('admin.whatsapp.meta-settings', [
            'form' => $settings->forForm(),
            // O `.env` continua valendo onde o banco está vazio. Mostrar o que
            // vale de fato evita a leitura errada de que nada está configurado.
            'effective' => $settings->effective(),
            'missing' => $settings->missing(),
            'webhookUrl' => route('internal.whatsapp.meta.verify'),
        ]);
    }

    public function update(MetaSettingsUpdateRequest $request, MetaSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        if ($data['forget_token'] ?? false) {
            $settings->forgetSecret('whatsapp.meta_token');
        }

        if ($data['forget_app_secret'] ?? false) {
            $settings->forgetSecret('whatsapp.meta_app_secret');
        }

        $old = $settings->save([
            'whatsapp.meta_base_url' => $data['base_url'] ?? '',
            'whatsapp.meta_api_version' => $data['api_version'] ?? '',
            'whatsapp.meta_phone_number_id' => $data['phone_number_id'] ?? '',
            'whatsapp.meta_business_account_id' => $data['business_account_id'] ?? '',
            'whatsapp.meta_token' => $data['token'] ?? '',
            'whatsapp.meta_app_secret' => $data['app_secret'] ?? '',
            'whatsapp.meta_verify_token' => $data['verify_token'] ?? '',
            'whatsapp.meta_invite_template' => $data['invite_template'] ?? '',
            'whatsapp.meta_invite_language' => $data['invite_language'] ?? '',
            'whatsapp.meta_timeout' => $data['timeout'] ?? '',
            'whatsapp.meta_connect_timeout' => $data['connect_timeout'] ?? '',
        ]);

        $audit->log(
            'whatsapp_meta.updated',
            'Configuração da API oficial da Meta alterada.',
            null,
            $old,
            $settings->auditable(),
        );

        return redirect()
            ->route('admin.whatsapp.meta-settings')
            ->with('success', 'Configuração da Meta salva.');
    }
}
