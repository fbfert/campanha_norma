<?php

namespace App\Http\Controllers\Admin\KeywordCampaigns;

use App\Http\Controllers\Controller;
use App\Jobs\EntregarCupomDeCampanhaJob;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignDraw;
use App\Services\KeywordCampaigns\CouponService;
use App\Services\KeywordCampaigns\DrawService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * O sorteio e a entrega do prêmio.
 *
 * Sortear é um ato deliberado de uma pessoa, com confirmação na tela. Não há
 * agendamento: um sorteio que acontece sozinho é um sorteio que ninguém estava
 * olhando quando aconteceu.
 */
class KeywordDrawController extends Controller
{
    public function index(Request $request, KeywordCampaign $campaign, CouponService $coupons): View
    {
        abort_unless($request->user()->can('keyword_campaigns.view'), 403);

        return view('admin.keyword-campaigns.draws.index', [
            'campaign' => $campaign,
            'draws' => $campaign->draws()->with('executor')->orderByDesc('id')->get(),
            'cuponsEmEstoque' => $coupons->disponiveis($campaign),
            'cuponsTotal' => $campaign->coupons()->count(),
            // O código só é revelado a quem tem a permissão própria.
            'podeVerCodigos' => (bool) $request->user()->can('keyword_coupons.manage'),
        ]);
    }

    public function importCoupons(Request $request, KeywordCampaign $campaign, CouponService $coupons): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_coupons.manage'), 403);

        $request->validate([
            'arquivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ], [], ['arquivo' => 'arquivo']);

        $resultado = $coupons->importar($campaign, $request->file('arquivo'), $request->user());

        $recado = "{$resultado['importados']} ".($resultado['importados'] === 1 ? 'cupom importado.' : 'cupons importados.');

        if ($resultado['repetidos'] > 0) {
            $recado .= " {$resultado['repetidos']} já existiam e foram ignorados.";
        }

        return back()->with('success', $recado);
    }

    /**
     * Cupons digitados à mão, um por linha — um para cada ganhador.
     *
     * Existe porque nem todo prêmio vem de planilha: são três códigos que
     * alguém leu de um e-mail, e obrigar a montar um CSV para isso é obrigar a
     * criar um arquivo com cupom dentro só para poder apagá-lo depois.
     *
     * O caminho é o mesmo da importação: idempotente, sem código no log.
     */
    public function storeCoupons(Request $request, KeywordCampaign $campaign, CouponService $coupons): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_coupons.manage'), 403);

        $validado = $request->validate([
            'codigos' => ['required', 'string', 'max:20000'],
        ], [
            'codigos.required' => 'Escreva ao menos um código, um por linha.',
        ], ['codigos' => 'códigos']);

        $codigos = $coupons->separarLinhas($validado['codigos']);

        if ($codigos === []) {
            return back()
                ->withErrors(['codigos' => 'Escreva ao menos um código, um por linha.'])
                ->withInput();
        }

        // O mesmo teto do sorteio: o que não pode ser sorteado de uma vez
        // também não precisa ser cadastrado de uma vez.
        if (count($codigos) > 1000) {
            return back()
                ->withErrors(['codigos' => 'São '.count($codigos).' códigos de uma vez, e o limite é 1000. Para um lote desse tamanho, use a importação por arquivo.'])
                ->withInput();
        }

        $resultado = $coupons->cadastrarAMao($campaign, $validado['codigos'], $request->user());

        $recado = "{$resultado['importados']} ".($resultado['importados'] === 1 ? 'cupom cadastrado.' : 'cupons cadastrados.');

        if ($resultado['repetidos'] > 0) {
            $recado .= " {$resultado['repetidos']} já existiam e foram ignorados.";
        }

        return back()->with('success', $recado);
    }

    public function store(Request $request, KeywordCampaign $campaign, DrawService $draws): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_draws.execute'), 403);

        $validado = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'seed' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:500'],
            // A confirmação explícita: sortear não pode ser um clique acidental.
            'confirmacao' => ['accepted'],
        ], [
            'confirmacao.accepted' => 'Confirme que quer executar o sorteio agora.',
        ], ['quantity' => 'quantidade', 'seed' => 'semente', 'note' => 'observação']);

        try {
            $draw = $draws->sortear(
                $campaign,
                (int) $validado['quantity'],
                $request->user(),
                $validado['seed'] ?? null,
                $validado['note'] ?? null,
            );
        } catch (ValidationException $excecao) {
            return back()->withErrors($excecao->errors())->withInput();
        }

        return back()->with('success', "Sorteio executado com {$draw->quantity} "
            .($draw->quantity === 1 ? 'ganhador' : 'ganhadores').'. A semente ficou registrada.');
    }

    /**
     * Refaz a conta na frente de quem está olhando.
     */
    public function verify(Request $request, KeywordCampaign $campaign, KeywordCampaignDraw $draw, DrawService $draws): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_campaigns.view'), 403);
        abort_unless($draw->keyword_campaign_id === $campaign->id, 404);

        $verificacao = $draws->verificar($draw);

        if ($verificacao['confere']) {
            return back()->with('success', 'Verificação concluída: a mesma lista e a mesma semente produzem exatamente o mesmo resultado.');
        }

        if (! $verificacao['lista_confere']) {
            return back()->withErrors([
                'sorteio' => 'A lista mudou depois deste sorteio. O sorteio continua válido como registro; a conta não bate porque a lista atual é outra.',
            ]);
        }

        return back()->withErrors(['sorteio' => 'A verificação não reproduziu o resultado registrado.']);
    }

    public function deliver(Request $request, KeywordCampaign $campaign, CouponService $coupons): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_coupons.manage'), 403);

        $pendentes = $campaign->coupons()
            ->whereNotNull('keyword_campaign_participation_id')
            ->whereNull('delivered_at')
            ->pluck('id');

        foreach ($pendentes as $couponId) {
            EntregarCupomDeCampanhaJob::dispatch((int) $couponId);
        }

        return back()->with('success', $pendentes->count().' '
            .($pendentes->count() === 1 ? 'cupom enfileirado para entrega.' : 'cupons enfileirados para entrega.')
            .' O envio passa pelo mesmo teto das confirmações.');
    }
}
