<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InsightTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ai_insights.manage_taxonomy');
    }

    public function rules(): array
    {
        $topicId = $this->route('insightTopic')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('insight_topics', 'slug')->ignore($topicId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'synonyms' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:20'],
            'display_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'parent_id' => [
                'nullable', 'integer', 'exists:insight_topics,id',
                Rule::notIn([$topicId]),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'O identificador aceita apenas letras minúsculas, números e sublinhado.',
            'parent_id.not_in' => 'Um tema não pode ser pai de si mesmo.',
        ];
    }
}
