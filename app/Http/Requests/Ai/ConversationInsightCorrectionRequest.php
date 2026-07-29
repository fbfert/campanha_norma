<?php

namespace App\Http\Requests\Ai;

use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Enums\MessageClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationInsightCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ai_insights.correct');
    }

    public function rules(): array
    {
        return [
            'summary' => ['nullable', 'string', 'max:1000'],
            'identified_problem' => ['nullable', 'string', 'max:1000'],
            'suggested_action' => ['nullable', 'string', 'max:1000'],
            'desired_result' => ['nullable', 'string', 'max:1000'],
            'affected_group' => ['nullable', 'string', 'max:255'],
            'locality_text' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', Rule::in(InsightUrgency::values())],
            'sentiment' => ['nullable', Rule::in(InsightSentiment::values())],
            'insight_topic_id' => ['nullable', 'integer', 'exists:insight_topics,id'],
            'classification' => ['nullable', Rule::in(MessageClassification::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Somente os campos do insight, sem classificacao nem motivo.
     *
     * @return array<string, mixed>
     */
    public function insightValues(): array
    {
        return $this->only([
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
        ]);
    }
}
