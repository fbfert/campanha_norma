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

    /** Formatos tabulares oferecidos nas telas. */
    public const FORMATS = ['csv', 'xlsx', 'markdown'];

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
        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';

        if ($format === 'markdown') {
            return $this->markdown($filename, $headers, $rows, $auditAction, $auditDescription);
        }

        $path = $this->path($filename, $format);

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

        return $this->respond($path, $filename, $format, $count, $auditAction, $auditDescription);
    }

    /**
     * Tabela em Markdown, para colar em documentação ou em uma issue.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    private function markdown(
        string $filename,
        array $headers,
        iterable $rows,
        string $auditAction,
        string $auditDescription,
    ): BinaryFileResponse {
        $path = $this->path($filename, 'md');
        $handle = fopen($path, 'w');

        fwrite($handle, '| '.implode(' | ', array_map($this->cell(...), $headers)).' |'.PHP_EOL);
        fwrite($handle, '|'.str_repeat(' --- |', count($headers)).PHP_EOL);

        $count = 0;

        foreach ($rows as $row) {
            fwrite($handle, '| '.implode(' | ', array_map($this->cell(...), $row)).' |'.PHP_EOL);
            $count++;
        }

        fclose($handle);

        return $this->respond($path, $filename, 'md', $count, $auditAction, $auditDescription);
    }

    /**
     * Uma célula de tabela Markdown.
     *
     * Três escapes, cada um por um motivo diferente. A barra vertical fecharia
     * a coluna no meio do texto e desalinharia a tabela inteira. A quebra de
     * linha encerraria a linha da tabela. E o sinal de menor vira `&lt;` porque
     * Markdown aceita HTML embutido: um tema chamado `<img onerror=...>` viraria
     * marcação viva no dia em que alguém publicasse esta tabela numa página.
     */
    private function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'sim' : 'não',
            default => (string) $value,
        };

        return trim(str_replace(
            ['\\', '|', "\r\n", "\n", "\r", '<'],
            ['\\\\', '\\|', ' ', ' ', ' ', '&lt;'],
            $text
        ));
    }

    private function path(string $filename, string $extension): string
    {
        return storage_path('app/private/'.$filename.'-'.now()->format('YmdHis').'.'.$extension);
    }

    private function respond(
        string $path,
        string $filename,
        string $extension,
        int $count,
        string $auditAction,
        string $auditDescription,
    ): BinaryFileResponse {
        $this->audit->log($auditAction, $auditDescription, null, null, [
            'format' => $extension,
            'count' => $count,
        ]);

        return response()
            ->download($path, $filename.'.'.$extension)
            ->deleteFileAfterSend(true);
    }
}
