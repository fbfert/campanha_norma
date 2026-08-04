<?php

namespace Tests\Feature;

use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nota de voz entra pela porta da frente.
 *
 * O tipo da mensagem era conferido contra uma lista fechada, e o vocabulário e
 * do WhatsApp, não nosso. Áudio gravado na hora chega como `ptt` — que ficou de
 * fora da lista — e era recusado na validação: nunca virava registro e nunca
 * disparava job nenhum.
 *
 * O efeito era invisível de propósito. Os áudios que existiam no banco tinham
 * entrado depois, pela sincronização, que não passa pelo normalizador. Quem
 * mandava áudio ficava sem resposta na hora e recebia, horas depois, o
 * agradecimento genérico da rede de segurança — como aconteceu na conversa 421.
 *
 * O que separa mensagem de pessoa de ruído de protocolo continua sendo
 * `PROTOCOL_TYPES`, conferido adiante com a mesma lista que a sincronização usa.
 */
class NotaDeVozChegaPelaPortaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nota_de_voz_e_aceita(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload(['message_type' => 'ptt']));

        $this->assertSame('ptt', $dados['message_type']);
        $this->assertTrue($dados['has_media']);
    }

    public function test_audio_anexado_tambem_e_aceito(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload(['message_type' => 'audio']));

        $this->assertSame('audio', $dados['message_type']);
    }

    /**
     * `chat` e o nome que o WhatsApp dá a mensagem de texto comum, e o motor de
     * fluxo só avalia o que estiver gravado como `text`.
     */
    public function test_chat_vira_texto(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload(['message_type' => 'chat']));

        $this->assertSame('text', $dados['message_type']);
    }

    /**
     * Tipo que ainda não existia quando isto foi escrito precisa chegar
     * inteiro. Recusar o desconhecido foi exatamente o que escondeu o `ptt`.
     */
    public function test_tipo_desconhecido_chega_inteiro_em_vez_de_sumir(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload(['message_type' => 'poll_creation']));

        $this->assertSame('poll_creation', $dados['message_type']);
    }

    public function test_tipo_em_maiusculas_e_normalizado(): void
    {
        $dados = app(IncomingMessageNormalizerService::class)->normalize($this->payload(['message_type' => 'PTT']));

        $this->assertSame('ptt', $dados['message_type']);
    }

    /**
     * A validação abriu, mas não sumiu: continua exigindo texto curto.
     */
    public function test_tipo_absurdo_continua_recusado(): void
    {
        $this->expectException(ValidationException::class);

        app(IncomingMessageNormalizerService::class)->normalize($this->payload([
            'message_type' => str_repeat('a', 41),
        ]));
    }

    /**
     * A cadeia inteira, do payload do webhook até a mensagem gravada.
     *
     * Os testes acima cobrem a validação, e o de resposta por escrito cobre o
     * job. Faltava o meio: a nota de voz precisa atravessar tudo e virar
     * registro, senão nada adiante chega a ser chamado.
     */
    public function test_nota_de_voz_atravessa_o_webhook_e_vira_registro(): void
    {
        $this->refreshDatabaseForIntegration();

        $contato = \App\Models\Contact::factory()->create([
            'phone_normalized' => '5549999990001',
            'status' => \App\Enums\ContactStatus::Active,
        ]);

        \App\Jobs\ProcessIncomingMessageJob::dispatchSync($this->payload([
            'message_type' => 'ptt',
            'has_media' => true,
        ]));

        $this->assertDatabaseHas('conversation_messages', [
            'contact_id' => $contato->id,
            'message_type' => 'ptt',
            'direction' => 'incoming',
            'has_media' => true,
        ]);
    }

    private function refreshDatabaseForIntegration(): void
    {
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'msg-'.uniqid(),
            'sender_phone' => '5549999990001',
            'recipient_phone' => '5549999990002',
            'message_type' => 'text',
            'text' => null,
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => true,
        ], $overrides);
    }
}
