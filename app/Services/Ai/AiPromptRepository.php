<?php

namespace App\Services\Ai;

use App\Enums\AiRunPurpose;
use App\Services\SystemSettingService;
use RuntimeException;

/**
 * Prompts de sistema versionados em arquivo.
 *
 * Ficam no repositorio para serem revisaveis em diff. A versao ativa por
 * finalidade vem de system_settings, o que permite promover ou reverter sem
 * deploy, mas nunca editar o texto em producao sem rastro.
 */
class AiPromptRepository
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function activeVersion(AiRunPurpose $purpose): string
    {
        $key = match ($purpose) {
            AiRunPurpose::Classify => 'ai.classification_prompt_version',
            AiRunPurpose::ExtractInsight => 'ai.extraction_prompt_version',
        };

        $version = trim((string) $this->settings->get($key, 'v1'));

        return $version === '' ? 'v1' : $version;
    }

    public function get(AiRunPurpose $purpose, ?string $version = null): string
    {
        $version ??= $this->activeVersion($purpose);
        $path = $this->path($purpose, $version);

        if (! is_file($path)) {
            throw new RuntimeException("Prompt de IA nao encontrado: {$purpose->value}/{$version}.");
        }

        return trim((string) file_get_contents($path));
    }

    /** @return array<int, string> */
    public function versions(AiRunPurpose $purpose): array
    {
        $files = glob($this->directory($purpose).'/*.txt') ?: [];

        return collect($files)
            ->map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME))
            ->sort()
            ->values()
            ->all();
    }

    public function exists(AiRunPurpose $purpose, string $version): bool
    {
        return is_file($this->path($purpose, $version));
    }

    private function path(AiRunPurpose $purpose, string $version): string
    {
        // Impede travessia de diretorio a partir de uma configuracao alterada.
        $safeVersion = preg_replace('/[^A-Za-z0-9._-]/', '', $version) ?? '';

        return $this->directory($purpose).'/'.$safeVersion.'.txt';
    }

    private function directory(AiRunPurpose $purpose): string
    {
        $folder = match ($purpose) {
            AiRunPurpose::Classify => 'classification',
            AiRunPurpose::ExtractInsight => 'extraction',
        };

        return resource_path('ai/prompts/'.$folder);
    }
}
