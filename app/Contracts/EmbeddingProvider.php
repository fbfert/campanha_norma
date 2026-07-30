<?php

namespace App\Contracts;

use App\Exceptions\Knowledge\KnowledgeProviderException;

interface EmbeddingProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * Dimensao esperada dos vetores deste provedor. Persistida junto de cada
     * embedding para que trocar de modelo nao corrompa leitura.
     */
    public function dimensions(): int;

    public function isConfigured(): bool;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> vetores na mesma ordem dos textos
     *
     * @throws KnowledgeProviderException
     */
    public function embed(array $texts): array;
}
