<?php

namespace App\Http\Controllers\Admin\KeywordCampaigns;

use App\Enums\KeywordParticipationEligibility;
use App\Http\Controllers\Controller;
use App\Models\KeywordCampaign;
use App\Services\KeywordCampaigns\CampaignFreezer;
use App\Services\KeywordCampaigns\StudentEligibilityImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A conferência de elegibilidade e o fechamento da lista.
 *
 * A campanha é entre alunos, mas a entrada não verifica nada — qualquer pessoa
 * se inscreve. Esta tela é onde a diferença é resolvida, e ela resolve marcando,
 * nunca recusando.
 */
class KeywordEligibilityController extends Controller
{
    public function index(Request $request, KeywordCampaign $campaign, CampaignFreezer $freezer): View
    {
        abort_unless($request->user()->can('keyword_participations.view'), 403);

        return view('admin.keyword-campaigns.eligibility.index', [
            'campaign' => $campaign,
            'pendentes' => $campaign->pendentesDeConferencia()
                ->with('contact')
                ->orderBy('id')
                ->paginate(100),
            'totalPendente' => $campaign->pendentesDeConferencia()->count(),
            'totalDaLista' => count($freezer->listaElegivel($campaign)),
        ]);
    }

    public function import(
        Request $request,
        KeywordCampaign $campaign,
        StudentEligibilityImporter $importer,
    ): RedirectResponse {
        abort_unless($request->user()->can('keyword_participations.invalidate'), 403);

        $request->validate([
            'arquivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ], [], ['arquivo' => 'arquivo']);

        $resultado = $importer->importar($campaign, $request->file('arquivo'), $request->user());

        $recado = "{$resultado['marked']} ".($resultado['marked'] === 1 ? 'inscrição marcada' : 'inscrições marcadas')
            .' como aluno confirmado.';

        if ($resultado['already_marked'] > 0) {
            $recado .= " {$resultado['already_marked']} já estavam marcadas.";
        }

        if ($resultado['unmatched'] > 0) {
            $recado .= " {$resultado['unmatched']} continuam esperando conferência.";
        }

        if ($resultado['invalid_phones'] > 0) {
            $recado .= " {$resultado['invalid_phones']} ".
                ($resultado['invalid_phones'] === 1 ? 'telefone do arquivo é inválido' : 'telefones do arquivo são inválidos').'.';
        }

        return back()->with('success', $recado);
    }

    /**
     * Conferência em lote. Um por um não escala numa divulgação de mil pessoas.
     */
    public function review(Request $request, KeywordCampaign $campaign, CampaignFreezer $freezer): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_participations.invalidate'), 403);

        $validado = $request->validate([
            'participations' => ['required', 'array', 'min:1'],
            'participations.*' => ['integer'],
            'eligibility' => ['required', Rule::in([
                KeywordParticipationEligibility::AlunoConfirmado->value,
                KeywordParticipationEligibility::NaoAluno->value,
            ])],
        ], [
            'participations.required' => 'Selecione ao menos uma inscrição.',
        ], ['participations' => 'inscrições', 'eligibility' => 'elegibilidade']);

        $total = $freezer->conferirEmLote(
            $campaign,
            array_map('intval', $validado['participations']),
            KeywordParticipationEligibility::from($validado['eligibility']),
            $request->user(),
        );

        return back()->with('success', "{$total} ".($total === 1 ? 'inscrição conferida.' : 'inscrições conferidas.'));
    }

    public function freeze(Request $request, KeywordCampaign $campaign, CampaignFreezer $freezer): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_campaigns.manage'), 403);

        try {
            $campaign = $freezer->congelar($campaign, $request->user());
        } catch (ValidationException $excecao) {
            return back()->withErrors($excecao->errors());
        }

        return back()->with(
            'success',
            "Lista congelada com {$campaign->frozen_list_count} "
                .($campaign->frozen_list_count === 1 ? 'participante' : 'participantes')
                .'. Novas inscrições não são mais aceitas, e o sorteio já pode ser executado.',
        );
    }

    public function unfreeze(Request $request, KeywordCampaign $campaign, CampaignFreezer $freezer): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_campaigns.manage'), 403);

        $validado = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Descongelar permite refazer o sorteio: escreva o motivo.',
        ], ['motivo' => 'motivo']);

        try {
            $freezer->descongelar($campaign, $validado['motivo'], $request->user());
        } catch (ValidationException $excecao) {
            return back()->withErrors($excecao->errors());
        }

        return back()->with('success', 'Lista descongelada. Um sorteio já executado continua registrado como está.');
    }
}
