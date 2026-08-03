<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O seeder de configuração não desfaz o que o operador escolheu.
 *
 * Ele era `updateOrCreate` com o registro inteiro. Rodar o seeder para
 * acrescentar uma chave nova devolvia todas as outras ao padrão de fabrica — e
 * foi o que aconteceu em produção: a automação foi desligada no meio de uma
 * pesquisa, e duas pessoas responderam "Sim" sem receber nada de volta.
 *
 * O comando não avisa: ele imprime "Seeding database" e termina com sucesso. A
 * única defesa possível e o seeder nunca tocar em valor existente.
 */
class SeederNaoSobrescreveConfiguracaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_valor_em_uso_sobrevive_a_uma_nova_execucao(): void
    {
        $this->seed(SystemSettingSeeder::class);

        SystemSetting::query()->where('key', 'conversation_automation.enabled')->update(['value' => '1']);
        SystemSetting::query()->where('key', 'ai.response.max_followups')->update(['value' => '15']);

        $this->seed(SystemSettingSeeder::class);

        $this->assertSame('1', SystemSetting::query()->where('key', 'conversation_automation.enabled')->value('value'));
        $this->assertSame('15', SystemSetting::query()->where('key', 'ai.response.max_followups')->value('value'));
    }

    public function test_chave_nova_continua_sendo_criada(): void
    {
        $this->seed(SystemSettingSeeder::class);

        SystemSetting::query()->where('key', 'conversation_automation.enabled')->delete();

        $this->seed(SystemSettingSeeder::class);

        $this->assertSame('0', SystemSetting::query()->where('key', 'conversation_automation.enabled')->value('value'));
    }

    /**
     * Grupo, tipo e descrição continuam acompanhando o código: são metadados, e
     * mudam quando o sistema muda. O valor e do operador.
     */
    public function test_os_metadados_continuam_sendo_atualizados(): void
    {
        $this->seed(SystemSettingSeeder::class);

        SystemSetting::query()->where('key', 'conversation_automation.enabled')->update([
            'value' => '1',
            'description' => 'descrição antiga',
            'group' => 'grupo_errado',
        ]);

        $this->seed(SystemSettingSeeder::class);

        $registro = SystemSetting::query()->where('key', 'conversation_automation.enabled')->first();

        $this->assertSame('1', $registro->value);
        $this->assertSame('conversation_automation', $registro->group);
        $this->assertNotSame('descrição antiga', $registro->description);
    }
}
