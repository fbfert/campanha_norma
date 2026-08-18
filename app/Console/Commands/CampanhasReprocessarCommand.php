<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Enums\KeywordEnrollmentOutcome;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\KeywordCampaigns\KeywordMatcherService;
use App\Services\KeywordCampaigns\ParticipationRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A rede de segurança da Etapa 10.
 *
 * A participação é projeção da mensagem, e não efeito colateral dela: por isso
 * ela é derivável do que já está gravado. Job que morreu, fila limpa ou worker
 * derrubado no meio de uma divulgação não perdem inscrição — este comando
 * refaz o caminho a partir das mensagens.
 *
 * É o comando que responde "alguém diz que participou e não consta".
 *
 * Idempotente: rodar duas vezes produz o mesmo estado, porque quem já está
 * inscrito volta como `ja_inscrita` e nada é gravado de novo.
 */
class CampanhasReprocessarCommand extends Command
{
    protected $signature = 'campanhas:reprocessar
        {--campanha= : Reprocessa apenas esta campanha}
        {--from= : Data inicial das mensagens (padrão: início da vigência da campanha)}
        {--to= : Data final das mensagens (padrão: agora)}
        {--dry-run : Mostra o que faria, sem gravar nada}';

    protected $description = 'Recria as participações ausentes a partir das mensagens recebidas.';

    public function handle(KeywordMatcherService $matcher, ParticipationRegistrar $registrar): int
    {
        $seco = (bool) $this->option('dry-run');

        $campanhas = KeywordCampaign::query()
            ->when($this->option('campanha'), fn ($query) => $query->whereKey((int) $this->option('campanha')))
            ->orderBy('id')
            ->get();

        if ($campanhas->isEmpty()) {
            $this->warn('Nenhuma campanha encontrada.');

            return self::SUCCESS;
        }

        $totalCriadas = 0;

        foreach ($campanhas as $campanha) {
            /*
             | O recorte padrão é a vigência da campanha.
             |
             | Varrer o histórico inteiro inscreveria quem escreveu a palavra
             | meses antes da campanha existir — e essa pessoa não se inscreveu
             | em nada.
             */
            $desde = $this->option('from')
                ? Carbon::parse((string) $this->option('from'))->startOfDay()
                : $campanha->starts_at;

            $ate = $this->option('to')
                ? Carbon::parse((string) $this->option('to'))->endOfDay()
                : ($campanha->ends_at?->isPast() ? $campanha->ends_at : now());

            $this->line("Campanha #{$campanha->id} — {$campanha->name}");
            $this->line('  período: '.$desde?->format('d/m/Y H:i').' até '.$ate?->format('d/m/Y H:i'));

            $palavras = $campanha->keywordList();

            if ($palavras === []) {
                $this->warn('  sem palavras cadastradas: nada a fazer.');

                continue;
            }

            $criadas = 0;
            $jaInscritas = 0;
            $recusadas = 0;

            ConversationMessage::query()
                ->where('direction', ConversationMessageDirection::Incoming)
                ->whereNotNull('body')
                ->when($desde, fn ($query) => $query->where('received_at', '>=', $desde))
                ->when($ate, fn ($query) => $query->where('received_at', '<=', $ate))
                // Mensagem que já originou uma participação nesta campanha não
                // precisa ser reavaliada: é o filtro que faz o reprocessamento
                // de uma divulgação inteira custar pouco na segunda execução.
                ->whereNotExists(function ($sub) use ($campanha): void {
                    $sub->selectRaw('1')
                        ->from('keyword_campaign_participations')
                        ->whereColumn('keyword_campaign_participations.conversation_message_id', 'conversation_messages.id')
                        ->where('keyword_campaign_participations.keyword_campaign_id', $campanha->id);
                })
                ->orderBy('id')
                ->chunkById(500, function ($mensagens) use ($campanha, $palavras, $matcher, $registrar, $seco, &$criadas, &$jaInscritas, &$recusadas): void {
                    foreach ($mensagens as $mensagem) {
                        $palavra = $matcher->match($matcher->textoParaCasamento($mensagem), $palavras);

                        if ($palavra === null) {
                            continue;
                        }

                        if ($seco) {
                            $criadas++;

                            continue;
                        }

                        $resultado = $registrar->registrar($campanha, $mensagem, $palavra);

                        match (true) {
                            $resultado->registrou() => $criadas++,
                            $resultado->outcome === KeywordEnrollmentOutcome::JaInscrita => $jaInscritas++,
                            default => $recusadas++,
                        };
                    }
                });

            $totalCriadas += $criadas;

            if ($seco) {
                $this->info("  {$criadas} ".($criadas === 1 ? 'inscrição seria criada' : 'inscrições seriam criadas').'.');

                continue;
            }

            $this->info("  {$criadas} ".($criadas === 1 ? 'inscrição criada' : 'inscrições criadas')
                .", {$jaInscritas} já inscritas, {$recusadas} recusadas.");
        }

        if ($seco) {
            $this->comment('Execução seca: nada foi gravado.');
        }

        $this->line('Total: '.$totalCriadas.' — participações agora: '.KeywordCampaignParticipation::count().'.');

        return self::SUCCESS;
    }
}
