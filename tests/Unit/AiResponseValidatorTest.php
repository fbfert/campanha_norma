<?php

namespace Tests\Unit;

use App\Services\Ai\AiResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes puros do validador de schema: sem banco, sem framework.
 *
 * O validador e a garantia de que a conformidade da saída nunca e presumida a
 * partir da promessa do provedor.
 */
class AiResponseValidatorTest extends TestCase
{
    private function validator(): AiResponseValidator
    {
        return new AiResponseValidator;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['classification', 'confidence', 'requires_human_review', 'review_reason'],
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => ['question_answer', 'ambiguous', 'opt_out']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'requires_human_review' => ['type' => 'boolean'],
                'review_reason' => ['type' => ['string', 'null'], 'maxLength' => 10],
            ],
        ];
    }

    public function test_accepts_a_conforming_response(): void
    {
        $result = $this->validator()->validate(
            '{"classification":"question_answer","confidence":0.93,"requires_human_review":false,"review_reason":null}',
            $this->schema()
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('question_answer', $result['data']['classification']);
        $this->assertSame([], $result['errors']);
    }

    public function test_accepts_an_integer_confidence(): void
    {
        $result = $this->validator()->validate(
            '{"classification":"ambiguous","confidence":1,"requires_human_review":true,"review_reason":"baixa"}',
            $this->schema()
        );

        $this->assertTrue($result['valid']);
    }

    public function test_unwraps_a_fenced_code_block(): void
    {
        $raw = "```json\n{\"classification\":\"opt_out\",\"confidence\":0.99,\"requires_human_review\":false,\"review_reason\":null}\n```";

        $result = $this->validator()->validate($raw, $this->schema());

        $this->assertTrue($result['valid']);
        $this->assertSame('opt_out', $result['data']['classification']);
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_non_conforming_responses(string $raw, string $expectedError): void
    {
        $result = $this->validator()->validate($raw, $this->schema());

        $this->assertFalse($result['valid'], 'Esperava saída invalida para: '.$raw);
        $this->assertNull($result['data']);
        $this->assertContains($expectedError, $result['errors']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidResponses(): array
    {
        return [
            'texto puro' => [
                'Não consegui classificar esta mensagem.',
                'resposta_nao_e_json_valido',
            ],
            'json truncado' => [
                '{"classification":"ambiguous","confidence":',
                'resposta_nao_e_json_valido',
            ],
            'campo obrigatório ausente' => [
                '{"classification":"ambiguous","confidence":0.5,"requires_human_review":false}',
                'review_reason:campo_obrigatorio_ausente',
            ],
            'categoria fora do conjunto' => [
                '{"classification":"inventada","confidence":0.5,"requires_human_review":false,"review_reason":null}',
                'classification:valor_fora_do_conjunto',
            ],
            'confiança acima de um' => [
                '{"classification":"ambiguous","confidence":1.4,"requires_human_review":false,"review_reason":null}',
                'confidence:acima_do_maximo',
            ],
            'confiança negativa' => [
                '{"classification":"ambiguous","confidence":-0.2,"requires_human_review":false,"review_reason":null}',
                'confidence:abaixo_do_minimo',
            ],
            'confiança como texto' => [
                '{"classification":"ambiguous","confidence":"alta","requires_human_review":false,"review_reason":null}',
                'confidence:tipo_invalido',
            ],
            'booleano como texto' => [
                '{"classification":"ambiguous","confidence":0.5,"requires_human_review":"sim","review_reason":null}',
                'requires_human_review:tipo_invalido',
            ],
            'campo desconhecido' => [
                '{"classification":"ambiguous","confidence":0.5,"requires_human_review":false,"review_reason":null,"voto":"partido"}',
                'voto:campo_desconhecido',
            ],
            'texto longo demais' => [
                '{"classification":"ambiguous","confidence":0.5,"requires_human_review":true,"review_reason":"motivo muito longo para o limite"}',
                'review_reason:texto_muito_longo',
            ],
        ];
    }

    public function test_rejects_a_list_when_an_object_is_expected(): void
    {
        $result = $this->validator()->validate('[1,2,3]', $this->schema());

        $this->assertFalse($result['valid']);
        $this->assertContains('raiz:tipo_invalido', $result['errors']);
    }

    public function test_validates_array_items_and_limits(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['keywords'],
            'properties' => [
                'keywords' => [
                    'type' => 'array',
                    'maxItems' => 2,
                    'items' => ['type' => 'string', 'maxLength' => 5],
                ],
            ],
        ];

        $tooMany = $this->validator()->validate('{"keywords":["a","b","c"]}', $schema);
        $this->assertFalse($tooMany['valid']);
        $this->assertContains('keywords:itens_demais', $tooMany['errors']);

        $wrongItemType = $this->validator()->validate('{"keywords":["ok",5]}', $schema);
        $this->assertFalse($wrongItemType['valid']);
        $this->assertContains('keywords[1]:tipo_invalido', $wrongItemType['errors']);

        $itemTooLong = $this->validator()->validate('{"keywords":["palavra"]}', $schema);
        $this->assertFalse($itemTooLong['valid']);
        $this->assertContains('keywords[0]:texto_muito_longo', $itemTooLong['errors']);

        $valid = $this->validator()->validate('{"keywords":["um","dois"]}', $schema);
        $this->assertTrue($valid['valid']);
    }
}
