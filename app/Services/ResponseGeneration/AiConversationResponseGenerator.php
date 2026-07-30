<?php

namespace App\Services\ResponseGeneration;

use App\Contracts\AnswerGroundingValidator;
use App\Contracts\ConversationResponseGenerator;
use App\Data\Ai\AiCompletionRequest;
use App\Data\Knowledge\GroundingVerdict;
use App\Data\Knowledge\RetrievalResult;
use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\GroundingStatus;
use App\Enums\HandoffReason;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Models\ConversationReplySuggestion;
use App\Models\KnowledgeRetrieval;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPromptRepository;
use App\Services\Ai\AiSchemaRegistry;
use App\Services\Ai\InsightTopicMapper;
use App\Services\Knowledge\KnowledgeGuard;
use App\Services\Knowledge\KnowledgeRetrievalService;
use App\Services\Knowledge\SuggestionCitationRecorder;
use App\Services\SystemSettingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gerador de resposta apoiado no provedor de IA configurado.
 *
 * Produz apenas uma linha em `conversation_reply_suggestions`. Não envia nada e
 * não altera o estado do fluxo.
 */
class AiConversationResponseGenerator implements ConversationResponseGenerator
{
    public function __construct(
        private readonly AiClient $client,
        private readonly AiPromptRepository $prompts,
        private readonly AiSchemaRegistry $schemas,
        private readonly ResponseContextBuilder $context,
        private readonly ReplyTextValidator $validator,
        private readonly ResponseModeResolver $modes,
        private readonly InsightTopicMapper $topics,
        private readonly SystemSettingService $settings,
        private readonly KnowledgeGuard $knowledge,
        private readonly KnowledgeRetrievalService $retrieval,
        private readonly AnswerGroundingValidator $grounding,
        private readonly SuggestionCitationRecorder $citations,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(ConversationMessage $message, ConversationFlowState $state, array $options = []): ?ConversationReplySuggestion
    {
        $mode = $this->modes->forFlow($state->flow);

        if (! $mode->generates()) {
            return null;
        }

        // A recuperação acontece antes da chamada ao modelo e fora de qualquer
        // transação de registro de mensagem. Falha aqui degrada para o
        // comportamento da subetapa anterior e não interrompe nada.
        [$retrieved, $retrievalLog] = $this->retrieve($message, $state);
        $useGrounded = $retrieved !== null;

        $promptVersion = $useGrounded
            ? $this->prompts->activeGroundedResponseVersion()
            : $this->prompts->activeVersion(AiRunPurpose::GenerateReply);

        $schemaVersion = $useGrounded
            ? $this->schemas->activeGroundedResponseVersion()
            : $this->schemas->activeVersion(AiRunPurpose::GenerateReply);

        $schema = $this->schemas->get(AiRunPurpose::GenerateReply, $schemaVersion);

        $insight = ConversationInsight::query()
            ->where('source_message_id', $message->id)
            ->latest('extraction_version')
            ->with(['topic', 'topicLinks.topic'])
            ->first();

        $classification = ConversationMessageClassification::query()
            ->where('conversation_message_id', $message->id)
            ->latest('id')
            ->first();

        $run = $this->client->execute(
            AiRunPurpose::GenerateReply,
            new AiCompletionRequest(
                systemPrompt: $this->prompts->get(AiRunPurpose::GenerateReply, $promptVersion),
                userPrompt: $this->context->build($message, $state, $insight, $retrieved),
                schemaName: $this->schemas->name(AiRunPurpose::GenerateReply, $schemaVersion),
                jsonSchema: $schema,
            ),
            $schema,
            $promptVersion,
            $schemaVersion,
            [
                'conversation_id' => $message->conversation_id,
                'source_message_id' => $message->id,
                'conversation_flow_id' => $state->conversation_flow_id,
            ],
        );

        // Liga o log de recuperação ao run so agora: a busca precede a chamada, e
        // sem esse vínculo o log não explica qual geração ele sustentou.
        $retrievalLog?->update(['ai_run_id' => $run->id]);

        $base = [
            'knowledge_retrieval_id' => $retrievalLog?->id,
            'conversation_id' => $message->conversation_id,
            'conversation_flow_state_id' => $state->id,
            'conversation_flow_id' => $state->conversation_flow_id,
            'source_message_id' => $message->id,
            'ai_run_id' => $run->id,
            'conversation_insight_id' => $insight?->id,
            'classification_id' => $classification?->id,
            'mode' => $mode,
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'turn_number' => $state->followups_count + 1,
            'generation_attempt' => (int) ($options['attempt'] ?? 1),
            'regeneration_reason' => $options['regeneration_reason'] ?? null,
            'expires_at' => now()->addMinutes(max(1, (int) $this->settings->get('ai.response.validity_minutes', 120))),
        ];

        // Falha ou saída invalida nunca viram sugestão aprovável.
        if ($run->status !== AiRunStatus::Succeeded) {
            return $this->persist($base + [
                'status' => ReplySuggestionStatus::Blocked,
                'action' => ReplySuggestionAction::HandoffHuman,
                'requires_human_review' => true,
                'handoff_reason' => $run->status === AiRunStatus::InvalidOutput
                    ? HandoffReason::InvalidGeneratedText
                    : HandoffReason::RepeatedProviderFailure,
                'blocked_reason' => (string) $run->error_code,
            ]);
        }

        $data = $run->result ?? [];
        $action = ReplySuggestionAction::tryFrom((string) ($data['action'] ?? '')) ?? ReplySuggestionAction::HandoffHuman;
        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : null;
        $text = is_string($data['reply_text'] ?? null) ? trim($data['reply_text']) : null;
        $topic = $this->topics->map(is_string($data['topic'] ?? null) ? $data['topic'] : null);

        $attributes = $base + [
            'action' => $action,
            'follow_up_type' => is_string($data['follow_up_type'] ?? null) ? $data['follow_up_type'] : null,
            'insight_topic_id' => $topic?->id,
            'generated_text' => $text,
            'confidence' => $confidence,
        ];

        // Ação sem texto para o contato: vira sugestão de encaminhamento.
        if (! $action->producesText()) {
            return $this->persist($attributes + [
                'status' => ReplySuggestionStatus::Pending,
                'requires_human_review' => true,
                'handoff_reason' => $action === ReplySuggestionAction::HandoffHuman
                    ? ($this->handoffReasonFrom($data) ?? HandoffReason::ContextConflict)
                    : null,
                'generated_text' => null,
            ] + $this->groundingColumns(null));
        }

        // Validação determinística do texto, independente do que o modelo disse.
        $validation = $this->validator->validate($text);

        if (! $validation['valid']) {
            return $this->persist($attributes + [
                'status' => ReplySuggestionStatus::Blocked,
                'requires_human_review' => true,
                'handoff_reason' => HandoffReason::InvalidGeneratedText,
                'validation_error' => implode(', ', $validation['errors']),
            ] + $this->groundingColumns(null));
        }

        // Fundamentação: o `grounded` do modelo e sinal, e esta conferência e a
        // autorização. Roda depois da validação de texto porque texto reprovado
        // já não vai a lugar nenhum, fundamentado ou não.
        $declared = is_array($data['citations'] ?? null) ? $data['citations'] : [];

        $verdict = $retrieved === null
            ? null
            : $this->grounding->validate($text, $declared, $retrieved, ($data['grounded'] ?? false) === true);

        if ($verdict !== null && ! $verdict->allowsSending()) {
            $suggestion = $this->persist($attributes + [
                'status' => ReplySuggestionStatus::Blocked,
                'requires_human_review' => true,
                'handoff_reason' => $verdict->status === GroundingStatus::NoEvidence
                    ? HandoffReason::InsufficientEvidence
                    : HandoffReason::UngroundedAnswer,
                'blocked_reason' => $verdict->status->value,
            ] + $this->groundingColumns($verdict));

            return $this->recordCitations($suggestion, $verdict, $retrievalLog, $declared);
        }

        $threshold = (float) $this->settings->get('ai.response.min_confidence', 0.75);
        $lowConfidence = $confidence === null || $confidence < $threshold;

        $suggestion = $this->persist($attributes + [
            'status' => ReplySuggestionStatus::Pending,
            'requires_human_review' => $lowConfidence || ($data['requires_human_review'] ?? false) === true,
            'handoff_reason' => $lowConfidence ? HandoffReason::LowConfidence : $this->handoffReasonFrom($data),
        ] + $this->groundingColumns($verdict));

        return $this->recordCitations($suggestion, $verdict, $retrievalLog, $declared);
    }

    /**
     * Recuperação na base oficial, quando o fluxo tem base ativa associada.
     *
     * Devolve `null` quando não ha base ou quando a recuperação falhou. Nos dois
     * casos a geração segue com o contrato da subetapa anterior: a base pode ficar
     * indisponível, a conversa não pode parar por causa disso.
     *
     * @return array{0: ?RetrievalResult, 1: ?KnowledgeRetrieval}
     */
    private function retrieve(ConversationMessage $message, ConversationFlowState $state): array
    {
        if (! $this->knowledge->groundingEnabledForFlow($state->flow)) {
            return [null, null];
        }

        try {
            $outcome = $this->retrieval->retrieveForFlow($state->flow, (string) $message->body, [
                'conversation_id' => $message->conversation_id,
                'source_message_id' => $message->id,
            ]);

            return [$outcome['result'], $outcome['retrieval']];
        } catch (Throwable $exception) {
            Log::warning('knowledge.retrieval_failed', [
                'conversation_id' => $message->conversation_id,
                'source_message_id' => $message->id,
                'exception' => $exception::class,
                // Mensagem do provedor pode carregar trecho de requisição: fica de fora.
                'code' => $exception->getCode(),
            ]);

            return [null, null];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function groundingColumns(?GroundingVerdict $verdict): array
    {
        if ($verdict === null) {
            return [
                'grounded' => false,
                'grounding_status' => null,
                'grounding_error' => null,
                'citation_count' => 0,
            ];
        }

        return [
            // `grounded` afirma que a resposta se apoia em evidência conferida.
            // Texto sem afirmação factual não e fundamentado: e dispensado.
            'grounded' => $verdict->status === GroundingStatus::Grounded,
            'grounding_status' => $verdict->status,
            'grounding_error' => $verdict->errorSummary(),
            'citation_count' => count($verdict->citations),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $declared
     */
    private function recordCitations(
        ?ConversationReplySuggestion $suggestion,
        ?GroundingVerdict $verdict,
        ?KnowledgeRetrieval $retrievalLog,
        array $declared,
    ): ?ConversationReplySuggestion {
        // `wasRecentlyCreated` falso significa que outro worker venceu a corrida e
        // já gravou as citações dele: duplicar aqui inventaria fonte.
        if ($suggestion === null || $verdict === null || ! $suggestion->wasRecentlyCreated) {
            return $suggestion;
        }

        $this->citations->record($suggestion, $verdict, $retrievalLog, $declared);

        return $suggestion;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handoffReasonFrom(array $data): ?HandoffReason
    {
        return HandoffReason::tryFrom((string) ($data['handoff_reason'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes): ?ConversationReplySuggestion
    {
        $status = $attributes['status'];

        // A coluna espelho ocupa a unicidade apenas enquanto a sugestão vive.
        $attributes['active_source_message_id'] = $status->isLive() ? $attributes['source_message_id'] : null;

        try {
            return ConversationReplySuggestion::create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Outro worker já criou a sugestão viva desta mensagem.
            return ConversationReplySuggestion::query()
                ->where('active_source_message_id', $attributes['source_message_id'])
                ->first();
        }
    }
}
