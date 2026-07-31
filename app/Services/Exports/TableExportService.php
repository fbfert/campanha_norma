<?php

namespace App\Services\Exports;

use App\Services\AuditLogger;
use App\Services\Reports\SpreadsheetValueSanitizer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportação de uma tabela simples: cabeçalho e linhas, nada mais.
 *
 * Existe para as telas de configuração — taxonomia de temas, bases de
 * conhecimento — cujo conteúdo já esta inteiro na tela. Não substitui o
 * `ReportExportService`, que trata de exportação analítica com escopo,
 * justificativa, k-anonimato e expiração; ali o que sai são dados de pessoas e
 * cada uma dessas proteções tem razão de existir.
 *
 * Duas coisas são obrigatórias e por isso ficam aqui, e não em quem chama:
 * toda célula passa pelo sanitizador de fórmula e toda exportação e registrada
 * na auditoria. Deixar isso a cargo de cada tela e garantir que uma delas
 * esqueça.
 */
class TableExportService
{
    public function __construct(
        private readonly SpreadsheetValueSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function download(
        string $filename,
        array $headers,
        iterable $rows,
        string $format,
        string $auditAction,
        string $auditDescription,
    ): BinaryFileResponse {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';
        $path = storage_path('app/private/'.$filename.'-'.now()->format('YmdHis').'.'.$format);

        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);

        // O cabeçalho também e sanitizado. Ele e escrito por nos e hoje e
        // seguro, mas uma coluna nova chamada "-saldo" passaria despercebida.
        $writer->addRow(Row::fromValues($this->sanitizer->row($headers)));

        $count = 0;

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($this->sanitizer->row($row)));
            $count++;
        }

        $writer->close();

        $this->audit->log($auditAction, $auditDescription, null, null, [
            'format' => $format,
            'count' => $count,
        ]);

        return response()
            ->download($path, $filename.'.'.$format)
            ->deleteFileAfterSend(true);
    }
}
