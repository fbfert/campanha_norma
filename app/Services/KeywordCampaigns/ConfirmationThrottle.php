<?php

namespace App\Services\KeywordCampaigns;

use App\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Teto global das confirmações de campanha.
 *
 * Divulgação bem-sucedida gera centenas de mensagens recebidas em minutos. Sem
 * teto, o sistema responde todas no ritmo que o worker drenar — e, pelo
 * provedor WhatsApp Web, que é sessão não oficial, esse é o comportamento que
 * mais rápido leva um número a bloqueio. Um número bloqueado interrompe a
 * operação inteira, não apenas a campanha.
 *
 * É separado do limitador de lote de propósito. O de lote protege o ritmo de um
 * disparo que nós escolhemos começar; este protege o ritmo de uma resposta que
 * quem escolhe é quem escreve.
 *
 * `ConversationAutomationGuard` também não serve: o limite dele é por conversa
 * (`automated_messages_count >= max`), e rajada é um problema global.
 */
class ConfirmationThrottle
{
    private const PREFIX = 'keyword_campaigns:confirmation';

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Tenta reservar uma vaga de envio.
     *
     * Reserva, e não pergunta: `SendingRateLimiterService` lê em `check()` e
     * incrementa em `consume()` sem trava, e dois workers passam os dois pela
     * leitura antes de qualquer um incrementar — furando o teto exatamente
     * quando ele mais importa, que é sob carga. Aqui o incremento vem primeiro
     * e a decisão vem do valor que o incremento devolveu; quem passou do teto
     * devolve a vaga.
     */
    public function reservar(?CarbonImmutable $agora = null): ThrottleDecision
    {
        $agora = $agora ?? CarbonImmutable::now();

        $intervalo = $this->intervaloMinimoSegundos();

        if ($intervalo > 0) {
            /*
             | O intervalo mínimo é reservado por `add`, que é atômico.
             |
             | Uma chave que expira sozinha no fim do intervalo: quem conseguir
             | criá-la ganhou a vez, e quem não conseguir espera. Ler a hora do
             | último envio e comparar, como o limitador de lote faz, deixa dois
             | workers lerem a mesma hora e concluírem os dois que podem enviar.
             */
            $chaveIntervalo = self::PREFIX.':interval';

            if (! Cache::add($chaveIntervalo, $agora->toIso8601String(), $intervalo)) {
                return ThrottleDecision::adiada($intervalo, 'intervalo_minimo');
            }
        }

        $teto = $this->tetoPorMinuto();

        if ($teto <= 0) {
            return ThrottleDecision::permitida();
        }

        $chaveMinuto = self::PREFIX.':minute:'.$agora->format('Y-m-d-H-i');

        // `add` cria o contador zerado só se ele ainda não existir, e é onde o
        // prazo de validade é definido. Os dois passos são atômicos, e é a
        // atomicidade de cada um que basta: quem cria, cria uma vez só.
        Cache::add($chaveMinuto, 0, $agora->addMinutes(2)->toDateTime());
        $usado = (int) Cache::increment($chaveMinuto);

        if ($usado > $teto) {
            // Devolve a vaga que não vai usar, senão o minuto seguinte herda um
            // contador inflado por tentativas recusadas.
            Cache::decrement($chaveMinuto);

            return ThrottleDecision::adiada(
                max(1, $agora->addMinute()->startOfMinute()->diffInSeconds($agora)),
                'teto_por_minuto',
            );
        }

        return ThrottleDecision::permitida();
    }

    /**
     * Quantas confirmações já saíram neste minuto.
     */
    public function usadoNoMinuto(?CarbonImmutable $agora = null): int
    {
        $agora = $agora ?? CarbonImmutable::now();

        return (int) Cache::get(self::PREFIX.':minute:'.$agora->format('Y-m-d-H-i'), 0);
    }

    public function tetoPorMinuto(): int
    {
        return (int) $this->settings->get('keyword_campaigns.confirmation_max_per_minute', 20);
    }

    public function intervaloMinimoSegundos(): int
    {
        return (int) $this->settings->get('keyword_campaigns.confirmation_min_interval_seconds', 2);
    }
}
