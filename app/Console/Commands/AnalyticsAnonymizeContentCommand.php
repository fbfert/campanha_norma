<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Services\Analytics\DailyMetricBuilder;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Anonimizacao de conteudo conversacional.
 *
 * Atende dois casos: retencao por politica (conteudo mais antigo que N dias) e
 * pedido de titular sobre os proprios dados (por contato).
 *
 * O que sai: texto da mensagem, nome e telefone gravados no instantaneo da
 * mensagem, e os campos livres do insight. O que fica: a linha, as datas, o
 * tema classificado e as contagens. A distincao e proposital — apagar a linha
 * inteira quebraria a integridade referencial e faria os agregados historicos
 * mudarem de valor sem explicacao, enquanto esvaziar o conteudo preserva a
 * estatistica e elimina o que identifica.
 *
 * A auditoria da propria execucao e preservada, porque um apagamento sem
 * registro de que aconteceu e indistinguivel de perda de dado.
 */
class AnalyticsAnonymizeContentCommand extends Command
{
    protected $signature = 'analytics:anonymize
        {--contact= : Anonimiza todo o conteudo de um contato}
        {--before= : Anonimiza conteudo anterior a esta data (AAAA-MM-DD)}
        {--retention : Usa a retencao configurada em analytics.content_retention_days}
        {--dry-run : Apenas relata o que seria afetado}';

    protected $description = 'Anonimiza conteudo de mensagens e insights preservando contagens e auditoria.';

    public function handle(SystemSettingService $settings, DailyMetricBuilder $builder, AuditLogger $audit): int
    {
        $contactId = $this->option('contact') ? (int) $this->option('contact') : null;
        $before = $this->resolveBefore($settings);
        $dryRun = (bool) $this->option('dry-run');

        if ($contactId === null && $before === null) {
            $this->error('Informe --contact, --before ou --retention. Sem escopo, nada e feito.');

            return self::FAILURE;
        }

        if ($contactId !== null && ! Contact::query()->whereKey($contactId)->exists()) {
            $this->error("Contato {$contactId} nao existe.");

            return self::FAILURE;
        }

        $messages = ConversationMessage::query()
            ->when($contactId, fn ($query) => $query->where('contact_id', $contactId))
            ->when($before, fn ($query) => $query->where('created_at', '<', $before))
            ->whereNotNull('body');

        $insights = ConversationInsight::query()
            ->when($contactId, fn ($query) => $query->where('contact_id', $contactId))
            ->when($before, fn ($query) => $query->where('created_at', '<', $before));

        $messageCount = (clone $messages)->count();
        $insightCount = (clone $insights)->count();

        $this->line("Mensagens no escopo: {$messageCount}");
        $this->line("Insights no escopo: {$insightCount}");

        if ($dryRun) {
            $this->warn('Execucao de teste. Nada foi alterado.');

            return self::SUCCESS;
        }

        if ($messageCount === 0 && $insightCount === 0) {
            $this->info('Nada a anonimizar.');

            return self::SUCCESS;
        }

        $days = $this->affectedDays(clone $messages, clone $insights);

        (clone $messages)->update([
            'body' => null,
            'sender_name_snapshot' => null,
            'sender_phone_snapshot' => null,
        ]);

        (clone $insights)->update([
            'summary' => null,
            'identified_problem' => null,
            'suggested_action' => null,
            'desired_result' => null,
            'keywords' => null,
            'locality_text' => null,
            'question_snapshot' => null,
        ]);

        foreach ($days as $day) {
            $builder->rebuildDay(Carbon::parse($day));
        }

        $audit->log(
            'analytics.content_anonymized',
            'Conteudo conversacional anonimizado.',
            null,
            null,
            [
                'contato' => $contactId,
                'anterior_a' => $before?->toDateString(),
                'mensagens' => $messageCount,
                'insights' => $insightCount,
                'dias_reprocessados' => count($days),
            ],
        );

        $this->info("Anonimizados {$messageCount} mensagem(ns) e {$insightCount} insight(s). ".count($days).' dia(s) reprocessado(s).');

        return self::SUCCESS;
    }

    private function resolveBefore(SystemSettingService $settings): ?Carbon
    {
        if ($this->option('before')) {
            return Carbon::parse((string) $this->option('before'))->startOfDay();
        }

        if (! $this->option('retention')) {
            return null;
        }

        $days = (int) $settings->get('analytics.content_retention_days', 0);

        if ($days <= 0) {
            $this->warn('A retencao de conteudo esta desligada (zero dias). Nada sera anonimizado.');

            return null;
        }

        return now()->subDays($days)->startOfDay();
    }

    /**
     * Dias que precisam ter os agregados recalculados.
     *
     * @return array<int, string>
     */
    private function affectedDays($messages, $insights): array
    {
        $days = collect()
            ->merge($messages->selectRaw('date(created_at) as day')->distinct()->pluck('day'))
            ->merge($insights->selectRaw('date(created_at) as day')->distinct()->pluck('day'))
            ->filter()
            ->map(fn ($day): string => (string) $day)
            ->unique()
            ->values();

        return $days->all();
    }
}
