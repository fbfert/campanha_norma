<?php

namespace App\Http\Controllers\Admin\KeywordCampaigns;

use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Http\Controllers\Controller;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\AuditLogger;
use App\Services\KeywordCampaigns\ParticipantExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Os inscritos de uma campanha.
 *
 * Duas ações aqui mudam quem pode ganhar o prêmio — invalidar e corrigir nome —
 * e as duas ficam gravadas com autor e motivo. Nada é apagado: a pergunta que
 * aparece depois do anúncio do ganhador é "por que fulano não está na lista", e
 * ela só tem resposta se a linha continuar existindo.
 */
class KeywordParticipationController extends Controller
{
    public function index(Request $request, KeywordCampaign $campaign): View
    {
        abort_unless($request->user()->can('keyword_participations.view'), 403);

        $busca = trim((string) $request->query('busca'));
        $situacao = (string) $request->query('situacao');
        $elegibilidade = (string) $request->query('elegibilidade');
        $semNome = $request->boolean('sem_nome');

        $participations = $campaign->participations()
            ->with('contact')
            ->when($busca !== '', function ($query) use ($busca): void {
                $digitos = preg_replace('/\D+/', '', $busca) ?? '';

                $query->where(function ($inner) use ($busca, $digitos): void {
                    $inner
                        ->where('captured_name', 'like', "%{$busca}%")
                        ->orWhere('reviewed_name', 'like', "%{$busca}%")
                        ->orWhereHas('contact', function ($contato) use ($busca, $digitos): void {
                            $contato->where('name', 'like', "%{$busca}%");

                            if ($digitos !== '') {
                                $contato->orWhere('phone_normalized', 'like', "%{$digitos}%");
                            }
                        });
                });
            })
            ->when($situacao !== '', fn ($query) => $query->where('status', $situacao))
            ->when($elegibilidade !== '', fn ($query) => $query->where('eligibility', $elegibilidade))
            // Nome ausente é o filtro que a operação usa antes do anúncio: dá
            // para chamar um ganhador pelo número, mas não dá para publicar.
            ->when($semNome, fn ($query) => $query->whereNull('captured_name')->whereNull('reviewed_name'))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.keyword-campaigns.participations.index', [
            'campaign' => $campaign,
            'participations' => $participations,
            'busca' => $busca,
            'situacaoAtual' => $situacao,
            'elegibilidadeAtual' => $elegibilidade,
            'semNome' => $semNome,
            'situacoes' => KeywordParticipationStatus::cases(),
            'elegibilidades' => KeywordParticipationEligibility::cases(),
        ]);
    }

    /**
     * Corrige o nome sem apagar o que o provedor informou.
     *
     * O original fica: é o que permite responder de onde veio o nome errado, e
     * é o que impede que uma correção equivocada se torne a única versão.
     */
    public function updateName(
        Request $request,
        KeywordCampaign $campaign,
        KeywordCampaignParticipation $participation,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($request->user()->can('keyword_participations.invalidate'), 403);
        abort_unless($participation->keyword_campaign_id === $campaign->id, 404);

        $validado = $request->validate([
            'reviewed_name' => ['required', 'string', 'max:120'],
        ], [], ['reviewed_name' => 'nome corrigido']);

        $antes = $participation->only(['captured_name', 'reviewed_name']);

        $participation->update([
            'reviewed_name' => trim($validado['reviewed_name']),
            'name_reviewed_by' => $request->user()->id,
            'name_reviewed_at' => now(),
            // Um nome corrigido tira a participação da situação "sem nome", que
            // existia só para dizer que faltava exatamente isto.
            'status' => $participation->status === KeywordParticipationStatus::SemNome
                ? KeywordParticipationStatus::Valida
                : $participation->status,
        ]);

        $audit->log('keyword_campaign.participation_name_reviewed', 'Nome de participante corrigido.', $participation, $antes, $participation->only([
            'captured_name', 'reviewed_name',
        ]), $request->user());

        return back()->with('success', 'Nome corrigido. O valor informado pelo provedor continua guardado.');
    }

    /**
     * Tira a participação do sorteio, com motivo escrito.
     *
     * O motivo é obrigatório porque invalidação sem motivo é indistinguível, na
     * auditoria, de alguém tirando da lista quem não queria que ganhasse.
     */
    public function invalidate(
        Request $request,
        KeywordCampaign $campaign,
        KeywordCampaignParticipation $participation,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($request->user()->can('keyword_participations.invalidate'), 403);
        abort_unless($participation->keyword_campaign_id === $campaign->id, 404);

        $validado = $request->validate([
            'invalidation_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'invalidation_reason.required' => 'Escreva o motivo da invalidação.',
            'invalidation_reason.min' => 'Escreva um motivo que outra pessoa consiga entender depois.',
        ], ['invalidation_reason' => 'motivo']);

        $antes = $participation->only(['status', 'invalidation_reason']);

        $participation->update([
            'status' => KeywordParticipationStatus::Invalidada,
            'invalidated_by' => $request->user()->id,
            'invalidated_at' => now(),
            'invalidation_reason' => trim($validado['invalidation_reason']),
        ]);

        $audit->log('keyword_campaign.participation_invalidated', 'Participação invalidada.', $participation, $antes, [
            'status' => KeywordParticipationStatus::Invalidada->value,
            'invalidation_reason' => $participation->invalidation_reason,
        ], $request->user());

        $recado = $campaign->estaCongelada()
            ? 'Participação invalidada. A lista já congelada e o sorteio já executado não mudam: um novo sorteio exige novo congelamento.'
            : 'Participação invalidada e fora do sorteio.';

        return back()->with('success', $recado);
    }

    public function export(
        Request $request,
        KeywordCampaign $campaign,
        ParticipantExportService $exports,
    ): RedirectResponse {
        abort_unless($request->user()->can('keyword_participations.export'), 403);

        $export = $exports->solicitar($request->user(), $campaign, (string) $request->input('format', 'csv'));

        return redirect()
            ->route('admin.report-exports.show', $export)
            ->with('success', 'Exportação gerada. O arquivo é privado e expira sozinho.');
    }
}
