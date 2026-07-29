<?php

namespace App\Services\Ai;

use App\Enums\InsightReviewReason;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
use App\Services\SystemSettingService;

/**
 * Deteccao deterministica de situacoes que exigem atendimento humano.
 *
 * Roda sobre o texto original, independentemente do resultado da IA e tambem
 * quando a chamada de IA falhou. O modelo nunca decide sozinho se um caso e
 * sensivel.
 */
class SensitiveContentDetector
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PermissionResponseClassifier $normalizer,
    ) {}

    /**
     * Primeiro motivo detectado, na ordem de gravidade das listas configuradas.
     */
    public function detect(?string $text): ?InsightReviewReason
    {
        $normalized = $this->normalizer->normalize((string) $text);

        if ($normalized === '') {
            return null;
        }

        foreach (InsightReviewReason::textDetectable() as $reason) {
            $key = $reason->settingKey();

            if ($key === null) {
                continue;
            }

            if ($this->matches($normalized, $this->expressions($key))) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $expressions
     */
    private function matches(string $normalized, array $expressions): bool
    {
        foreach ($expressions as $expression) {
            if ($normalized === $expression) {
                return true;
            }

            // Palavra ou frase inteira, nunca substring solta.
            if (preg_match('/(?:^|\s)'.preg_quote($expression, '/').'(?:\s|$)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function expressions(string $key): array
    {
        $raw = (string) $this->settings->get($key, '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalizer->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
