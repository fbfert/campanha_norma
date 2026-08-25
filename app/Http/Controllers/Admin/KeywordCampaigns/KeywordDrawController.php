<?php

namespace App\Http\Controllers\Admin\KeywordCampaigns;

use App\Http\Controllers\Controller;
use App\Jobs\EntregarCupomDeCampanhaJob;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use App\Models\KeywordCampaignDraw;
use App\Services\KeywordCampaigns\CouponMessage;
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
    public function index(Request $request, KeywordCampaign $campaign, CouponService $coupons, CouponMessage $mensagens): View
    {
        abort_unless($request->user()->can('keyword_campaigns.view'), 403);

        // O código só é revelado a quem tem a permissão própria.
        $podeVerCodigos = (bool) $request->user()->can('keyword_coupons.manage');

        /*
         | Usado antes de disponível, e não por ordem de cadastro.
         |
         | Quem abre esta tela depois do sorteio quer saber para quem o prêmio
         | foi; quem abre antes quer saber se tem cupom bastante, e para isso o
         | contador do topo já responde sem descer a página.
         |
         | `CASE WHEN` em vez do booleano direto: a mesma expressão passa em
         | MySQL e em SQLite, e a suíte roda no segundo.
         */
        $cupons = $campaign->coupons()
            ->with('participation.contact')
            ->orderByRaw('CASE WHEN keyword_campaign_participation_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->paginate(100)
            ->withQueryString();

        /*
         | A revelação acontece aqui, onde a permissão foi conferida, e não na
         | view.
         |
         | O modelo esconde `code` de toda serialização justamente para o código
         | não escapar por um caminho que ninguém revisou. Montar o mapa no
         | controlador é o que garante que a view nunca tem o que vazar quando a
         | permissão falta: sem ela o mapa chega vazio.
         */
        $codigos = $podeVerCodigos
            ? collect($cupons->items())->mapWithKeys(
                fn (KeywordCampaignCoupon $cupom): array => [$cupom->id => $coupons->revelar($cupom)],
            )->all()
            : [];

        return view('admin.keyword-campaigns.draws.index', [
            'campaign' => $campaign,
            'draws' => $campaign->draws()->with('executor')->orderByDesc('id')->get(),
            'cuponsEmEstoque' => $coupons->disponiveis($campaign),
            'cuponsTotal' => $campaign->coupons()->count(),
            'podeVerCodigos' => $podeVerCodigos,
            'mensagemDoCupom' => $mensagens->texto($campaign),
            'cupons' => $cupons,
            'codigos' => $codigos,
            'cuponsEntregues' => $campaign->coupons()->whereNotNull('delivered_at')->count(),
            'cuponsAEntregar' => $campaign->coupons()
                ->whereNotNull('keyword_campaign_participation_id')
                ->whereNull('delivered_at')
                ->count(),
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

    /**
     * Entrega os cupons, com a mensagem que o ganhador vai ler.
     *
     * A mensagem é conferida e gravada antes de qualquer job sair. Enfileirar
     * primeiro e validar depois deixaria metade do lote entregue com um texto
     * que a outra metade não vai receber — e mensagem entregue não volta.
     */
    public function deliver(Request $request, KeywordCampaign $campaign, CouponService $coupons, CouponMessage $mensagens): RedirectResponse
    {
        abort_unless($request->user()->can('keyword_coupons.manage'), 403);

        $validado = $request->validate([
            'mensagem' => ['required', 'string', 'max:4000'],
        ], [
            'mensagem.required' => 'Escreva a mensagem que o ganhador vai receber.',
        ], ['mensagem' => 'mensagem']);

        $texto = trim($validado['mensagem']);
        $erros = $mensagens->erros($texto);

        if ($erros !== []) {
            return back()->withErrors(['mensagem' => $erros])->withInput();
        }

        $pendentes = $campaign->coupons()
            ->whereNotNull('keyword_campaign_participation_id')
            ->whereNull('delivered_at')
            ->with('participation.contact')
            ->get();

        /*
         | Quem seria saudado pelo nome precisa ter nome, e isso é conferido
         | aqui, antes de enfileirar.
         |
         | Descobrir no meio da fila que um ganhador não tem nome deixaria a
         | escolha entre mandar "Parabéns, !" e não mandar nada — e as duas são
         | ruins depois que metade do lote já saiu.
         */
        $semNome = $mensagens->ganhadoresSemNome(
            $texto,
            $pendentes->map(fn ($cupom) => $cupom->participation)->filter(),
        );

        if ($semNome !== []) {
            return back()->withErrors(['mensagem' => count($semNome).' '
                .(count($semNome) === 1 ? 'ganhador não tem nome cadastrado' : 'ganhadores não têm nome cadastrado')
                .' e a mensagem usa {nome}: '.implode(', ', array_slice($semNome, 0, 5))
                .(count($semNome) > 5 ? ' e outros' : '')
                .'. Tire o {nome} da mensagem ou complete o cadastro na conferência.'])->withInput();
        }

        // Gravada na campanha: reenviar depois de uma falha manda o mesmo
        // texto, e não o padrão de fábrica.
        $campaign->update(['coupon_text' => $texto]);

        foreach ($pendentes as $cupom) {
            EntregarCupomDeCampanhaJob::dispatch((int) $cupom->id);
        }

        return back()->with('success', $pendentes->count().' '
            .($pendentes->count() === 1 ? 'cupom enfileirado para entrega.' : 'cupons enfileirados para entrega.')
            .' O envio passa pelo mesmo teto das confirmações.');
    }
}
