<?php

namespace App\Services\Ai;

use App\Enums\AiRunPurpose;
use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Enums\MessageClassification;
use App\Services\SystemSettingService;
use RuntimeException;

/**
 * Schemas JSON por finalidade e versao.
 *
 * O mesmo schema e enviado ao provedor e aplicado localmente pelo validador.
 * Nenhum campo para atributo sensivel existe aqui: a ausencia do campo e a
 * garantia estrutural de que o modelo nao tem onde escrever esse dado.
 */
class AiSchemaRegistry
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function activeVersion(AiRunPurpose $purpose): int
    {
        $key = match ($purpose) {
            AiRunPurpose::Classify => 'ai.classification_schema_version',
            AiRunPurpose::ExtractInsight => 'ai.extraction_schema_version',
        };

        return max(1, (int) $this->settings->get($key, 1));
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
        };
    }

    /** @return array<string, mixed> */
    private function classification(int $version): array
    {
        if ($version !== 1) {
            throw new RuntimeException("Versao de schema de classificacao nao suportada: {$version}.");
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

    /** @return array<string, mixed> */
    private function extraction(int $version): array
    {
        if ($version !== 1) {
            throw new RuntimeException("Versao de schema de extracao nao suportada: {$version}.");
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
