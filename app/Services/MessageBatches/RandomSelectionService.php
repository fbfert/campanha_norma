<?php

namespace App\Services\MessageBatches;

class RandomSelectionService
{
    public function seed(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function sample(array $ids, int $quantity, ?string $seed = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $this->shuffle($ids, $seed ?? $this->seed());

        return array_slice($ids, 0, $quantity);
    }

    public function positions(array $ids, ?string $seed = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $this->shuffle($ids, $seed ?? $this->seed());

        return array_flip(array_map('intval', $ids));
    }

    /**
     * Sorteio auditável: usa a semente inteira e é conferível de fora.
     *
     * `shuffle()`, logo abaixo, deriva o estado do gerador com
     * `mt_srand(abs(crc32($seed)))`. Isso reduz a semente a 32 bits e joga fora
     * entropia. Para escolher destinatário de lote é irrelevante — ninguém
     * confere um lote. Para um sorteio cuja única defesa é a semente
     * registrada, a semente registrada precisa ser a semente de verdade.
     *
     * A ordenação aqui é por `sha256(semente|id)`, e não por gerador
     * pseudoaleatório. A diferença que importa não é estatística: é que
     * qualquer pessoa, em qualquer linguagem, refaz esta conta com a semente e
     * a lista publicadas e chega à mesma ordem. Reproduzir `mt_rand` exigiria
     * confiar na implementação do PHP e na versão dele.
     *
     * O caminho antigo continua onde estava, sem alteração de comportamento
     * observável: um lote sorteado ontem com a mesma semente continua dando o
     * mesmo resultado.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function auditableSample(array $ids, int $quantity, string $seed): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        usort($ids, function (int $a, int $b) use ($seed): int {
            $chaveA = hash('sha256', $seed.'|'.$a);
            $chaveB = hash('sha256', $seed.'|'.$b);

            // O identificador desempata. Sem isso, dois hashes iguais — que não
            // acontecem na prática — deixariam a ordem indefinida, e ordem
            // indefinida é o oposto de sorteio reproduzível.
            return $chaveA === $chaveB ? $a <=> $b : strcmp($chaveA, $chaveB);
        });

        return array_slice($ids, 0, max(0, $quantity));
    }

    private function shuffle(array &$ids, string $seed): void
    {
        mt_srand(abs(crc32($seed)));
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        mt_srand();
    }
}
