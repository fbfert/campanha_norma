<?php

namespace App\Services\Exports;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportação em SQL: `INSERT` prontos para recriar as linhas em outra
 * instalação.
 *
 * Serve para levar configuração de um ambiente para outro — a taxonomia
 * revisada em homologação indo para produção, por exemplo. Não serve como
 * backup: um `mysqldump` faz isso melhor, com esquema, índices e chaves.
 *
 * Três decisões que valem ser ditas em voz alta, porque um arquivo `.sql` e
 * executado sem ninguém ler:
 *
 * 1. O valor e citado pelo PDO da conexão em uso, e não concatenado a mão. Um
 *    tema chamado `'); DROP TABLE ...` precisa sair como texto, não como
 *    comando. E, como o PDO e o do banco em uso, a citação sai no dialeto certo.
 *
 * 2. Colunas de autoria e de aprovação nunca saem. Elas apontam para usuários
 *    que existem *neste* sistema; recriadas em outro, passariam a apontar para
 *    pessoas diferentes, e um registro de aprovação com o nome errado e pior do
 *    que registro nenhum.
 *
 * 3. São `INSERT` simples, e não `REPLACE` nem upsert. Rodar duas vezes falha
 *    de forma barulhenta, que e o que se quer: sobrescrever configuração em
 *    silêncio e como se perde trabalho de outra pessoa.
 */
class SqlExportService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $columns
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function download(
        string $filename,
        string $table,
        array $columns,
        iterable $rows,
        string $auditAction,
        string $auditDescription,
        string $note = '',
    ): BinaryFileResponse {
        $path = storage_path('app/private/'.$filename.'-'.now()->format('YmdHis').'.sql');
        $handle = fopen($path, 'w');

        fwrite($handle, '-- '.$table.' — gerado em '.now()->format('d/m/Y H:i').PHP_EOL);
        fwrite($handle, '-- Confira o destino antes de executar. São INSERT simples:'.PHP_EOL);
        fwrite($handle, '-- rodar duas vezes falha, e nada aqui atualiza linha existente.'.PHP_EOL);

        if ($note !== '') {
            foreach (explode("\n", $note) as $line) {
                fwrite($handle, '-- '.$line.PHP_EOL);
            }
        }

        fwrite($handle, PHP_EOL);

        $columnList = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
        $count = 0;

        foreach ($rows as $row) {
            $values = implode(', ', array_map($this->quote(...), $row));
            fwrite($handle, 'INSERT INTO `'.$table.'` ('.$columnList.') VALUES ('.$values.');'.PHP_EOL);
            $count++;
        }

        fclose($handle);

        $this->audit->log($auditAction, $auditDescription, null, null, [
            'format' => 'sql',
            'table' => $table,
            'count' => $count,
        ]);

        return response()
            ->download($path, $filename.'.sql')
            ->deleteFileAfterSend(true);
    }

    /**
     * Citação pelo PDO da conexão, e nunca por concatenação.
     *
     * Escapar a mão e onde nasce injeção: sempre falta um caso — aspa dupla,
     * barra invertida, byte nulo, codificação multibyte. O driver já sabe fazer
     * isso para o banco que esta em uso.
     */
    private function quote(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => (string) DB::connection()->getPdo()->quote((string) $value),
        };
    }
}
