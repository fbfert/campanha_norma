<?php

namespace App\Services\Knowledge;

use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Services\SystemSettingService;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Verificacao antivirus do arquivo enviado.
 *
 * O comando vem de configuracao, sem caminho fixo. Quando o scanner nao esta
 * disponivel e a verificacao e exigida, o upload e recusado: um padrao permissivo
 * transformaria a indisponibilidade do antivirus em ausencia silenciosa de
 * verificacao, que e o pior dos dois mundos.
 */
class AntivirusScanner
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function required(): bool
    {
        return (bool) $this->settings->get('knowledge.antivirus_required', '1');
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * @return string resultado operacional registrado no documento
     *
     * @throws KnowledgeProviderException
     */
    public function scan(string $path): string
    {
        $binary = $this->binary();

        if ($binary === null) {
            if ($this->required()) {
                throw KnowledgeProviderException::code(KnowledgeProviderException::ANTIVIRUS_UNAVAILABLE);
            }

            return 'nao_verificado';
        }

        try {
            $process = new Process($this->arguments($binary, $path));
            $process->setTimeout((float) config('knowledge.process_timeout'));
            $process->run();
        } catch (ExceptionInterface $exception) {
            if ($this->required()) {
                throw KnowledgeProviderException::code(
                    KnowledgeProviderException::ANTIVIRUS_UNAVAILABLE,
                    $exception::class,
                );
            }

            return 'falha_na_verificacao';
        }

        // Convencao do clamscan: 0 limpo, 1 infectado, 2 erro. Tratamos erro como
        // indisponibilidade, nao como arquivo limpo.
        return match ($process->getExitCode()) {
            0 => 'limpo',
            1 => throw KnowledgeProviderException::code(KnowledgeProviderException::INFECTED_FILE),
            default => $this->required()
                ? throw KnowledgeProviderException::code(
                    KnowledgeProviderException::ANTIVIRUS_UNAVAILABLE,
                    'Codigo de saida '.$process->getExitCode().'.',
                )
                : 'falha_na_verificacao',
        };
    }

    private function binary(): ?string
    {
        $template = trim((string) config('knowledge.antivirus_command'));

        if ($template === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $template) ?: [];
        $binary = $parts[0] ?? '';

        if ($binary === '') {
            return null;
        }

        $resolved = str_contains($binary, '/') ? $binary : (new ExecutableFinder)->find($binary);

        return is_string($resolved) && $resolved !== '' && is_executable($resolved) ? $resolved : null;
    }

    /**
     * Sem shell: o caminho do arquivo entra como argumento proprio.
     *
     * @return array<int, string>
     */
    private function arguments(string $binary, string $path): array
    {
        $parts = preg_split('/\s+/', trim((string) config('knowledge.antivirus_command'))) ?: [];
        array_shift($parts);

        $arguments = [$binary];

        foreach ($parts as $part) {
            $arguments[] = $part === ':input' ? $path : $part;
        }

        // Template sem marcador ainda precisa receber o arquivo.
        if (! in_array($path, $arguments, true)) {
            $arguments[] = $path;
        }

        return $arguments;
    }
}
