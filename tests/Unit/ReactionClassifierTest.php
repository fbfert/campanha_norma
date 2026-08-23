<?php

namespace Tests\Unit;

use App\Enums\PermissionResponseClassification;
use App\Services\ConversationAutomation\ReactionClassifier;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que cada emoji quer dizer.
 *
 * As listas vêm do seeder, e não do código: quem acompanha o que as pessoas
 * mandam é quem lê as conversas. O teste confere o comportamento com a lista
 * padrão, que é a que vai para produção.
 */
class ReactionClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    private function classificar(?string $emoji): PermissionResponseClassification
    {
        return app(ReactionClassifier::class)->classify($emoji)['classification'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('positivas')]
    public function test_reacao_positiva_autoriza(string $emoji): void
    {
        $this->assertSame(PermissionResponseClassification::PermissionYes, $this->classificar($emoji));
    }

    public static function positivas(): array
    {
        return [
            'polegar' => ['👍'],
            'polegar com tom de pele' => ['👍🏽'],
            'coração com seletor de variação' => ['❤️'],
            'coração sem seletor' => ['❤'],
            'palmas' => ['👏'],
            'certo' => ['✅'],
            'festa' => ['🎉'],
            'sorriso' => ['😀'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('negativas')]
    public function test_reacao_negativa_recusa(string $emoji): void
    {
        $this->assertSame(PermissionResponseClassification::PermissionNo, $this->classificar($emoji));
    }

    public static function negativas(): array
    {
        return [
            'polegar para baixo' => ['👎'],
            'polegar para baixo com tom de pele' => ['👎🏿'],
            'bravo' => ['😡'],
            'coração partido' => ['💔'],
            'errado' => ['❌'],
            'proibido' => ['🚫'],
        ];
    }

    /**
     * Recusar não é sair.
     *
     * Descadastro é irreversível para quem o sofre, e um toque errado no
     * teclado de emoji não pode produzi-lo. Nenhuma reação devolve `OptOut`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('negativas')]
    public function test_nenhuma_reacao_negativa_vira_opt_out(string $emoji): void
    {
        $this->assertNotSame(PermissionResponseClassification::OptOut, $this->classificar($emoji));
    }

    /**
     * Sequência composta cai para o emoji base: 🙅 mais ligador mais gênero
     * continua sendo alguém dizendo não.
     */
    public function test_sequencia_composta_cai_para_o_emoji_base(): void
    {
        $this->assertSame(PermissionResponseClassification::PermissionNo, $this->classificar("🙅\u{200D}♀\u{FE0F}"));
        $this->assertSame(PermissionResponseClassification::PermissionYes, $this->classificar("❤\u{FE0F}\u{200D}🔥"));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('semSignificado')]
    public function test_reacao_fora_das_listas_e_ambigua(?string $emoji): void
    {
        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classificar($emoji));
    }

    public static function semSignificado(): array
    {
        return [
            'nula' => [null],
            'vazia' => [''],
            'só espaço' => ['   '],
            'pensativo' => ['🤔'],
            'pizza' => ['🍕'],
            'texto' => ['sim'],
        ];
    }

    /**
     * Se alguém puser o mesmo emoji nas duas listas, o erro tem de cair para o
     * lado de não presumir consentimento. É a mesma precedência que
     * `PermissionResponseClassifier` aplica ao texto.
     */
    public function test_emoji_nas_duas_listas_conta_como_negativo(): void
    {
        app(\App\Services\SystemSettingService::class)->updateMany([
            'conversation_automation.positive_reactions' => '👍|🤝',
            'conversation_automation.negative_reactions' => '👎|🤝',
        ]);

        $this->assertSame(PermissionResponseClassification::PermissionNo, $this->classificar('🤝'));
    }

    /**
     * Lista vazia devolve o sistema ao comportamento anterior: reagir não
     * significa nada, e ninguém é inscrito por engano ao limpar o campo.
     */
    public function test_lista_vazia_desliga_a_leitura_de_reacao(): void
    {
        app(\App\Services\SystemSettingService::class)->updateMany([
            'conversation_automation.positive_reactions' => '',
            'conversation_automation.negative_reactions' => '',
        ]);

        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classificar('👍'));
        $this->assertFalse(app(ReactionClassifier::class)->isPositive('👍'));
    }
}
