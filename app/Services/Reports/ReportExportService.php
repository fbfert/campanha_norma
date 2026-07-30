<?php

namespace App\Services\Reports;

use App\Enums\ReportExportStatus;
use App\Jobs\ProcessReportExportJob;
use App\Models\ReportExport;
use App\Models\User;
use App\Queries\Histories\MessageHistoryQuery;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Exportacao de historico de mensagens.
 *
 * Etapa 9E: as celulas passam pelo sanitizador antes de serem escritas. O
 * conteudo exportado inclui mensagem recebida de terceiros, e uma mensagem que
 * comeca com `=` virava formula ao abrir a planilha. E correcao de
 * vulnerabilidade existente, nao mudanca de escopo.
 */
class ReportExportService
{
    public const MESSAGE_COLUMNS = ['lote', 'posicao', 'nome', 'telefone', 'email', 'cidade', 'estado', 'mensagem', 'status', 'tentativas', 'data_de_envio', 'data_de_falha', 'erro', 'criador_do_lote', 'modelo', 'versao', 'provedor', 'identificacao_externa'];

    public function __construct(private readonly MessageHistoryQuery $history, private readonly SystemSettingService $settings, private readonly AuditLogger $audit, private readonly SpreadsheetValueSanitizer $sanitizer) {}

    public function request(User $user, string $type, string $format, array $filters = [], ?array $columns = null): ReportExport
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';
        $columns ??= self::MESSAGE_COLUMNS;
        $maxRows = (int) $this->settings->get('reports.maximum_export_rows', 100000);
        $query = $this->query($type, $filters);
        $rows = min((clone $query)->count(), $maxRows);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => $type,
            'format' => $format,
            'status' => ReportExportStatus::Pending,
            'filters' => $filters,
            'columns' => $columns,
            'total_rows' => $rows,
            'expires_at' => now()->addHours((int) $this->settings->get('reports.export_expiration_hours', 24)),
        ]);

        if ($rows > (int) $this->settings->get('reports.synchronous_export_max_rows', 1000)) {
            ProcessReportExportJob::dispatch($export->id)->onQueue('whatsapp-maintenance');
        } else {
            $this->process($export);
        }

        $this->audit->log('report.export_requested', 'Exportacao de relatorio solicitada.', $export, null, ['report_type' => $type, 'format' => $format, 'total_rows' => $rows], $user);

        return $export->refresh();
    }

    public function process(ReportExport $export): ReportExport
    {
        try {
            $export->update(['status' => ReportExportStatus::Processing, 'started_at' => now()]);
            Storage::disk('local')->makeDirectory('report-exports');
            $path = 'report-exports/report-'.$export->id.'-'.now()->format('YmdHis').'.'.$export->format;
            $fullPath = Storage::disk('local')->path($path);
            $writer = $export->format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile($fullPath);
            $writer->addRow(Row::fromValues($this->sanitizer->row($export->columns ?? self::MESSAGE_COLUMNS)));

            $count = 0;
            $this->query($export->report_type, $export->filters ?? [])->chunkById(500, function ($rows) use ($writer, $export, &$count): void {
                foreach ($rows as $recipient) {
                    $writer->addRow(Row::fromValues($this->sanitizer->row($this->row($recipient, $export->columns ?? self::MESSAGE_COLUMNS))));
                    $count++;
                }
            });

            $writer->close();
            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_path' => $path,
                'file_size' => file_exists($fullPath) ? filesize($fullPath) : null,
                'total_rows' => $count,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $export->update(['status' => ReportExportStatus::Failed, 'finished_at' => now(), 'error_code' => 'EXPORT_FAILED', 'error_message' => 'Falha ao gerar exportacao.']);
        }

        return $export->refresh();
    }

    private function query(string $type, array $filters)
    {
        return $this->history->build($filters);
    }

    private function row($recipient, array $columns): array
    {
        $values = [
            'lote' => $recipient->batch?->name,
            'posicao' => $recipient->random_position,
            'nome' => $recipient->contact_name_snapshot,
            'telefone' => $recipient->contact_phone_snapshot,
            'email' => $recipient->contact_email_snapshot,
            'cidade' => $recipient->contact_city_snapshot,
            'estado' => $recipient->contact_state_snapshot,
            'mensagem' => $recipient->rendered_message,
            'status' => $recipient->processing_status?->value,
            'tentativas' => $recipient->attempts,
            'data_de_envio' => $recipient->sent_at?->format('d/m/Y H:i'),
            'data_de_falha' => $recipient->failed_at?->format('d/m/Y H:i'),
            'erro' => $recipient->error_code,
            'criador_do_lote' => $recipient->batch?->creator?->name,
            'modelo' => $recipient->batch?->template?->name,
            'versao' => $recipient->batch?->message_template_version,
            'provedor' => config('whatsapp.provider', 'web'),
            'identificacao_externa' => $recipient->external_message_id,
        ];

        return collect($columns)->map(fn (string $column): mixed => $values[$column] ?? null)->all();
    }
}
