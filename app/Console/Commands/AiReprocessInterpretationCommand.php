<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Jobs\InterpretConversationMessageJob;
use App\Models\ConversationMessage;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reprocessamento seguro da interpretação.
 *
 * Exige ao menos um filtro e pede confirmação acima do limite configurado.
 * Não existe forma de reprocessar tudo sem confirmação explícita.
 */
class AiReprocessInterpretationCommand extends Command
{
    protected $signature = 'ai:reprocess
        {--message= : ID de uma mensagem específica}
        {--conversation= : ID de uma conversa}
        {--from= : Data inicial no formato Y-m-d}
        {--to= : Data final no formato Y-m-d}
        {--limit=500 : Teto de mensagens despachadas}
        {--dry-run : Apenas conta, sem despachar}';

    protected $description = 'Reprocessa a interpretação por IA de mensagens recebidas, por identificador ou período.';

    public function handle(SystemSettingService $settings): int
    {
        if (! $this->hasFilter()) {
            $this->error('Informe ao menos um filtro: --message, --conversation, --from ou --to.');
            $this->line('Reprocessar toda a base sem filtro não e permitido.');

            return self::FAILURE;
        }

        $query = $this->query();
        $total = (int) $query->count();

        if ($total === 0) {
            $this->info('Nenhuma mensagem encontrada para os filtros informados.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $threshold = max(1, (int) $settings->get('ai.reprocess_confirm_threshold', 50));

        $this->line("Mensagens encontradas: {$total}.");

        if ($total > $limit) {
            $this->warn("O teto de {$limit} será aplicado. Ajuste --limit para incluir mais.");
        }

        if ($this->option('dry-run')) {
            $this->info('Execução apenas de contagem. Nada foi despachado.');

            return self::SUCCESS;
        }

        $dispatchCount = min($total, $limit);

        if ($dispatchCount > $threshold && ! $this->confirm("Confirmar o despacho de {$dispatchCount} interpretações?", false)) {
            $this->info('Operação cancelada.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        $query->orderBy('id')->limit($limit)->each(function (ConversationMessage $message) use (&$dispatched): void {
            InterpretConversationMessageJob::dispatch($message->id);
            $dispatched++;
        });

        $this->info("Interpretações enfileiradas: {$dispatched}.");

        return self::SUCCESS;
    }

    private function hasFilter(): bool
    {
        return $this->option('message') || $this->option('conversation') || $this->option('from') || $this->option('to');
    }

    private function query(): Builder
    {
        return ConversationMessage::query()
            ->where('direction', ConversationMessageDirection::Incoming->value)
            ->whereNotNull('body')
            ->when($this->option('message'), fn (Builder $query) => $query->where('id', (int) $this->option('message')))
            ->when($this->option('conversation'), fn (Builder $query) => $query->where('conversation_id', (int) $this->option('conversation')))
            ->when($this->option('from'), fn (Builder $query) => $query->whereDate('created_at', '>=', $this->option('from')))
            ->when($this->option('to'), fn (Builder $query) => $query->whereDate('created_at', '<=', $this->option('to')));
    }
}
