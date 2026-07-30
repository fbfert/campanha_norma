<?php

namespace App\Services\Ai;

use App\Data\Ai\AiCompletionRequest;
use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\InsightReviewReason;
use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationInsightTopic;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use App\Services\SystemSettingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Extração estruturada e pesquisável a partir da resposta do contato.
 *
 * O insight e derivado e versionado. A mensagem original nunca e alterada.
 */
class InsightExtractionService
{
    public function __construct(
        private readonly AiInterpretationGuard $guard,
        private readonly AiClient $client,
        private readonly AiPromptRepository $prompts,
        private readonly AiSchemaRegistry $schemas,
        private readonly AiContextBuilder $context,
        private readonly InsightTopicMapper $topics,
        private readonly SensitiveContentDetector $sensitive,
        private readonly SystemSettingService $settings,
    ) {}

    public function extract(ConversationMessage $message, ?ConversationFlowState $state): ?ConversationInsight
    {
        if (! $this->guard->extractionEnabled()) {
            return null;
        }

        $promptVersion = $this->prompts->activeVersion(AiRunPurpose::ExtractInsight);
        $extractionVersion = $this->schemas->activeVersion(AiRunPurpose::ExtractInsight);

        // Idempotência: mesma mensagem e mesma versão de extração não chamam o
        // provedor de novo nem criam um segundo insight.
        $existing = ConversationInsight::query()
            ->where('source_message_id', $message->id)
            ->where('extraction_version', $extractionVersion)
            ->first();

        if ($existing) {
            return $existing;
        }

        $fallback = $this->topics->fallback();
        $schema = $this->schemas->get(AiRunPurpose::ExtractInsight, $extractionVersion);

        $run = $this->client->execute(
            AiRunPurpose::ExtractInsight,
            new AiCompletionRequest(
                systemPrompt: $this->prompts->get(AiRunPurpose::ExtractInsight, $promptVersion),
                userPrompt: $this->context->forExtraction(
                    $message,
                    $state,
                    $this->topics->promptTopics(),
                    $fallback?->slug ?? 'outros'
                ),
                schemaName: $this->schemas->name(AiRunPurpose::ExtractInsight, $extractionVersion),
                jsonSchema: $schema,
            ),
            $schema,
            $promptVersion,
            $extractionVersion,
            [
                'conversation_id' => $message->conversation_id,
                'source_message_id' => $message->id,
                'conversation_flow_id' => $state?->conversation_flow_id,
            ],
        );

        $sensitiveReason = $this->sensitive->detect($message->body);

        if ($run->status !== AiRunStatus::Succeeded) {
            // Saída invalida ou falha não criam insight, apenas revisão humana.
            return null;
        }

        $data = $run->result ?? [];
        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : null;
        $reason = $sensitiveReason ?? $this->reviewReason($data, $confidence);

        return $this->persist($message, $state, $data, $confidence, $reason, $promptVersion, $extractionVersion, $run->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reviewReason(array $data, ?float $confidence): ?InsightReviewReason
    {
        $threshold = (float) $this->settings->get('ai.min_extraction_confidence', 0.65);

        if ($confidence === null || $confidence < $threshold) {
            return InsightReviewReason::LowConfidence;
        }

        // O modelo pode sinalizar revisão, mas o motivo canônico e sempre um
        // valor conhecido do sistema.
        if (($data['requires_human_review'] ?? false) === true) {
            return InsightReviewReason::tryFrom((string) ($data['review_reason'] ?? ''))
                ?? InsightReviewReason::SensitiveReport;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(
        ConversationMessage $message,
        ?ConversationFlowState $state,
        array $data,
        ?float $confidence,
        ?InsightReviewReason $reason,
        string $promptVersion,
        int $extractionVersion,
        int $runId,
    ): ConversationInsight {
        $mainTopicRaw = is_string($data['main_topic'] ?? null) ? $data['main_topic'] : null;
        $mainTopic = $this->topics->map($mainTopicRaw);
        $secondaryRaw = is_array($data['secondary_topics'] ?? null) ? array_values($data['secondary_topics']) : [];

        $keys = [
            'source_message_id' => $message->id,
            'extraction_version' => $extractionVersion,
        ];

        $attributes = [
            'conversation_id' => $message->conversation_id,
            'contact_id' => $message->conversation?->contact_id,
            'conversation_flow_id' => $state?->conversation_flow_id,
            'conversation_flow_question_id' => $state?->selected_question_id,
            'question_snapshot' => $state?->selected_question_snapshot,
            'summary' => $this->text($data['summary'] ?? null),
            'insight_topic_id' => $mainTopic?->id,
            'main_topic_raw' => $mainTopicRaw,
            'secondary_topics_raw' => $secondaryRaw,
            'identified_problem' => $this->text($data['identified_problem'] ?? null),
            'suggested_action' => $this->text($data['suggested_action'] ?? null),
            'desired_result' => $this->text($data['desired_result'] ?? null),
            'affected_group' => $this->text($data['affected_group'] ?? null),
            'locality_text' => $this->text($data['locality_text'] ?? null),
            // Nunca inferimos cidade: a coluna normalizada so recebe valor quando
            // houver localidade declarada pelo próprio contato.
            'locality_normalized' => null,
            'region' => $this->text($data['region'] ?? null),
            'urgency' => InsightUrgency::tryFrom((string) ($data['urgency'] ?? '')),
            'sentiment' => InsightSentiment::tryFrom((string) ($data['sentiment'] ?? '')),
            'keywords' => is_array($data['keywords'] ?? null) ? array_values($data['keywords']) : [],
            'confidence' => $confidence,
            'requires_human_review' => $reason !== null,
            'review_reason' => $reason?->value,
            'prompt_version' => $promptVersion,
            'ai_run_id' => $runId,
        ];

        return DB::transaction(function () use ($keys, $attributes, $mainTopic, $secondaryRaw): ConversationInsight {
            try {
                $insight = ConversationInsight::updateOrCreate($keys, $attributes);
            } catch (UniqueConstraintViolationException) {
                // Corrida entre workers: o índice único e a garantia final.
                return ConversationInsight::where($keys)->firstOrFail();
            }

            $this->syncTopics($insight, $mainTopic, $secondaryRaw);

            return $insight;
        });
    }

    /**
     * @param  array<int, mixed>  $secondaryRaw
     */
    private function syncTopics(ConversationInsight $insight, ?InsightTopic $mainTopic, array $secondaryRaw): void
    {
        $insight->topicLinks()->delete();

        if ($mainTopic) {
            ConversationInsightTopic::create([
                'conversation_insight_id' => $insight->id,
                'insight_topic_id' => $mainTopic->id,
                'role' => 'main',
                'raw_value' => $insight->main_topic_raw,
            ]);
        }

        foreach ($this->topics->mapMany($this->strings($secondaryRaw)) as $entry) {
            if ($mainTopic && $entry['topic']->id === $mainTopic->id) {
                continue;
            }

            ConversationInsightTopic::create([
                'conversation_insight_id' => $insight->id,
                'insight_topic_id' => $entry['topic']->id,
                'role' => 'secondary',
                'raw_value' => $entry['raw'],
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
