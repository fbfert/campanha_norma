<?php

namespace App\Services\MessageProcessing;

use App\Enums\MessageRecipientProcessingStatus;
use App\Models\MessageBatchRecipient;
use App\Models\SendingSetting;

/**
 * Trava de reciprocidade: não abordar mais gente do que está respondendo.
 *
 * Os limites que já existiam eram todos de ritmo — por minuto, por hora, por
 * dia. Nenhum olhava para o outro lado. Era possível abordar mil pessoas em
 * ritmo impecável sem que uma única respondesse, e nada no sistema notava: os
 * contadores mostravam sucesso, porque entregar a mensagem é sucesso.
 *
 * Esta trava mede a conversa, não a entrega. Quando o número de pessoas
 * abordadas que nunca responderam alcança o teto, o envio para e espera. O que
 * destrava não é o relógio: é alguém do outro lado responder.
 *
 * A contagem é de pessoas, não de mensagens. Quem recebeu três mensagens e não
 * respondeu conta uma vez, porque o que se quer medir é quanta gente está em
 * silêncio — mandar mais para a mesma pessoa não aumenta o alcance.
 */
class ReciprocityGuard
{
    /**
     * @return array{allowed: bool, waiting: int, threshold: int, reason: ?string}
     */
    public function check(SendingSetting $settings): array
    {
        $teto = (int) $settings->unanswered_lock_threshold;

        // Teto zerado desliga a trava: e o comportamento de quem já enviava
        // antes de ela existir.
        if ($teto <= 0) {
            return $this->allow(0, $teto);
        }

        $emSilencio = $this->silentContacts();

        if ($emSilencio < $teto) {
            return $this->allow($emSilencio, $teto);
        }

        return [
            'allowed' => false,
            'waiting' => $emSilencio,
            'threshold' => $teto,
            'reason' => "{$emSilencio} pessoas abordadas ainda não responderam, e o limite é {$teto}. O envio recomeça quando alguém responder.",
        ];
    }

    /**
     * Quantas pessoas distintas foram abordadas por lote e nunca responderam.
     *
     * `has_replied` e mantido pela entrada de mensagens: assim que a pessoa
     * escreve, ela sai desta conta e a trava afrouxa sozinha, sem ninguém
     * precisar liberar nada.
     */
    public function silentContacts(): int
    {
        return MessageBatchRecipient::query()
            ->where('processing_status', MessageRecipientProcessingStatus::Sent)
            ->whereNotNull('contact_id')
            ->whereHas('contact', fn ($query) => $query->where('has_replied', false))
            ->distinct('contact_id')
            ->count('contact_id');
    }

    /**
     * @return array{allowed: bool, waiting: int, threshold: int, reason: ?string}
     */
    private function allow(int $emSilencio, int $teto): array
    {
        return ['allowed' => true, 'waiting' => $emSilencio, 'threshold' => $teto, 'reason' => null];
    }
}
