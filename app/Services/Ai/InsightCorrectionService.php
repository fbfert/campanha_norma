<?php

namespace App\Services\Ai;

use App\Enums\ClassificationSource;
use App\Enums\MessageClassification;
use App\Models\ConversationInsight;
use App\Models\ConversationInsightCorrection;
use App\Models\ConversationMessageClassification;
use App\Models\User;
use App\Services\AuditLogger;
use BackedEnum;

/**
 * Correcao humana auditada.
 *
 * Grava sempre o valor original. Nenhuma correcao retroalimenta o modelo, ajusta
 * prompt ou vira exemplo automatico: promover uma correcao exige uma nova versao
 * de prompt no repositorio.
 */
class InsightCorrectionService
{
    /** Campos do insight que o operador pode corrigir. */
    private const EDITABLE = [
        'summary',
        'identified_problem',
        'suggested_action',
        'desired_result',
        'affected_group',
        'locality_text',
        'region',
        'urgency',
        'sentiment',
        'insight_topic_id',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $values
     * @return int Quantidade de campos efetivamente alterados.
     */
    public function correctInsight(ConversationInsight $insight, array $values, User $user, ?string $reason = null): int
    {
        $changed = 0;

        foreach (self::EDITABLE as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $original = $insight->getAttribute($field);
            $originalValue = $this->stringify($original);
            $newValue = $this->stringify($values[$field]);

            if ($originalValue === $newValue) {
                continue;
            }

            $insight->setAttribute($field, $values[$field] === '' ? null : $values[$field]);

            ConversationInsightCorrection::create([
                'conversation_insight_id' => $insight->id,
                'field' => $field,
                'original_value' => $originalValue,
                'corrected_value' => $newValue,
                'reason' => $reason,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            $changed++;
        }

        if ($changed === 0) {
            return 0;
        }

        $insight->forceFill([
            'reviewed' => true,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'requires_human_review' => false,
        ])->save();

        $this->syncMainTopicLink($insight);

        $this->audit->log('ai_insights.corrected', 'Insight corrigido manualmente.', $insight, null, [
            'insight_id' => $insight->id,
            'conversation_id' => $insight->conversation_id,
            'fields' => $changed,
        ], $user);

        return $changed;
    }

    public function correctClassification(
        ConversationMessageClassification $classification,
        MessageClassification $newClassification,
        User $user,
        ?string $reason = null,
    ): bool {
        $original = $classification->classification;

        if ($original === $newClassification) {
            return false;
        }

        ConversationInsightCorrection::create([
            'conversation_message_classification_id' => $classification->id,
            'field' => 'classification',
            'original_value' => $original->value,
            'corrected_value' => $newClassification->value,
            'reason' => $reason,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $classification->forceFill([
            'classification' => $newClassification,
            'source' => ClassificationSource::Human,
            'requires_human_review' => false,
            'review_reason' => null,
        ])->save();

        $this->audit->log('ai_insights.classification_corrected', 'Classificacao corrigida manualmente.', $classification, null, [
            'classification_id' => $classification->id,
            'conversation_id' => $classification->conversation_id,
            'from' => $original->value,
            'to' => $newClassification->value,
        ], $user);

        return true;
    }

    /**
     * Marca como revisado sem alterar valores, para casos em que o operador
     * confirma que o resultado esta correto.
     */
    public function approve(ConversationInsight $insight, User $user): void
    {
        $insight->forceFill([
            'reviewed' => true,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'requires_human_review' => false,
        ])->save();

        $this->audit->log('ai_insights.approved', 'Insight aprovado sem alteracao.', $insight, null, [
            'insight_id' => $insight->id,
            'conversation_id' => $insight->conversation_id,
        ], $user);
    }

    /**
     * Mantem o vinculo relacional do tema principal coerente com a correcao.
     */
    private function syncMainTopicLink(ConversationInsight $insight): void
    {
        $insight->topicLinks()->where('role', 'main')->delete();

        if ($insight->insight_topic_id === null) {
            return;
        }

        $insight->topicLinks()->create([
            'insight_topic_id' => $insight->insight_topic_id,
            'role' => 'main',
            'raw_value' => $insight->main_topic_raw,
        ]);
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
