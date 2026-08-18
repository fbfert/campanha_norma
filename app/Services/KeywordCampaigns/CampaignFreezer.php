<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fecha a lista que vai ao sorteio.
 *
 * O congelamento é o que separa um sorteio auditável de um sorteio que se
 * discute depois. Sem ele, a lista muda entre o sorteio e o anúncio e ninguém
 * de fora consegue conferir que foi sorteado o que foi publicado.
 */
class CampaignFreezer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Congela a lista, ou recusa dizendo o que falta.
     *
     * @throws ValidationException
     */
    public function congelar(KeywordCampaign $campaign, ?User $usuario = null): KeywordCampaign
    {
        if ($campaign->estaCongelada()) {
            throw ValidationException::withMessages([
                'campanha' => 'A lista desta campanha já foi congelada em '
                    .$campaign->frozen_at->format('d/m/Y H:i').'.',
            ]);
        }

        /*
         | A fila de conferência precisa estar vazia.
         |
         | Uma lista congelada com inelegível dentro obriga a resortear quando o
         | sorteio apontar um deles — e um sorteio refeito porque o ganhador não
         | servia é indistinguível, para quem está de fora, de um sorteio
         | refeito porque o ganhador não agradou.
         */
        $pendentes = $campaign->pendentesDeConferencia()->count();

        if ($pendentes > 0) {
            throw ValidationException::withMessages([
                'campanha' => $pendentes === 1
                    ? '1 inscrição ainda não foi conferida. Conclua a conferência antes de congelar.'
                    : "{$pendentes} inscrições ainda não foram conferidas. Conclua a conferência antes de congelar.",
            ]);
        }

        $ids = $this->listaElegivel($campaign);

        if ($ids === []) {
            throw ValidationException::withMessages([
                'campanha' => 'Nenhuma inscrição elegível: não há lista para congelar.',
            ]);
        }

        return DB::transaction(function () use ($campaign, $ids, $usuario): KeywordCampaign {
            $campaign->forceFill([
                'status' => KeywordCampaignStatus::Congelada,
                'frozen_at' => now(),
                'frozen_by' => $usuario?->id,
                'frozen_list_hash' => $this->hash($ids),
                'frozen_list_count' => count($ids),
            ])->save();

            $this->audit->log(
                'keyword_campaign.list_frozen',
                "Lista da campanha \"{$campaign->name}\" congelada com ".count($ids).' participantes.',
                $campaign,
                null,
                ['hash' => $campaign->frozen_list_hash, 'total' => count($ids)],
                $usuario,
            );

            return $campaign->refresh();
        });
    }

    /**
     * Os identificadores que entram na lista congelada.
     *
     * Válida e aluno confirmado, em ordem de identificador. A ordem é parte do
     * hash: a mesma lista precisa produzir o mesmo hash, e ordem de banco não é
     * garantida sem `orderBy`.
     *
     * @return list<int>
     */
    public function listaElegivel(KeywordCampaign $campaign): array
    {
        return $campaign->participations()
            ->elegivelParaSorteio()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A lista congelada, na ordem em que foi congelada.
     *
     * Reconstruída a partir dos mesmos critérios: por isso invalidar depois do
     * congelamento não pode alterá-la, e é isso que os identificadores gravados
     * no sorteio garantem. Este método serve para conferir o hash, não para
     * decidir um sorteio já executado.
     *
     * @return list<int>
     */
    public function listaCongelada(KeywordCampaign $campaign): array
    {
        return $this->listaElegivel($campaign);
    }

    /**
     * Hash do conteúdo da lista, não do instante em que foi tirado.
     *
     * Congelar duas vezes o mesmo conjunto produz o mesmo hash, e é isso que
     * permite a alguém de fora conferir que a lista sorteada é a lista que foi
     * publicada.
     *
     * @param  list<int>  $ids
     */
    public function hash(array $ids): string
    {
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    /**
     * Descongelar exige registro: é a ação que permite refazer um sorteio.
     */
    public function descongelar(KeywordCampaign $campaign, string $motivo, ?User $usuario = null): KeywordCampaign
    {
        if (! $campaign->estaCongelada()) {
            throw ValidationException::withMessages(['campanha' => 'Esta campanha não está congelada.']);
        }

        $anterior = $campaign->only(['frozen_at', 'frozen_list_hash', 'frozen_list_count']);

        $campaign->forceFill([
            'status' => KeywordCampaignStatus::Encerrada,
            'frozen_at' => null,
            'frozen_by' => null,
            'frozen_list_hash' => null,
            'frozen_list_count' => null,
        ])->save();

        $this->audit->log(
            'keyword_campaign.list_unfrozen',
            "Lista da campanha \"{$campaign->name}\" descongelada.",
            $campaign,
            $anterior,
            ['motivo' => $motivo],
            $usuario,
        );

        return $campaign->refresh();
    }

    /**
     * Marca em lote a elegibilidade conferida por um humano.
     *
     * Conferir um por um não escala: uma divulgação de mil pessoas produz
     * centenas de linhas na fila.
     *
     * @param  list<int>  $participationIds
     */
    public function conferirEmLote(
        KeywordCampaign $campaign,
        array $participationIds,
        KeywordParticipationEligibility $elegibilidade,
        ?User $usuario = null,
    ): int {
        if ($participationIds === []) {
            return 0;
        }

        $total = KeywordCampaignParticipation::query()
            ->where('keyword_campaign_id', $campaign->id)
            ->whereIn('id', $participationIds)
            ->update([
                'eligibility' => $elegibilidade,
                'reviewed_by' => $usuario?->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->audit->log(
            'keyword_campaign.eligibility_reviewed',
            "{$total} ".($total === 1 ? 'inscrição conferida' : 'inscrições conferidas')." na campanha \"{$campaign->name}\".",
            $campaign,
            null,
            ['total' => $total, 'eligibility' => $elegibilidade->value],
            $usuario,
        );

        return $total;
    }
}
