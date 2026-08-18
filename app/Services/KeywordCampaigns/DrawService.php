<?php

namespace App\Services\KeywordCampaigns;

use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignDraw;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageBatches\RandomSelectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O sorteio.
 *
 * Executa apenas sobre lista congelada, grava tudo o que alguém de fora precisa
 * para refazer a conta, e é sempre um ato deliberado de uma pessoa. Não existe
 * agendamento automático: um sorteio que acontece sozinho é um sorteio que
 * ninguém estava olhando quando aconteceu.
 */
class DrawService
{
    public function __construct(
        private readonly RandomSelectionService $random,
        private readonly CampaignFreezer $freezer,
        private readonly CouponService $coupons,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function sortear(
        KeywordCampaign $campaign,
        int $quantidade,
        ?User $usuario = null,
        ?string $semente = null,
        ?string $observacao = null,
    ): KeywordCampaignDraw {
        if (! $campaign->estaCongelada()) {
            throw ValidationException::withMessages([
                'sorteio' => 'A lista precisa estar congelada antes do sorteio. Sem congelamento, a lista muda entre o sorteio e o anúncio.',
            ]);
        }

        if ($quantidade < 1) {
            throw ValidationException::withMessages(['sorteio' => 'Informe quantos ganhadores sortear.']);
        }

        $lista = $this->freezer->listaCongelada($campaign);

        if (count($lista) < $quantidade) {
            throw ValidationException::withMessages([
                'sorteio' => 'A lista congelada tem '.count($lista).' '
                    .(count($lista) === 1 ? 'participante' : 'participantes')
                    .", e não dá para sortear {$quantidade}.",
            ]);
        }

        /*
         | Cupom insuficiente recusa o sorteio, e não o contrário.
         |
         | Sortear primeiro e descobrir depois que falta prêmio obriga a
         | escolher entre não entregar a um ganhador anunciado e refazer o
         | sorteio — e as duas saídas destroem a auditabilidade que o
         | congelamento existe para dar.
         */
        $disponiveis = $this->coupons->disponiveis($campaign);

        if ($disponiveis < $quantidade) {
            $faltam = $quantidade - $disponiveis;

            throw ValidationException::withMessages([
                'sorteio' => "Faltam {$faltam} ".($faltam === 1 ? 'cupom' : 'cupons')
                    .": há {$disponiveis} ".($disponiveis === 1 ? 'disponível' : 'disponíveis')
                    ." para {$quantidade} ".($quantidade === 1 ? 'ganhador' : 'ganhadores').'.',
            ]);
        }

        $semente = $semente !== null && trim($semente) !== ''
            ? trim($semente)
            : $this->random->seed();

        $resultado = $this->random->auditableSample($lista, $quantidade, $semente);

        return DB::transaction(function () use ($campaign, $lista, $semente, $quantidade, $resultado, $usuario, $observacao): KeywordCampaignDraw {
            $draw = KeywordCampaignDraw::create([
                'keyword_campaign_id' => $campaign->id,
                'list_hash' => $this->freezer->hash($lista),
                'seed' => $semente,
                'quantity' => $quantidade,
                'result' => $resultado,
                'executed_by' => $usuario?->id,
                'executed_at' => now(),
                'note' => $observacao,
            ]);

            $this->coupons->atribuirAosGanhadores($campaign, $resultado);

            $this->audit->log(
                'keyword_campaign.draw_executed',
                "Sorteio executado na campanha \"{$campaign->name}\" com {$quantidade} "
                    .($quantidade === 1 ? 'ganhador' : 'ganhadores').'.',
                $draw,
                null,
                [
                    'list_hash' => $draw->list_hash,
                    'seed' => $semente,
                    'quantity' => $quantidade,
                    'result' => $resultado,
                ],
                $usuario,
            );

            return $draw;
        });
    }

    /**
     * Refaz a conta de um sorteio já registrado.
     *
     * É o que a tela oferece para conferir: mesma lista congelada, mesma
     * semente, mesmo resultado. Devolve `false` quando a lista mudou depois —
     * o que não invalida o sorteio, mas explica por que a conta não bate.
     *
     * @return array{confere: bool, lista_confere: bool, resultado: list<int>}
     */
    public function verificar(KeywordCampaignDraw $draw): array
    {
        $lista = $this->freezer->listaCongelada($draw->campaign);
        $listaConfere = $this->freezer->hash($lista) === $draw->list_hash;

        $refeito = $this->random->auditableSample($lista, $draw->quantity, $draw->seed);

        return [
            'confere' => $listaConfere && $refeito === array_map('intval', $draw->result ?? []),
            'lista_confere' => $listaConfere,
            'resultado' => $refeito,
        ];
    }
}
