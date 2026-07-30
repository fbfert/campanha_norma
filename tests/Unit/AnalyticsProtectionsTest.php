<?php

namespace Tests\Unit;

use App\Services\Analytics\ExportAnonymizer;
use App\Services\Analytics\SmallGroupSuppressor;
use App\Services\Reports\SpreadsheetValueSanitizer;
use App\Services\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Subetapa 9E: protecoes transversais.
 *
 * Sao tres regras puras que valem para toda tela e toda exportacao. Testadas
 * isoladamente porque, se qualquer uma falhar, ela falha em silencio: uma
 * planilha executa a formula sem avisar, uma celula pequena identifica sem
 * avisar, e um pseudonimo estavel entre arquivos permite cruzamento sem avisar.
 */
class AnalyticsProtectionsTest extends TestCase
{
    use RefreshDatabase;

    // --- Injecao de formula ---------------------------------------------------

    public static function dangerousCells(): array
    {
        return [
            'igual' => ['=1+1'],
            'mais' => ['+1'],
            'menos' => ['-1'],
            'arroba' => ['@SUM(A1)'],
            'tabulacao' => ["\tvalor"],
            'retorno' => ["\rvalor"],
            'comando' => ['=cmd|\' /C calc\'!A0'],
        ];
    }

    #[DataProvider('dangerousCells')]
    public function test_a_dangerous_cell_is_prefixed_as_text(string $value): void
    {
        $sanitized = (new SpreadsheetValueSanitizer)->value($value);

        $this->assertSame("'".$value, $sanitized);
    }

    public function test_an_ordinary_cell_passes_untouched(): void
    {
        $sanitizer = new SpreadsheetValueSanitizer;

        $this->assertSame('Saude publica', $sanitizer->value('Saude publica'));
        $this->assertSame('', $sanitizer->value(''));
        $this->assertNull($sanitizer->value(null));
    }

    /**
     * Numero precisa continuar numero: transformar em texto quebraria soma na
     * planilha, e o risco de formula esta em texto, nao em inteiro.
     */
    public function test_numbers_are_not_converted_to_text(): void
    {
        $sanitizer = new SpreadsheetValueSanitizer;

        $this->assertSame(42, $sanitizer->value(42));
        $this->assertSame(-7, $sanitizer->value(-7));
        $this->assertSame(1.5, $sanitizer->value(1.5));
    }

    public function test_a_whole_row_is_sanitized_preserving_keys(): void
    {
        $row = (new SpreadsheetValueSanitizer)->row(['tema' => '=HYPERLINK("x")', 'total' => 10]);

        $this->assertSame(['tema' => '\'=HYPERLINK("x")', 'total' => 10], $row);
    }

    // --- Supressao de grupo pequeno -------------------------------------------

    public function test_a_cell_below_the_minimum_is_suppressed(): void
    {
        app(SystemSettingService::class)->updateMany(['analytics.minimum_cell_size' => '5']);
        $suppressor = app(SmallGroupSuppressor::class);

        $this->assertNull($suppressor->count(4));
        $this->assertSame(5, $suppressor->count(5));
        $this->assertSame(120, $suppressor->count(120));
    }

    /**
     * Zero nao identifica ninguem, e transformar zero em suprimido esconderia
     * ausencia de dado — que costuma ser a informacao mais importante da tela.
     */
    public function test_zero_is_never_suppressed(): void
    {
        $this->assertSame(0, app(SmallGroupSuppressor::class)->count(0));
    }

    public function test_the_minimum_follows_the_configured_value(): void
    {
        app(SystemSettingService::class)->updateMany(['analytics.minimum_cell_size' => '20']);
        $suppressor = app(SmallGroupSuppressor::class);

        $this->assertNull($suppressor->count(19));
        $this->assertSame(20, $suppressor->count(20));
    }

    /**
     * Linha suprimida continua na lista. Remove-la faria a soma das visiveis
     * nao bater com o total, e quem lesse concluiria que faltam registros.
     */
    public function test_a_suppressed_row_stays_in_the_list_marked(): void
    {
        app(SystemSettingService::class)->updateMany(['analytics.minimum_cell_size' => '5']);

        $rows = app(SmallGroupSuppressor::class)->rows([
            ['name' => 'Saude', 'total' => 40],
            ['name' => 'Vila pequena', 'total' => 2],
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(40, $rows[0]['total']);
        $this->assertFalse($rows[0]['suppressed']);
        $this->assertNull($rows[1]['total']);
        $this->assertTrue($rows[1]['suppressed']);
    }

    // --- Anonimizacao ---------------------------------------------------------

    public function test_the_phone_keeps_only_the_last_four_digits(): void
    {
        $anonymizer = new ExportAnonymizer;

        $this->assertSame('****8242', $anonymizer->maskPhone('554991888242'));
        $this->assertSame('****8242', $anonymizer->maskPhone('+55 (49) 9188-8242'));
        $this->assertNull($anonymizer->maskPhone(null));
        $this->assertNull($anonymizer->maskPhone('sem digitos'));
    }

    public function test_the_name_never_survives(): void
    {
        $this->assertSame('', (new ExportAnonymizer)->removeName('Maria da Silva'));
    }

    public function test_the_pseudonym_is_stable_inside_one_export(): void
    {
        $anonymizer = new ExportAnonymizer;
        $salt = $anonymizer->newSalt();

        $this->assertSame(
            $anonymizer->pseudonym($salt, 42),
            $anonymizer->pseudonym($salt, 42),
        );
    }

    /**
     * O ponto que sustenta a anonimizacao inteira: com sal fixo, duas
     * exportacoes de periodos diferentes teriam o mesmo pseudonimo para a mesma
     * pessoa, e cruzar as duas reconstruiria o historico dela.
     */
    public function test_two_exports_never_produce_the_same_pseudonym(): void
    {
        $anonymizer = new ExportAnonymizer;

        $this->assertNotSame(
            $anonymizer->pseudonym($anonymizer->newSalt(), 42),
            $anonymizer->pseudonym($anonymizer->newSalt(), 42),
        );
    }

    public function test_the_pseudonym_does_not_contain_the_identifier(): void
    {
        $anonymizer = new ExportAnonymizer;
        $pseudonym = $anonymizer->pseudonym($anonymizer->newSalt(), 123456);

        $this->assertStringNotContainsString('123456', $pseudonym);
        $this->assertSame(16, strlen($pseudonym));
    }
}
