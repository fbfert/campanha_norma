<?php

namespace Tests\Feature;

use App\Models\SendingSetting;
use App\Services\MessageProcessing\SendingWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dias permitidos para envio.
 *
 * O formulário gravava `["1","2","3","4","5","6"]` — textos. A janela compara
 * com `in_array($agora->dayOfWeekIso, ..., true)`, comparação estrita, e
 * `5 !== '5'`. Resultado: **nenhum dia era permitido, em nenhum lote**. Todo
 * destinatário parava em "Dia não permitido para envio", e nada denunciava a
 * causa — a tela mostrava os dias marcados corretamente.
 *
 * Levou dias para aparecer porque o sintoma parecia configuração: quem lê
 * "dia não permitido" confere os dias na tela, vê tudo certo, e não suspeita
 * do tipo do valor.
 */
class JanelaDeEnvioDiaPermitidoTest extends TestCase
{
    use RefreshDatabase;

    private function configuracao(array $dias): SendingSetting
    {
        $settings = SendingSetting::query()->create([
            'max_per_minute' => 1,
            'max_per_hour' => 15,
            'max_per_day' => 40,
            'minimum_interval_seconds' => 60,
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'timezone' => 'America/Sao_Paulo',
            'max_attempts' => 3,
            'retry_interval_minutes' => 15,
        ]);

        // Grava direto na coluna para reproduzir o que já esta no banco: o
        // acessor normalizaria antes de salvar, e o teste perderia a graça.
        DB::table('sending_settings')->where('id', $settings->id)->update([
            'allowed_weekdays' => json_encode($dias),
        ]);

        return $settings->fresh();
    }

    private function numaSexta(): CarbonImmutable
    {
        // 31/07/2026, sexta-feira, 19h — o dia e a hora do caso relatado.
        return CarbonImmutable::create(2026, 7, 31, 19, 0, 0, 'America/Sao_Paulo');
    }

    /**
     * O caso exato que estava em produção.
     */
    public function test_dia_gravado_como_texto_continua_valendo(): void
    {
        $settings = $this->configuracao(['1', '2', '3', '4', '5', '6']);

        $resultado = app(SendingWindowService::class)->check($settings, $this->numaSexta());

        $this->assertTrue(
            $resultado['allowed'],
            'Sexta esta na lista de dias permitidos; gravada como texto, precisa continuar valendo.'
        );
    }

    public function test_o_acessor_devolve_numeros_mesmo_com_texto_gravado(): void
    {
        $settings = $this->configuracao(['1', '5', '6']);

        $this->assertSame([1, 5, 6], $settings->allowed_weekdays);
    }

    public function test_o_que_e_gravado_ja_sai_como_numero(): void
    {
        $settings = $this->configuracao([1, 2, 3]);
        $settings->update(['allowed_weekdays' => ['2', '4']]);

        $this->assertSame([2, 4], $settings->fresh()->allowed_weekdays);
        $this->assertSame('[2,4]', DB::table('sending_settings')->where('id', $settings->id)->value('allowed_weekdays'));
    }

    /**
     * A correção não pode transformar "dia bloqueado" em "dia liberado": um dia
     * fora da lista continua fora.
     */
    public function test_dia_fora_da_lista_continua_bloqueado(): void
    {
        // Sexta e o dia 5; a lista tem tudo menos ele.
        $settings = $this->configuracao(['1', '2', '3', '4', '6', '7']);

        $resultado = app(SendingWindowService::class)->check($settings, $this->numaSexta());

        $this->assertFalse($resultado['allowed']);
        $this->assertSame('Dia não permitido para envio.', $resultado['reason']);
    }

    public function test_lista_vazia_bloqueia_todos_os_dias(): void
    {
        $settings = $this->configuracao([]);

        $this->assertFalse(app(SendingWindowService::class)->check($settings, $this->numaSexta())['allowed']);
    }

    /**
     * Valor sujo não pode virar dia válido: `(int) 'sabado'` daria zero, e zero
     * não e dia nenhum — mas também não pode derrubar a leitura.
     */
    public function test_valor_invalido_e_descartado_sem_quebrar(): void
    {
        $settings = $this->configuracao(['5', 'sabado', null, '5']);

        $this->assertSame([5], $settings->allowed_weekdays);
        $this->assertTrue(app(SendingWindowService::class)->check($settings, $this->numaSexta())['allowed']);
    }
}
