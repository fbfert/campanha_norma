<?php

namespace App\Services\Analytics;

use App\Services\SystemSettingService;

/**
 * Supressão de célula agregada com poucos registros.
 *
 * Numa cidade com três respondentes, "3 pessoas reclamaram de saúde" e uma
 * frase sobre três pessoas que qualquer um que conheca a cidade consegue
 * apontar. O número e verdadeiro e mesmo assim identifica.
 *
 * A supressão acontece aqui, no serviço, e nunca na view. Uma proteção que
 * depende de a tela lembrar de aplicar e uma proteção que um dia falha, e a
 * tela que esquecer será justamente a nova.
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
     * Devolve a contagem ou `null` quando ela esta abaixo do mínimo.
     *
     * Zero passa intacto: "nenhuma resposta" não identifica ninguém, e
     * transformar zero em suprimido esconderia ausência de dado, que e
     * informação legítima e frequentemente a mais importante.
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
     * Aplica a supressão a uma lista de linhas agregadas.
     *
     * Linha suprimida permanece na lista com a contagem nula e a marca
     * `suppressed`. Remove-la faria a soma das linhas visíveis não bater com o
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
