<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

/**
 * Anonimizacao aplicada a exportacao detalhada.
 *
 * Tres operacoes, cada uma com um motivo distinto:
 *
 * - nome sai por completo, porque nome nao tem uso analitico;
 * - telefone fica mascarado nos quatro ultimos digitos, o bastante para a
 *   equipe conferir um caso especifico contra a caixa de entrada e insuficiente
 *   para discar;
 * - identificador do contato vira pseudonimo derivado com sal proprio da
 *   exportacao.
 *
 * O sal por exportacao e o ponto que sustenta o resto. Com sal fixo, duas
 * exportacoes de periodos diferentes teriam o mesmo pseudonimo para a mesma
 * pessoa, e cruzar as duas reconstruiria o historico dela. Com sal por
 * exportacao, cada arquivo e um universo fechado: da para agrupar respostas
 * dentro dele e nao da para ligar nada fora dele.
 */
class ExportAnonymizer
{
    public function newSalt(): string
    {
        return Str::random(48);
    }

    /**
     * Pseudonimo estavel dentro de uma exportacao e sem relacao com qualquer
     * outra. Irreversivel por construcao: nao existe caminho de volta do hash
     * ao identificador, mesmo de posse do sal.
     */
    public function pseudonym(string $salt, int|string|null $contactId): string
    {
        if ($contactId === null || $contactId === '') {
            return 'sem-contato';
        }

        return substr(hash('sha256', $salt.'|'.$contactId), 0, 16);
    }

    /**
     * Telefone reduzido aos quatro ultimos digitos.
     */
    public function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return mb_strlen($digits) <= 4
            ? str_repeat('*', mb_strlen($digits))
            : str_repeat('*', 4).mb_substr($digits, -4);
    }

    /**
     * Nome nunca sai. Devolve string vazia em vez de remover a coluna para que
     * o cabecalho da planilha continue estavel entre exportacoes.
     */
    public function removeName(?string $name): string
    {
        return '';
    }
}
