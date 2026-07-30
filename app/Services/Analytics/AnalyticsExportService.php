<?php

namespace App\Services\Analytics;

use App\Enums\ReportExportStatus;
use App\Models\ConversationInsight;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reports\SpreadsheetValueSanitizer;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Exportação analítica.
 *
 * Dois escopos com regras diferentes:
 *
 * - **agregado** e o padrão. Contagem, rótulo, taxa e período. Não carrega
 *   identificação de ninguém, e por isso basta a permissão de exportar
 *   agregado.
 * - **detalhado** carrega o texto que as pessoas escreveram. Exige permissão
 *   elevada, finalidade escrita e passa pelo anonimizador.
 *
 * A finalidade não e validada pelo sistema. E um registro de responsabilidade,
 * não um controle técnico — vale dizer isso em voz alta para que ninguém a
 * confunda com garantia.
 */
class AnalyticsExportService
{
    public const SCOPE_AGGREGATE = 'aggregate';

    public const SCOPE_DETAILED = 'detailed';

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
        private readonly ExportAnonymizer $anonymizer,
        private readonly SpreadsheetValueSanitizer $sanitizer,
        private readonly TopicMetricsService $topics,
        private readonly DemandMetricsService $demands,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function request(User $user, string $type, string $scope, string $format, array $filters, ?string $purpose = null): ReportExport
    {
        $scope = $scope === self::SCOPE_DETAILED ? self::SCOPE_DETAILED : self::SCOPE_AGGREGATE;
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';

        $export = ReportExport::query()->create([
            'user_id' => $user->id,
            'report_type' => 'analytics.'.$type,
            'scope' => $scope,
            'purpose' => $scope === self::SCOPE_DETAILED ? $purpose : null,
            'anonymized' => true,
            'pseudonym_salt' => $scope === self::SCOPE_DETAILED ? $this->anonymizer->newSalt() : null,
            'format' => $format,
            'status' => ReportExportStatus::Pending,
            'filters' => $filters,
            'columns' => $this->columns($type, $scope),
            'total_rows' => 0,
            'expires_at' => now()->addHours((int) $this->settings->get('analytics.export_expiration_hours', 24)),
        ]);

        $this->audit->log(
            'analytics.export_requested',
            'Exportação analítica solicitada.',
            $export,
            null,
            ['tipo' => $type, 'escopo' => $scope, 'formato' => $format, 'finalidade' => $purpose, 'filtros' => $filters],
            $user,
        );

        return $this->process($export);
    }

    public function process(ReportExport $export): ReportExport
    {
        try {
            $export->update(['status' => ReportExportStatus::Processing, 'started_at' => now()]);

            Storage::disk('local')->makeDirectory('report-exports');
            $path = 'report-exports/analytics-'.$export->id.'-'.now()->format('YmdHis').'.'.$export->format;
            $full = Storage::disk('local')->path($path);

            $writer = $export->format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile($full);

            $columns = $export->columns ?? [];
            $writer->addRow(Row::fromValues($this->sanitizer->row($columns)));

            $count = 0;

            foreach ($this->rows($export) as $row) {
                $writer->addRow(Row::fromValues($this->sanitizer->row($row)));
                $count++;
            }

            $writer->close();

            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_path' => $path,
                'file_size' => file_exists($full) ? filesize($full) : null,
                'total_rows' => $count,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            $export->update([
                'status' => ReportExportStatus::Failed,
                'finished_at' => now(),
                'error_code' => 'ANALYTICS_EXPORT_FAILED',
                'error_message' => 'Falha ao gerar exportação analítica.',
            ]);
        }

        return $export->refresh();
    }

    /**
     * @return iterable<int, array<int, mixed>>
     */
    private function rows(ReportExport $export): iterable
    {
        $filters = $export->filters ?? [];
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $flowId = isset($filters['flow']) ? (int) $filters['flow'] : null;

        if ($export->scope === self::SCOPE_DETAILED) {
            return $this->detailedRows($export, $from, $to, $flowId);
        }

        return match ($export->report_type) {
            'analytics.demands' => array_map(
                fn (array $row): array => [$row['label'], $row['total'], $row['suppressed'] ? 'sim' : 'não'],
                $this->demands->problems($from, $to, $flowId, 1000),
            ),
            default => array_map(
                fn (array $row): array => [$row['name'], $row['total'], $row['average_confidence'], $row['reviewed'], $row['suppressed'] ? 'sim' : 'não'],
                $this->topics->mostMentioned($from, $to, $flowId, 1000),
            ),
        };
    }

    /**
     * Linhas detalhadas, já anonimizadas.
     *
     * @return array<int, array<int, mixed>>
     */
    private function detailedRows(ReportExport $export, Carbon $from, Carbon $to, ?int $flowId): array
    {
        $salt = (string) $export->pseudonym_salt;
        $limit = (int) $this->settings->get('analytics.maximum_export_rows', 50000);

        return ConversationInsight::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (ConversationInsight $insight): array => [
                $this->anonymizer->pseudonym($salt, $insight->contact_id),
                $insight->created_at?->format('d/m/Y H:i'),
                $this->text($insight->main_topic_raw),
                $this->text($insight->summary),
                $this->text($insight->identified_problem),
                $this->text($insight->suggested_action),
                $this->text($insight->desired_result),
                $this->text($insight->urgency),
                $this->text($insight->region),
                $insight->confidence === null ? null : (float) $insight->confidence,
                $insight->reviewed ? 'sim' : 'não',
            ])
            ->all();
    }

    /**
     * Alguns campos do insight são convertidos em enum pelo modelo e o escritor
     * de planilha não sabe o que fazer com um objeto. Converter aqui evita que
     * a exportação inteira falhe por causa de uma coluna.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * @return array<int, string>
     */
    private function columns(string $type, string $scope): array
    {
        if ($scope === self::SCOPE_DETAILED) {
            return ['pseudonimo', 'data', 'tema', 'resumo', 'problema', 'acao_sugerida', 'resultado_desejado', 'urgencia', 'regiao', 'confianca', 'revisado'];
        }

        return match ($type) {
            'demands' => ['demanda', 'quantidade', 'suprimido'],
            default => ['tema', 'quantidade', 'confianca_media', 'revisados', 'suprimido'],
        };
    }
}
