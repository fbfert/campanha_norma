<?php

namespace App\Services\Knowledge;

use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\Extractors\DocxExtractor;
use App\Services\Knowledge\Extractors\ExtractedText;
use App\Services\Knowledge\Extractors\HtmlExtractor;
use App\Services\Knowledge\Extractors\PdfExtractor;
use App\Services\Knowledge\Extractors\PlainTextExtractor;
use App\Services\Knowledge\Extractors\TextExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * Despacha a extração pelo MIME real do arquivo.
 *
 * Formato sem extrator não produz extração parcial: produz falha com código.
 */
class DocumentTextExtractor
{
    /** @var array<int, TextExtractor> */
    private array $extractors;

    public function __construct()
    {
        $this->extractors = [
            new PlainTextExtractor,
            new HtmlExtractor,
            new DocxExtractor,
            new PdfExtractor,
        ];
    }

    public function extract(KnowledgeDocument $document): ExtractedText
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->file_path)) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::FILE_MISSING);
        }

        $path = $disk->path($document->file_path);
        $extension = mb_strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($document->mime_type, $extension)) {
                $extracted = $extractor->extract($path);

                if ($extracted->isEmpty()) {
                    throw KnowledgeProviderException::code(KnowledgeProviderException::EMPTY_EXTRACTION);
                }

                return $extracted;
            }
        }

        throw KnowledgeProviderException::code(
            KnowledgeProviderException::EXTRACTOR_UNAVAILABLE,
            'MIME sem extrator: '.$document->mime_type,
        );
    }
}
