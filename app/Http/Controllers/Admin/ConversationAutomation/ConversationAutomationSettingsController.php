<?php

namespace App\Http\Controllers\Admin\ConversationAutomation;

use App\Enums\ConversationFlowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConversationAutomation\AiThresholdSettingsRequest;
use App\Http\Requests\ConversationAutomation\ConversationAutomationSettingsRequest;
use App\Models\ConversationFlow;
use App\Models\MessageBatch;
use App\Services\Ai\AiThresholdSettings;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationAutomationSettings;
use App\Services\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de configuração da automação conversacional.
 *
 * Reune o que antes exigia acesso ao banco: ligar o motor, liberar o envio
 * automático, ajustar janela, limites, textos e as listas de expressões.
 *
 * A tela também responde "por que não esta respondendo?", porque as causas mais
 * comuns não são a configuração desta página: fluxo sem pergunta ativa e lote
 * enviado sem fluxo vinculado não aparecem em nenhum campo do formulário.
 */
class ConversationAutomationSettingsController extends Controller
{
    public function edit(Request $request, ConversationAutomationSettings $settings): View
    {
        abort_unless($request->user()->can('conversation_automation.manage_settings'), 403);

        return view('admin.conversation-automation.settings', [
            'form' => $settings->forForm(),
            'limiares' => app(AiThresholdSettings::class)->forForm(),
            'diagnostico' => $this->diagnostico(app(SystemSettingService::class)),
        ]);
    }

    /**
     * Limiares de confiança da IA.
     *
     * Formulário próprio, na mesma tela: são chaves de outro grupo, com outra
     * validação, e salvar um não deve exigir revalidar o outro.
     */
    public function updateThresholds(AiThresholdSettingsRequest $request, AiThresholdSettings $limiares): RedirectResponse
    {
        $old = $limiares->save($request->validated());

        app(AuditLogger::class)->log(
            'ai.thresholds_updated',
            'Limiares de confiança da IA alterados.',
            null,
            $old,
            $limiares->auditable(),
        );

        return redirect()
            ->route('admin.conversation-automation.settings.edit')
            ->with('success', 'Limiares de confiança salvos.');
    }

    public function update(ConversationAutomationSettingsRequest $request, ConversationAutomationSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        $old = $settings->save(array_merge($data, [
            'enabled' => $request->boolean('enabled'),
            'auto_send_enabled' => $request->boolean('auto_send_enabled'),
            'mark_do_not_contact_on_refusal' => $request->boolean('mark_do_not_contact_on_refusal'),
        ]));

        app(AuditLogger::class)->log(
            'conversation_automation.settings_updated',
            'Configuração da automação conversacional alterada.',
            null,
            $old,
            $settings->auditable(),
        );

        return redirect()
            ->route('admin.conversation-automation.settings.edit')
            ->with('success', 'Configuração da automação salva.');
    }

    /**
     * Sinais que decidem se alguma resposta automática chega a sair.
     *
     * @return array<string, mixed>
     */
    private function diagnostico(SystemSettingService $settings): array
    {
        $flows = ConversationFlow::query()
            ->where('status', ConversationFlowStatus::Active)
            ->withCount(['questions' => fn ($query) => $query->where('is_active', true)])
            ->get();

        return [
            'ligada' => (bool) $settings->get('conversation_automation.enabled', '0'),
            'envio_automatico' => (bool) $settings->get('conversation_automation.auto_send_enabled', '0'),
            'fluxos_ativos' => $flows->count(),
            'fluxos_sem_pergunta' => $flows->where('questions_count', 0)->count(),
            'lotes_com_fluxo' => MessageBatch::query()->whereNotNull('conversation_flow_id')->count(),
            'filas' => [
                (string) $settings->get('conversation_automation.queue', 'conversation-automation'),
                (string) $settings->get('conversation_automation.send_queue', 'conversation-automation-send'),
            ],
        ];
    }
}
