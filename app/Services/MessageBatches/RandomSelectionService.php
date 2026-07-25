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
