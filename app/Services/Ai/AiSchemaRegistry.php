<?php

namespace App\Services\Ai;

use App\Enums\AiRunPurpose;
use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Enums\MessageClassification;
use App\Enums\ReplySuggestionAction;
use App\Services\SystemSettingService;
use RuntimeException;

/**
 * Schemas JSON por finalidade e versão.
 *
 * O mesmo schema e enviado ao provedor e aplicado localmente pelo validador.
 * Nenhum campo para atributo sensível existe aqui: a ausência do campo e a
 * garantia estrutural de que o modelo não tem onde escrever esse dado.
 */
class AiSchemaRegistry
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function activeVersion(AiRunPurpose $purpose): int
    {
        $key = match ($purpose) {
            AiRunPurpose::Classify => 'ai.classification_schema_version',
            AiRunPurpose::ExtractInsight => 'ai.extraction_schema_version',
            AiRunPurpose::GenerateReply => 'ai.response.schema_version',
        };

        return max(1, (int) $this->settings->get($key, 1));
    }

    /**
     * Versão do schema usada quando a resposta e fundamentada em base aprovada.
     * Chave própria pelo mesmo motivo do prompt fundamentado.
     */
    public function activeGroundedResponseVersion(): int
    {
        return max(2, (int) $this->settings->get('ai.response.grounded_schema_version', 2));
    }

    public function name(AiRunPurpose $purpose, int $version): string
    {
        return $purpose->value.'_v'.$version;
    }

    /** @return array<string, mixed> */
    public function get(AiRunPurpose $purpose, ?int $version = null): array
    {
        $version ??= $this->activeVersion($purpose);

        return match ($purpose) {
            AiRunPurpose::Classify => $this->classification($version),
            AiRunPurpose::ExtractInsight => $this->extraction($version),
            AiRunPurpose::GenerateReply => $this->response($version),
        };
    }

    /** @return array<string, mixed> */
    private function classification(int $version): array
    {
        if ($version !== 1) {
            throw new RuntimeException("Versão de schema de classificação não suportada: {$version}.");
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['classification', 'confidence', 'requires_human_review', 'review_reason'],
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => MessageClassification::values()],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'requires_human_review' => ['type' => 'boolean'],
                'review_reason' => ['type' => ['string', 'null'], 'maxLength' => 255],
            ],
        ];
    }

    /**
     * Schema da resposta gerada. Não existe campo para dado factual sobre a
     * pessoa representada: sem base aprovada, o modelo não tem onde inventar.
     *
     * @return array<string, mixed>
     */
    private function response(int $version): array
    {
        return match ($version) {
            1 => $this->responseV1(),
            2 => $this->responseV2(),
            default => throw new RuntimeException("Versão de schema de resposta não suportada: {$version}."),
        };
    }

    /** @return array<string, mixed> */
    private function responseV1(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['action', 'reply_text', 'follow_up_type', 'topic', 'confidence', 'requires_human_review', 'handoff_reason'],
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ReplySuggestionAction::values()],
                'reply_text' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'follow_up_type' => ['type' => ['string', 'null'], 'maxLength' => 60],
                'topic' => ['type' => ['string', 'null'], 'maxLength' => 120],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'requires_human_review' => ['type' => 'boolean'],
                'handoff_reason' => ['type' => ['string', 'null'], 'maxLength' => 120],
            ],
        ];
    }

    /**
     * Resposta fundamentada em base aprovada.
     *
     * `grounded` e `citations` são devolvidos pelo modelo como declaração, e a
     * validação de fundamentação confere depois se ela se sustenta. Não existe
     * campo para o modelo afirmar que dispensa evidência.
     *
     * @return array<string, mixed>
     */
    private function responseV2(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'action', 'reply_text', 'follow_up_type', 'topic', 'grounded',
                'citations', 'confidence', 'requires_human_review', 'handoff_reason',
            ],
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ReplySuggestionAction::values()],
                'reply_text' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'follow_up_type' => ['type' => ['string', 'null'], 'maxLength' => 60],
                'topic' => ['type' => ['string', 'null'], 'maxLength' => 120],
                'grounded' => ['type' => 'boolean'],
                'citations' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['document_id', 'chunk_id', 'page', 'section'],
                        'properties' => [
                            'document_id' => ['type' => 'integer', 'minimum' => 1],
                            'chunk_id' => ['type' => 'string', 'maxLength' => 120],
                            // Página e seção existem no contrato para o modelo
                            // poder ecoar o que leu. O valor gravado na citação vem
                            // do trecho recuperado, não daqui: metadado de
                            // procedência não e escolha do modelo.
                            'page' => ['type' => ['integer', 'null'], 'minimum' => 1],
                            'section' => ['type' => ['string', 'null'], 'maxLength' => 255],
                        ],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'requires_human_review' => ['type' => 'boolean'],
                'handoff_reason' => ['type' => ['string', 'null'], 'maxLength' => 120],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function extraction(int $version): array
    {
        if ($version !== 1) {
            throw new RuntimeException("Versão de schema de extração não suportada: {$version}.");
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary', 'main_topic', 'secondary_topics', 'identified_problem',
                'suggested_action', 'desired_result', 'affected_group', 'locality_text',
                'region', 'urgency', 'sentiment', 'keywords', 'confidence', 'requires_human_review',
                'review_reason',
            ],
            'properties' => [
                'summary' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'main_topic' => ['type' => 'string', 'maxLength' => 120],
                'secondary_topics' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => ['type' => 'string', 'maxLength' => 120],
                ],
                'identified_problem' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'suggested_action' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'desired_result' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'affected_group' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'locality_text' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'region' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'urgency' => ['type' => ['string', 'null'], 'enum' => InsightUrgency::values()],
                'sentiment' => ['type' => ['string', 'null'], 'enum' => InsightSentiment::values()],
                'keywords' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => ['type' => 'string', 'maxLength' => 60],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'requires_human_review' => ['type' => 'boolean'],
                'review_reason' => ['type' => ['string', 'null'], 'maxLength' => 255],
            ],
        ];
    }
}
