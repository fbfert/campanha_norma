<?php

namespace App\Services\Analytics;

use App\Services\SystemSettingService;

/**
 * Supressao de celula agregada com poucos registros.
 *
 * Numa cidade com tres respondentes, "3 pessoas reclamaram de saude" e uma
 * frase sobre tres pessoas que qualquer um que conheca a cidade consegue
 * apontar. O numero e verdadeiro e mesmo assim identifica.
 *
 * A supressao acontece aqui, no servico, e nunca na view. Uma protecao que
 * depende de a tela lembrar de aplicar e uma protecao que um dia falha, e a
 * tela que esquecer sera justamente a nova.
 */
class SmallGroupSuppressor
{
    public const SUPPRESSED = null;

    public function __construct(private readonly SystemSettingService $settings) {}

    public function minimum(): int
    {
        return max(1, (int) $this->settings->get('analytics.minimum_cell_size', 5));
    }

    /**
     * Devolve a contagem ou `null` quando ela esta abaixo do minimo.
     *
     * Zero passa intacto: "nenhuma resposta" nao identifica ninguem, e
     * transformar zero em suprimido esconderia ausencia de dado, que e
     * informacao legitima e frequentemente a mais importante.
     */
    public function count(int $value): ?int
    {
        if ($value === 0) {
            return 0;
        }

        return $value >= $this->minimum() ? $value : self::SUPPRESSED;
    }

    public function isSuppressed(int $value): bool
    {
        return $this->count($value) === self::SUPPRESSED;
    }

    /**
     * Aplica a supressao a uma lista de linhas agregadas.
     *
     * Linha suprimida permanece na lista com a contagem nula e a marca
     * `suppressed`. Remove-la faria a soma das linhas visiveis nao bater com o
     * total, e quem lesse concluiria que ha registros faltando.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function rows(iterable $rows, string $countKey = 'total'): array
    {
        $result = [];

        foreach ($rows as $row) {
            $value = (int) ($row[$countKey] ?? 0);
            $suppressed = $this->isSuppressed($value);

            $row[$countKey] = $suppressed ? self::SUPPRESSED : $value;
            $row['suppressed'] = $suppressed;

            $result[] = $row;
        }

        return $result;
    }
}
