<?php

namespace App\Console\Commands;

use App\Models\KeywordCampaign;
use App\Services\KeywordCampaigns\ConfirmationThrottle;
use App\Services\KeywordCampaigns\CouponService;
use Illuminate\Console\Command;

/**
 * Responde "está funcionando?" sem abrir o banco.
 *
 * É a primeira coisa a rodar quando alguém diz que mandou a palavra e não
 * aconteceu nada, e a última antes de ligar a divulgação.
 */
class CampanhasDiagnosticarCommand extends Command
{
    protected $signature = 'campanhas:diagnosticar {--campanha= : Diagnostica apenas esta campanha}';

    protected $description = 'Mostra o estado de cada campanha por palavra-chave.';

    public function handle(ConfirmationThrottle $throttle, CouponService $coupons): int
    {
        $campanhas = KeywordCampaign::query()
            ->when($this->option('campanha'), fn ($query) => $query->whereKey((int) $this->option('campanha')))
            ->orderBy('id')
            ->get();

        $this->line('Limitador de confirmação: teto de '.$throttle->tetoPorMinuto().' por minuto, '
            .$throttle->intervaloMinimoSegundos().'s de intervalo. '
            .$throttle->usadoNoMinuto().' já usadas neste minuto.');
        $this->newLine();

        if ($campanhas->isEmpty()) {
            $this->warn('Nenhuma campanha cadastrada. Nada dispara.');

            return self::SUCCESS;
        }

        foreach ($campanhas as $campanha) {
            $this->line("#{$campanha->id} — {$campanha->name}");
            $this->line('  situação: '.$campanha->status->label()
                .($campanha->estaVigente() ? ' (recebendo inscrições)' : ' (não recebe inscrições agora)'));
            $this->line('  vigência: '.$campanha->starts_at?->format('d/m/Y H:i').' até '.$campanha->ends_at?->format('d/m/Y H:i'));
            $this->line('  palavras: '.implode(', ', $campanha->keywordList()));

            $inscritos = $campanha->validParticipations()->count();
            $pendentes = $campanha->pendentesDeConferencia()->count();

            $this->line("  inscritos: {$inscritos}"
                .($campanha->participant_limit ? ' de '.$campanha->participant_limit : ' (sem limite)'));
            $this->line("  a conferir: {$pendentes}");

            if ($campanha->hourly_alert_raised_at) {
                $this->warn('  alarme de rajada disparado em '.$campanha->hourly_alert_raised_at->format('d/m/Y H:i'));
            }

            $disponiveis = $coupons->disponiveis($campanha);
            $this->line("  cupons: {$disponiveis} disponíveis de ".$campanha->coupons()->count()
                .' ('.$campanha->coupons()->whereNotNull('delivered_at')->count().' entregues)');

            $this->line('  congelamento: '.($campanha->estaCongelada()
                ? $campanha->frozen_at->format('d/m/Y H:i').' com '.$campanha->frozen_list_count
                : 'não congelada'));

            $sorteio = $campanha->draws()->latest('id')->first();
            $this->line('  sorteio: '.($sorteio
                ? $sorteio->executed_at?->format('d/m/Y H:i').' com '.$sorteio->quantity
                : 'nenhum'));

            /*
             | O aviso que evita a pergunta mais cara.
             |
             | Campanha ativa com palavras cadastradas e zero inscritos depois
             | de a vigência começar é o sintoma de divulgação que não saiu, ou
             | de palavra que ninguém escreve do jeito que foi cadastrada.
             */
            if ($campanha->estaVigente() && $inscritos === 0 && $campanha->starts_at?->isPast()) {
                $this->warn('  atenção: vigente e sem nenhuma inscrição.');
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
