<?php

namespace App\Data\Knowledge;

readonly class ProviderIndexResult
{
    /**
     * @param  array<int, string|null>  $externalChunkIds  na ordem dos trechos
     */
    public function __construct(
        public int $indexedChunks,
        public ?string $providerFileId = null,
        public array $externalChunkIds = [],
    ) {}
}
