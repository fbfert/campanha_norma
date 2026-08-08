<?php

namespace App\Console\Commands;

use App\Enums\MessageBatchRecipientEligibility;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Services\MessageBatches\ContactEligibilityService;
use App\Services\Placeholders\PlaceholderParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Converte um lote de texto sorteado para um texto só.
 *
 * O sistema sorteava até dez modelos por lote, um por destinatário. Isso saiu
 * da criação quando o envio passou a depender de template aprovado pela Meta:
 * lá cada texto é uma aprovação separada, e o que viaja são as variáveis em
 * ordem — ordem que o sorteio deixava indefinida, porque
 * `placeholders_snapshot` guardava a união das variáveis de todos os modelos.
 *
 * Este comando existe para os lotes que ficaram preparados antes daquela
 * mudança e ainda não enviaram nada. Ele reescreve o corpo, o instantâneo de
 * variáveis e a mensagem de cada destinatário.
 *
 * Recusa lote em que alguém já recebeu. Reescrever a mensagem de quem já leu
 * apagaria o registro do que foi de fato enviado, e o histórico da conversa
 * passaria a mostrar um texto que a pessoa nunca viu.
 */
class BatchesUseSingleTemplateCommand extends Command
{
    protected $signature = 'message-batches:single-template
        {batch* : Ids dos lotes a converter}
        {--template= : Id do modelo que passa a valer para todos}
        {--aplicar : Grava. Sem esta opção o comando apenas mostra o que faria}';

    protected $description = 'Faz um lote preparado usar um texto só, no lugar do sorteio entre modelos.';

    public function handle(ContactEligibilityService $eligibility, PlaceholderParserService $parser): int
    {
        $template = MessageTemplate::find((int) $this->option('template'));

        if (! $template) {
            $this->error('Informe --template com o id de um modelo existente.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $variaveis = $parser->parse($template->body)['valid'];

        $this->line('Modelo: '.$template->name.' v'.$template->version);
        $this->line('Texto:  '.$template->body);
        $this->line('Variáveis: '.implode(', ', $variaveis));
        $this->newLine();

        foreach ($this->args() as $id) {
            $batch = MessageBatch::find($id);

            if (! $batch) {
                $this->error('Lote '.$id.' não existe.');

                return self::FAILURE;
            }

            $enviados = $batch->recipients()
                ->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('external_message_id'))
                ->count();

            if ($enviados > 0) {
                $this->error('Lote '.$id.' já enviou para '.$enviados.' destinatários e não pode ser reescrito.');

                return self::FAILURE;
            }

            $this->convert($batch, $template, $variaveis, $eligibility, $aplicar);
        }

        if (! $aplicar) {
            $this->newLine();
            $this->warn('Nada foi gravado. Repita com --aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $variaveis
     */
    private function convert(MessageBatch $batch, MessageTemplate $template, array $variaveis, ContactEligibilityService $eligibility, bool $aplicar): void
    {
        $aptos = 0;
        $inaptos = 0;

        DB::transaction(function () use ($batch, $template, $variaveis, $eligibility, $aplicar, &$aptos, &$inaptos): void {
            foreach ($batch->recipients()->with('contact')->get() as $recipient) {
                $contact = $recipient->contact;

                if (! $contact) {
                    $inaptos++;

                    continue;
                }

                $resultado = $eligibility->evaluate($contact, $template->body);
                $resultado['eligible'] ? $aptos++ : $inaptos++;

                if (! $aplicar) {
                    continue;
                }

                $recipient->forceFill([
                    'message_template_id' => $template->id,
                    'message_template_version' => $template->version,
                    'message_template_name_snapshot' => $template->name,
                    'eligibility_status' => $resultado['eligible'] ? MessageBatchRecipientEligibility::Eligible : MessageBatchRecipientEligibility::Excluded,
                    'ineligibility_reason' => $resultado['reason'],
                    'rendered_message' => $resultado['eligible'] ? $resultado['rendered_message'] : null,
                    'render_errors' => $resultado['render_errors'],
                ])->save();
            }

            if ($aplicar) {
                $batch->forceFill([
                    'is_campaign' => false,
                    'campaign_templates_snapshot' => null,
                    'message_template_id' => $template->id,
                    'message_template_version' => $template->version,
                    'message_body_snapshot' => $template->body,
                    'placeholders_snapshot' => $variaveis,
                    'eligible_total' => $batch->recipients()->where('eligibility_status', 'eligible')->count(),
                    'ineligible_total' => $batch->recipients()->where('eligibility_status', '!=', 'eligible')->count(),
                ])->save();
            }
        });

        $this->info('Lote '.$batch->id.' - '.$batch->name);
        $this->line('   '.$aptos.' aptos, '.$inaptos.' inaptos'.($aplicar ? ' (gravado)' : ' (simulação)'));
    }

    /** @return array<int, int> */
    private function args(): array
    {
        return array_map('intval', (array) $this->argument('batch'));
    }
}
