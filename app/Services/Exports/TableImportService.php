<?php

namespace App\Services\Exports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Leitura de planilha para as telas de configuração.
 *
 * O par do `TableExportService`: lê de volta o mesmo CSV ou XLSX que ele
 * escreve, o que faz da própria exportação o modelo de importação — não existe
 * um "modelo" separado para sair de sincronia com o que o sistema aceita.
 *
 * Não lê SQL, e isso e deliberado. Um arquivo `.sql` enviado por formulário e
 * executado contra o banco e execução de comando arbitrário: quem consegue
 * enviar o arquivo passa a poder ler, alterar ou apagar qualquer tabela. A
 * exportação em SQL existe para alguém levar ao banco de destino
 * conscientemente, com as próprias credenciais, depois de ler o arquivo.
 *
 * O arquivo enviado fica guardado entre a conferência e a confirmação. Sem isso
 * seria preciso enviar duas vezes, e a segunda poderia ser outro arquivo — a
 * pessoa confirmaria uma coisa e gravaria outra.
 */
class TableImportService
{
    private const DIRECTORY = 'imports/configuracao';

    /** Formatos que a leitura entende. */
    public const ACCEPTED = ['csv', 'txt', 'xlsx', 'md', 'markdown'];

    /**
     * Guarda o arquivo e devolve o identificador para recupera-lo.
     */
    public function stash(UploadedFile $file): string
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ACCEPTED, true)) {
            throw ValidationException::withMessages([
                'file' => 'Envie um arquivo CSV, XLSX ou Markdown. Arquivo SQL não e importado pela tela.',
            ]);
        }

        $token = (string) Str::uuid();
        $file->storeAs(self::DIRECTORY, $token.'.'.$extension, 'local');

        return $token.'.'.$extension;
    }

    /**
     * Lê o arquivo guardado como linhas com chave de cabeçalho.
     *
     * O cabeçalho vira identificador (`Política de uso` → `politica_de_uso`),
     * então a planilha pode ser editada com acento e maiúscula sem quebrar.
     *
     * @return list<array{linha: int, dados: array<string, string>}>
     */
    public function read(string $stored): array
    {
        $path = Storage::disk('local')->path(self::DIRECTORY.'/'.basename($stored));

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo enviado não esta mais disponível. Envie novamente.',
            ]);
        }

        if (preg_match('/\.(md|markdown)$/i', $path) === 1) {
            return $this->readMarkdown($path);
        }

        $reader = str_ends_with(Str::lower($path), '.xlsx') ? new XlsxReader : new CsvReader;
        $reader->open($path);

        $headers = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $index => $row) {
                $values = array_map(static fn ($value): string => trim((string) $value), $row->toArray());

                if ($index === 1) {
                    $headers = array_map(static fn (string $value): string => Str::slug($value, '_'), $values);

                    continue;
                }

                if (implode('', $values) === '') {
                    continue;
                }

                $data = [];

                foreach ($headers as $position => $header) {
                    if ($header !== '') {
                        $data[$header] = $values[$position] ?? '';
                    }
                }

                $rows[] = ['linha' => $index, 'dados' => $data];
            }

            // Somente a primeira aba. Uma planilha com abas de rascunho ao lado
            // não deve gravar o rascunho.
            break;
        }

        $reader->close();

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo não tem nenhuma linha além do cabeçalho.',
            ]);
        }

        return $rows;
    }

    /**
     * Lê a tabela de um arquivo Markdown.
     *
     * Aceita o arquivo inteiro, e não so a tabela: linhas que não sejam de
     * tabela são puladas. Assim funciona tanto o arquivo que a exportação gera
     * quanto uma tabela colada no meio de um documento com títulos e parágrafos
     * ao redor — que e como a tabela costuma voltar depois de alguém revisar.
     *
     * A separação de colunas ignora a barra escapada: `\|` e conteúdo, e não
     * divisão. Os sinônimos dos temas são separados por barra, então errar isso
     * partiria cada tema em dezenas de colunas.
     *
     * @return list<array{linha: int, dados: array<string, string>}>
     */
    private function readMarkdown(string $path): array
    {
        $headers = [];
        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|') || ! str_ends_with($line, '|')) {
                continue;
            }

            // A linha de separação (`| --- | --- |`) não e dado.
            if (preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                continue;
            }

            $cells = array_map(
                $this->unescapeMarkdown(...),
                preg_split('/(?<!\\\\)\|/', mb_substr($line, 1, -1)) ?: []
            );

            if ($headers === []) {
                $headers = array_map(static fn (string $value): string => Str::slug($value, '_'), $cells);

                continue;
            }

            $data = [];

            foreach ($headers as $position => $header) {
                if ($header !== '') {
                    $data[$header] = $cells[$position] ?? '';
                }
            }

            $rows[] = ['linha' => $index + 1, 'dados' => $data];
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Nenhuma tabela Markdown encontrada no arquivo, ou ela so tem cabeçalho.',
            ]);
        }

        return $rows;
    }

    /**
     * Desfaz os escapes que a exportação aplica.
     *
     * O `&lt;` volta a ser `<`: sem isso, um documento reimportado acumularia
     * um escape a cada volta. O texto que entra continua sendo texto — quem
     * escapa na hora de mostrar e a camada de template.
     */
    private function unescapeMarkdown(string $value): string
    {
        return trim(str_replace(
            ['\\|', '&lt;', '\\\\'],
            ['|', '<', '\\'],
            trim($value)
        ));
    }

    public function discard(string $stored): void
    {
        Storage::disk('local')->delete(self::DIRECTORY.'/'.basename($stored));
    }
}
