<?php

namespace Tests\Feature;

use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Horário da mensagem no fuso da aplicação.
 *
 * O serviço Node entrega ISO-8601 em UTC. `Carbon::parse` preserva o fuso da
 * string, e o valor ia para o banco em Greenwich: uma mensagem recebida as 19h
 * aparecia como 22h na tela, enquanto `created_at` — gravado pelo próprio
 * Laravel — mostrava 19h. Duas horas diferentes para o mesmo evento, na mesma
 * linha, e nenhuma pista de qual estava certa.
 */
class HorarioDaMensagemNoFusoCertoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        config(['app.timezone' => 'America/Sao_Paulo']);
    }

    public function test_horario_em_utc_vira_horario_local(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload([
            'sent_at' => '2026-08-03T22:14:38.000Z',
            'received_at' => '2026-08-03T22:14:40.000Z',
        ]));

        $this->assertSame('19:14:38', $dados['sent_at']->format('H:i:s'));
        $this->assertSame('19:14:40', $dados['received_at']->format('H:i:s'));
    }

    /**
     * O instante não muda — só a forma de escrevê-lo. Converter fuso não pode
     * virar deslocar o evento no tempo.
     */
    public function test_o_instante_permanece_o_mesmo(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload([
            'sent_at' => '2026-08-03T22:14:38.000Z',
        ]));

        $this->assertSame(
            (new \DateTimeImmutable('2026-08-03T22:14:38+00:00'))->getTimestamp(),
            $dados['sent_at']->getTimestamp(),
        );
    }

    /**
     * Horário já entregue com deslocamento local continua valendo: converter
     * duas vezes tiraria mais três horas.
     */
    public function test_horario_ja_local_nao_e_deslocado_de_novo(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload([
            'sent_at' => '2026-08-03T19:14:38-03:00',
        ]));

        $this->assertSame('19:14:38', $dados['sent_at']->format('H:i:s'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'msg-'.uniqid(),
            'sender_phone' => '5549999990001',
            'recipient_phone' => '5549999990002',
            'message_type' => 'text',
            'text' => 'Falta praça no bairro.',
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => false,
        ], $overrides);
    }
}
