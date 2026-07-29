<?php

namespace App\Services\Ai;

use App\Data\Ai\AiCompletionRequest;
use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\ClassificationSource;
use App\Enums\InsightReviewReason;
use App\Enums\MessageClassification;
use App\Enums\PermissionResponseClassification;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
use App\Services\SystemSettingService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Classificacao ampliada de mensagens abertas.
 *
 * A regra deterministica da 9A tem precedencia estrutural: quando ela conclui,
 * o caminho de codigo nem chega ao provedor de IA.
 */
class MessageClassificationService
{
    private const DETERMINISTIC_VERSION = 'deterministic';

    public function __construct(
        private readonly PermissionResponseClassifier $deterministic,
        private readonly AiInterpretationGuard $guard,
        private readonly AiClient $client,
        private readonly AiPromptRepository $prompts,
        private readonly AiSchemaRegistry $schemas,
        private readonly AiContextBuilder $context,
        private readonly SensitiveContentDetector $sensitive,
        private readonly SystemSettingService $settings,
    ) {}

    public function classify(ConversationMessage $message, ?ConversationFlowState $state): ConversationMessageClassification
    {
        $sensitiveReason = $this->sensitive->detect($message->body);

        if ($unsupported = $this->unsupportedClassification($message)) {
            return $this->persist($message, $unsupported, ClassificationSource::Deterministic, 1.0, $sensitiveReason, self::DETERMINISTIC_VERSION, 0);
        }

        $rule = $this->deterministic->classify($message->body);

        if ($mapped = $this->fromDeterministic($rule['classification'], $rule['matched'])) {
            return $this->persist($message, $mapped, ClassificationSource::Deterministic, 1.0, $sensitiveReason, self::DETERMINISTIC_VERSION, 0);
        }

        if (! $this->guard->classificationEnabled()) {
            return $this->persist($message, MessageClassification::Ambiguous, ClassificationSource::Deterministic, null, $sensitiveReason, self::DETERMINISTIC_VERSION, 0);
        }

        return $this->classifyWithAi($message, $state, $sensitiveReason);
    }

    private function classifyWithAi(ConversationMessage $message, ?ConversationFlowState $state, ?InsightReviewReason $sensitiveReason): ConversationMessageClassification
    {
        $promptVersion = $this->prompts->activeVersion(AiRunPurpose::Classify);
        $schemaVersion = $this->schemas->activeVersion(AiRunPurpose::Classify);

        // Idempotencia: mesma mensagem, mesma finalidade e mesma versao nao
        // provoca nova chamada ao provedor.
        $existing = $this->existing($message, $promptVersion, $schemaVersion);
        if ($existing) {
            return $existing;
        }

        $schema = $this->schemas->get(AiRunPurpose::Classify, $schemaVersion);

        $run = $this->client->execute(
            AiRunPurpose::Classify,
            new AiCompletionRequest(
                systemPrompt: $this->prompts->get(AiRunPurpose::Classify, $promptVersion),
                userPrompt: $this->context->forClassification($message, $state),
                schemaName: $this->schemas->name(AiRunPurpose::Classify, $schemaVersion),
                jsonSchema: $schema,
            ),
            $schema,
            $promptVersion,
            $schemaVersion,
            [
                'conversation_id' => $message->conversation_id,
                'source_message_id' => $message->id,
                'conversation_flow_id' => $state?->conversation_flow_id,
            ],
        );

        if ($run->status !== AiRunStatus::Succeeded) {
            // Falha ou saida invalida nunca alteram estado: registram ambiguidade
            // e mandam para revisao humana com o motivo correspondente.
            $reason = $run->status === AiRunStatus::InvalidOutput
                ? InsightReviewReason::InvalidOutput
                : InsightReviewReason::ProviderFailure;

            return $this->persist(
                $message,
                MessageClassification::Ambiguous,
                ClassificationSource::Ai,
                null,
                $sensitiveReason ?? $reason,
                $promptVersion,
                $schemaVersion,
                $run->id,
            );
        }

        $data = $run->result ?? [];
        $classification = MessageClassification::tryFrom((string) ($data['classification'] ?? '')) ?? MessageClassification::Ambiguous;
        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : null;

        return $this->persist(
            $message,
            $classification,
            ClassificationSource::Ai,
            $confidence,
            $sensitiveReason ?? $this->reviewReasonFor($classification, $confidence),
            $promptVersion,
            $schemaVersion,
            $run->id,
        );
    }

    /**
     * Motivo de revisao decidido pelo sistema, nunca pelo modelo.
     */
    private function reviewReasonFor(MessageClassification $classification, ?float $confidence): ?InsightReviewReason
    {
        $mapped = match ($classification) {
            MessageClassification::HumanRequested => InsightReviewReason::HumanRequested,
            MessageClassification::Complaint => InsightReviewReason::Complaint,
            MessageClassification::SensitiveReport => InsightReviewReason::SensitiveReport,
            MessageClassification::InsultOrAbuse => InsightReviewReason::InsultOrAbuse,
            default => null,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        $threshold = (float) $this->settings->get('ai.min_classification_confidence', 0.7);

        if ($confidence === null || $confidence < $threshold) {
            return InsightReviewReason::LowConfidence;
        }

        return null;
    }

    /**
     * Mapeia a saida deterministica da 9A para a taxonomia ampliada da 9B.
     * `ambiguous` sem expressao correspondida devolve null para permitir IA.
     */
    private function fromDeterministic(PermissionResponseClassification $classification, ?string $matched): ?MessageClassification
    {
        if ($matched === null) {
            return null;
        }

        return match ($classification) {
            PermissionResponseClassification::OptOut => MessageClassification::OptOut,
            PermissionResponseClassification::PermissionYes => MessageClassification::PermissionYes,
            PermissionResponseClassification::PermissionNo => MessageClassification::PermissionNo,
            PermissionResponseClassification::Ambiguous => null,
        };
    }

    private function unsupportedClassification(ConversationMessage $message): ?MessageClassification
    {
        // `message_type` e string no schema existente ('text', 'image', ...).
        if ($message->message_type !== 'text' || blank($message->body)) {
            return MessageClassification::MediaOrUnsupported;
        }

        return null;
    }

    private function existing(ConversationMessage $message, string $promptVersion, int $schemaVersion): ?ConversationMessageClassification
    {
        return ConversationMessageClassification::query()
            ->where('conversation_message_id', $message->id)
            ->where('purpose', AiRunPurpose::Classify->value)
            ->where('prompt_version', $promptVersion)
            ->where('schema_version', $schemaVersion)
            ->first();
    }

    private function persist(
        ConversationMessage $message,
        MessageClassification $classification,
        ClassificationSource $source,
        ?float $confidence,
        ?InsightReviewReason $reason,
        string $promptVersion,
        int $schemaVersion,
        ?int $runId = null,
    ): ConversationMessageClassification {
        $reason ??= $classification->alwaysRequiresHuman()
            ? InsightReviewReason::from($this->fallbackReason($classification))
            : null;

        $attributes = [
            'conversation_id' => $message->conversation_id,
            'classification' => $classification,
            'source' => $source,
            'confidence' => $confidence,
            'requires_human_review' => $reason !== null,
            'review_reason' => $reason?->value,
            'ai_run_id' => $runId,
        ];

        $keys = [
            'conversation_message_id' => $message->id,
            'purpose' => AiRunPurpose::Classify->value,
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
        ];

        try {
            return ConversationMessageClassification::updateOrCreate($keys, $attributes);
        } catch (UniqueConstraintViolationException) {
            // Corrida entre workers: o indice unico e a garantia final.
            return ConversationMessageClassification::where($keys)->firstOrFail();
        }
    }

    private function fallbackReason(MessageClassification $classification): string
    {
        return match ($classification) {
            MessageClassification::HumanRequested => InsightReviewReason::HumanRequested->value,
            MessageClassification::Complaint => InsightReviewReason::Complaint->value,
            MessageClassification::SensitiveReport => InsightReviewReason::SensitiveReport->value,
            default => InsightReviewReason::InsultOrAbuse->value,
        };
    }
}
