<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

/**
 * Anonimização aplicada a exportação detalhada.
 *
 * Três operações, cada uma com um motivo distinto:
 *
 * - nome sai por completo, porque nome não tem uso analítico;
 * - telefone fica mascarado nos quatro últimos digitos, o bastante para a
 *   equipe conferir um caso específico contra a caixa de entrada e insuficiente
 *   para discar;
 * - identificador do contato vira pseudônimo derivado com sal próprio da
 *   exportação.
 *
 * O sal por exportação e o ponto que sustenta o resto. Com sal fixo, duas
 * exportações de períodos diferentes teriam o mesmo pseudônimo para a mesma
 * pessoa, e cruzar as duas reconstruiria o histórico dela. Com sal por
 * exportação, cada arquivo e um universo fechado: da para agrupar respostas
 * dentro dele e não da para ligar nada fora dele.
 */
class ExportAnonymizer
{
    public function newSalt(): string
    {
        return Str::random(48);
    }

    /**
     * Pseudônimo estável dentro de uma exportação e sem relação com qualquer
     * outra. Irreversível por construção: não existe caminho de volta do hash
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
     * Telefone reduzido aos quatro últimos digitos.
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
     * o cabeçalho da planilha continue estável entre exportações.
     */
    public function removeName(?string $name): string
    {
        return '';
    }
}
