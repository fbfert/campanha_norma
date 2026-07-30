<?php

namespace App\Services\Reports;

/**
 * Neutralizacao de injecao de formula em planilha.
 *
 * Uma celula que comeca com `=`, `+`, `-` ou `@` e interpretada como formula
 * por Excel, LibreOffice e Google Sheets. Como o conteudo exportado inclui
 * texto escrito por terceiros — mensagem recebida de um cidadao, nome de
 * contato importado de planilha alheia — quem escreve o texto decide o que a
 * planilha executa quando alguem da equipe abrir o arquivo.
 *
 * A defesa e prefixar com aspa simples, que toda planilha entende como "isto e
 * texto". O valor continua legivel; deixa de ser executavel.
 *
 * Tabulacao e retorno de carro entram na lista porque algumas planilhas os
 * ignoram no inicio da celula e passam a avaliar o caractere seguinte.
 */
class SpreadsheetValueSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Sanitiza um valor isolado. Numeros e nulos passam intactos: o risco esta
     * em texto, e converter numero para texto quebraria soma na planilha.
     */
    public function value(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true)
            ? "'".$value
            : $value;
    }

    /**
     * Sanitiza uma linha inteira, preservando as chaves.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    public function row(array $row): array
    {
        return array_map(fn (mixed $value): mixed => $this->value($value), $row);
    }
}
