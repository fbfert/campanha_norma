<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiProviderException;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Cache;

/**
 * Disjuntor simples por provedor.
 *
 * Conta falhas consecutivas em cache. Ao atingir o limite, abre por um periodo
 * configuravel e as chamadas seguintes falham sem tocar a rede. Um sucesso zera
 * o contador. Deliberadamente sem meia-abertura probabilistica, para nao
 * introduzir nao determinismo em teste.
 */
class AiCircuitBreaker
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function isOpen(string $provider): bool
    {
        return Cache::get($this->openKey($provider)) !== null;
    }

    /**
     * @throws AiProviderException
     */
    public function assertClosed(string $provider): void
    {
        if ($this->isOpen($provider)) {
            throw new AiProviderException(
                AiProviderException::CIRCUIT_OPEN,
                'Provedor de IA temporariamente indisponivel por excesso de falhas.'
            );
        }
    }

    public function recordSuccess(string $provider): void
    {
        Cache::forget($this->failureKey($provider));
        Cache::forget($this->openKey($provider));
    }

    public function recordFailure(string $provider): void
    {
        $threshold = max(1, (int) $this->settings->get('ai.circuit_failure_threshold', 5));
        $openSeconds = max(1, (int) $this->settings->get('ai.circuit_open_seconds', 300));

        $failures = ((int) Cache::get($this->failureKey($provider), 0)) + 1;
        Cache::put($this->failureKey($provider), $failures, $openSeconds * 2);

        if ($failures >= $threshold) {
            Cache::put($this->openKey($provider), true, $openSeconds);
        }
    }

    public function failures(string $provider): int
    {
        return (int) Cache::get($this->failureKey($provider), 0);
    }

    private function failureKey(string $provider): string
    {
        return "ai-circuit:failures:{$provider}";
    }

    private function openKey(string $provider): string
    {
        return "ai-circuit:open:{$provider}";
    }
}
