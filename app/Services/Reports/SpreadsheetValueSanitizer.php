<?php

namespace App\Services\Reports;

/**
 * Neutralização de injeção de fórmula em planilha.
 *
 * Uma célula que começa com `=`, `+`, `-` ou `@` e interpretada como fórmula
 * por Excel, LibreOffice e Google Sheets. Como o conteúdo exportado inclui
 * texto escrito por terceiros — mensagem recebida de um cidadão, nome de
 * contato importado de planilha alheia — quem escreve o texto decide o que a
 * planilha executa quando alguém da equipe abrir o arquivo.
 *
 * A defesa e prefixar com aspa simples, que toda planilha entende como "isto e
 * texto". O valor continua legível; deixa de ser executável.
 *
 * Tabulação e retorno de carro entram na lista porque algumas planilhas os
 * ignoram no início da célula e passam a avaliar o caractere seguinte.
 */
class SpreadsheetValueSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Sanitiza um valor isolado. Números e nulos passam intactos: o risco esta
     * em texto, e converter número para texto quebraria soma na planilha.
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
