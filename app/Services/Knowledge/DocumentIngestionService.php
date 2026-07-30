<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeDocumentStatus;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o arquivo, valida, verifica e enfileira a indexação.
 *
 * A validação também existe na regra de formulário. Aqui ela e a garantia: uma
 * chamada de comando ou de teste que passe por fora do formulário não pode
 * introduzir arquivo grande, tipo inesperado ou duplicata.
 */
class DocumentIngestionService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AntivirusScanner $antivirus,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function store(KnowledgeBase $base, UploadedFile $file, array $attributes, ?User $user = null): KnowledgeDocument
    {
        $this->assertAcceptable($file);

        $hash = hash_file('sha256', $file->getRealPath());

        if ($hash === false) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::FILE_MISSING);
        }

        $this->assertNotDuplicate($base, $hash);

        $disk = (string) config('knowledge.providers.local.disk');
        $directory = trim((string) config('knowledge.providers.local.directory'), '/');

        /*
         | O nome armazenado e gerado aqui, nunca derivado do que veio no upload.
         | Isso encerra path traversal na origem: não existe caminho em que o nome
         | enviado pelo usuário participe da montagem do caminho em disco.
         */
        $storedName = Str::uuid()->toString().$this->extensionSuffix($file);
        $path = $file->storeAs($directory, $storedName, $disk);

        if ($path === false) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::FILE_MISSING);
        }

        try {
            $antivirusResult = $this->antivirus->scan(Storage::disk($disk)->path($path));
        } catch (KnowledgeProviderException $exception) {
            // Arquivo suspeito ou não verificável não fica no disco.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        $document = KnowledgeDocument::create([
            'knowledge_base_id' => $base->id,
            'title' => $attributes['title'],
            'type' => $attributes['type'],
            'source' => $attributes['source'] ?? null,
            'source_url' => $attributes['source_url'] ?? null,
            'document_date' => $attributes['document_date'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'supersedes_document_id' => $attributes['supersedes_document_id'] ?? null,
            'disk' => $disk,
            'file_path' => $path,
            'original_filename' => $this->safeDisplayName($file->getClientOriginalName()),
            'mime_type' => $this->mimeOf($file),
            'file_size' => (int) $file->getSize(),
            'content_hash' => $hash,
            'status' => KnowledgeDocumentStatus::Processing,
            'version' => (int) ($attributes['version'] ?? 1),
            'antivirus_result' => $antivirusResult,
            'created_by' => $user?->id,
        ]);

        $this->audit->log('knowledge_document.uploaded', 'Documento enviado para a base de conhecimento.', $document, null, [
            'knowledge_base_id' => $base->id,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'antivirus' => $antivirusResult,
        ], $user);

        IndexKnowledgeDocumentJob::dispatch($document->id)
            ->onQueue((string) $this->settings->get('knowledge.queue', 'knowledge-indexing'));

        return $document;
    }

    /**
     * @return array<int, string>
     */
    public function acceptedMimeTypes(): array
    {
        $raw = (string) $this->settings->get(
            'knowledge.accepted_mime_types',
            'application/pdf|text/plain|text/markdown|text/html|application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => trim(mb_strtolower($item)))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    public function maxFileSizeKb(): int
    {
        return max(1, (int) $this->settings->get('knowledge.max_file_size_mb', 20)) * 1024;
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() === false || $file->getSize() > $this->maxFileSizeKb() * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Arquivo acima do tamanho máximo permitido.',
            ]);
        }

        if (! in_array($this->mimeOf($file), $this->acceptedMimeTypes(), true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipo de arquivo não aceito na base de conhecimento.',
            ]);
        }
    }

    private function assertNotDuplicate(KnowledgeBase $base, string $hash): void
    {
        $existing = KnowledgeDocument::query()
            ->where('knowledge_base_id', $base->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'file' => 'Este arquivo já existe nesta base como o documento #'.$existing->id.'.',
            ]);
        }
    }

    /**
     * MIME real do arquivo, não o declarado pelo cliente.
     */
    private function mimeOf(UploadedFile $file): string
    {
        $mime = $file->getMimeType();

        return mb_strtolower(is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream');
    }

    private function extensionSuffix(UploadedFile $file): string
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        return $extension === '' ? '' : '.'.mb_substr($extension, 0, 10);
    }

    /**
     * Nome apenas para exibição e download. Nunca participa do caminho em disco.
     */
    private function safeDisplayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $name);

        return mb_substr(trim($name) === '' ? 'documento' : trim($name), 0, 255);
    }
}
