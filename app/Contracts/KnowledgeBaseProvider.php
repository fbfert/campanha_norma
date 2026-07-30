<?php

namespace App\Contracts;

use App\Data\Knowledge\PreparedChunk;
use App\Data\Knowledge\ProviderIndexResult;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;

/**
 * Armazenamento da base de conhecimento.
 *
 * A interface existe para que trocar o armazenamento não toque em nenhum
 * chamador. Implementações que usam serviço externo persistem os identificadores
 * remotos nas colunas já previstas: `external_store_id`, `provider_file_id` e
 * `external_chunk_id`.
 */
interface KnowledgeBaseProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Indica se o provedor mantem um armazenamento remoto por base, que precisa
     * ser criado e removido junto com ela.
     */
    public function supportsRemoteStore(): bool;

    /**
     * @return string|null identificador externo do armazenamento, quando houver
     */
    public function createStore(KnowledgeBase $base): ?string;

    public function deleteStore(KnowledgeBase $base): void;

    /**
     * Indexa os trechos já extraidos e sanitizados de um documento.
     *
     * @param  array<int, PreparedChunk>  $chunks
     *
     * @throws KnowledgeProviderException
     */
    public function indexDocument(KnowledgeDocument $document, array $chunks): ProviderIndexResult;

    public function deleteDocument(KnowledgeDocument $document): void;

    /**
     * @return array<string, mixed> estado operacional, sem segredo
     */
    public function health(): array;
}
