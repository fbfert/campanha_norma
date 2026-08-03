<?php

namespace App\Services\Ai;

use App\Models\InsightTopic;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
use Illuminate\Support\Collection;

/**
 * Mapeia a saída livre do modelo para um tema cadastrado.
 *
 * Determinístico e sem aproximação semântica: compara slug, nome e sinônimos
 * normalizados. Sem correspondência, cai no tema de fallback. O modelo nunca
 * cria tema.
 */
class InsightTopicMapper
{
    public function __construct(private readonly PermissionResponseClassifier $normalizer) {}

    public function map(?string $rawTopic): ?InsightTopic
    {
        $normalized = $this->normalizer->normalize((string) $rawTopic);

        if ($normalized === '') {
            return $this->fallback();
        }

        $match = $this->activeTopics()->first(
            fn (InsightTopic $topic): bool => in_array($normalized, $this->keysFor($topic), true)
        );

        return $match ?? $this->fallback();
    }

    /**
     * @param  array<int, string>|null  $rawTopics
     * @return Collection<int, array{topic: InsightTopic, raw: string}>
     */
    public function mapMany(?array $rawTopics): Collection
    {
        return collect($rawTopics ?? [])
            ->filter(fn ($item): bool => is_string($item) && trim($item) !== '')
            ->map(function (string $raw): ?array {
                $topic = $this->map($raw);

                return $topic ? ['topic' => $topic, 'raw' => $raw] : null;
            })
            ->filter()
            ->unique(fn (array $item): int => $item['topic']->id)
            ->values();
    }

    public function fallback(): ?InsightTopic
    {
        return InsightTopic::query()->where('is_fallback', true)->first();
    }

    /**
     * Lista de temas ativos para o prompt, cada um com o vocabulário que o
     * representa.
     *
     * Antes ia so o slug. O modelo tinha de adivinhar o que `cultura` abrange, e
     * os sinônimos — que existiam no cadastro — so eram consultados depois, para
     * mapear a resposta dele de volta. O resultado foi metade das analises caindo
     * em "outros": ele não tinha como saber que pista de skate e praça estão em
     * `cultura`, nem que posto de saúde esta em `saude`.
     *
     * A descrição continua fora: ela e nota interna de quem cadastra o tema, e
     * pode conter orientação que não faz sentido para o modelo.
     *
     * @return array<int, string>
     */
    public function promptTopics(): array
    {
        return $this->activeTopics()
            ->map(function (InsightTopic $topic): string {
                $vocabulario = $topic->synonymList();

                return $vocabulario === []
                    ? $topic->slug
                    : $topic->slug.' ('.collect($vocabulario)->take(12)->implode(', ').')';
            })
            ->values()
            ->all();
    }

    /** @return Collection<int, InsightTopic> */
    private function activeTopics(): Collection
    {
        return InsightTopic::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Chaves normalizadas que representam o tema.
     *
     * @return array<int, string>
     */
    private function keysFor(InsightTopic $topic): array
    {
        return collect([$topic->slug, $topic->name])
            ->merge($topic->synonymList())
            ->map(fn (string $item): string => $this->normalizer->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
