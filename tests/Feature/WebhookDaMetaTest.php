<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Webhook da API oficial da Meta.
 *
 * Os corpos aqui são os formatos que a Cloud API envia de verdade: um envelope
 * com várias mensagens por requisição, confirmações de entrega num campo
 * separado, e assinatura HMAC do corpo cru.
 */
class WebhookDaMetaTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'segredo-do-app';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('whatsapp.meta.app_secret', self::SEGREDO);
        Config::set('whatsapp.meta.verify_token', 'token-combinado');
        Queue::fake();
    }

    // --- Verificação por desafio ---------------------------------------------

    /**
     * A Meta espera o desafio de volta em texto puro. Devolver JSON faz o
     * cadastro do webhook falhar sem explicar por quê.
     */
    public function test_o_desafio_volta_em_texto_puro(): void
    {
        $this->get(route('internal.whatsapp.meta.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'token-combinado',
            'hub_challenge' => '1158201444',
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('1158201444');
    }

    public function test_token_errado_nao_verifica(): void
    {
        $this->get(route('internal.whatsapp.meta.verify', [
            'hub_verify_token' => 'chute',
            'hub_challenge' => '123',
        ]))->assertForbidden();
    }

    // --- Assinatura ----------------------------------------------------------

    public function test_sem_assinatura_valida_nada_entra(): void
    {
        $this->postJson(route('internal.whatsapp.meta.receive'), $this->payload(), [
            'X-Hub-Signature-256' => 'sha256=invento',
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * Sem segredo configurado, tudo é recusado. Aceitar seria abrir a porta
     * para qualquer um enfileirar mensagem em nome de um eleitor.
     */
    public function test_sem_segredo_configurado_tudo_e_recusado(): void
    {
        Config::set('whatsapp.meta.app_secret', '');

        $this->enviar($this->payload())->assertStatus(401);

        Queue::assertNothingPushed();
    }

    // --- Tradução ------------------------------------------------------------

    public function test_mensagem_de_texto_vira_o_formato_do_sistema(): void
    {
        $this->enviar($this->payload())->assertOk();

        Queue::assertPushed(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job): bool {
            $dados = $this->payloadDoJob($job);

            return $dados['provider'] === 'meta'
                && $dados['sender_phone'] === '5549991613378'
                && $dados['sender_name'] === 'Fabielle'
                && $dados['text'] === 'Boa tarde'
                && $dados['message_type'] === 'text'
                && $dados['is_from_me'] === false
                && $dados['is_group'] === false;
        });
    }

    /**
     * Uma requisição pode trazer várias mensagens, e tratar só a primeira
     * perderia as outras em silêncio.
     */
    public function test_varias_mensagens_no_mesmo_envelope_viram_varios_jobs(): void
    {
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'][] = [
            'from' => '5549991613378',
            'id' => 'wamid.SEGUNDA',
            'timestamp' => '1786000100',
            'type' => 'text',
            'text' => ['body' => 'Pode perguntar'],
        ];

        $this->enviar($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingMessageJob::class, 2);
    }

    /**
     * A Meta reenvia enquanto não receber 200. Sortear o identificador do
     * evento faria a mesma mensagem entrar duas vezes, porque é por ele que a
     * duplicidade é conferida.
     */
    public function test_reenvio_produz_o_mesmo_identificador_de_evento(): void
    {
        $this->enviar($this->payload())->assertOk();
        $this->enviar($this->payload())->assertOk();

        $identificadores = [];

        Queue::assertPushed(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job) use (&$identificadores): bool {
            $identificadores[] = $this->payloadDoJob($job)['event_id'];

            return true;
        });

        $this->assertCount(1, array_unique($identificadores), 'O reenvio precisa produzir o mesmo identificador.');
    }

    /**
     * Legenda de imagem é texto. Ignorá-la faria a foto com a pergunta escrita
     * embaixo virar mídia muda.
     */
    public function test_legenda_de_imagem_vira_texto(): void
    {
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'] = [[
            'from' => '5549991613378',
            'id' => 'wamid.IMG',
            'timestamp' => '1786000000',
            'type' => 'image',
            'image' => ['id' => '1234', 'mime_type' => 'image/jpeg', 'caption' => 'Olha o buraco na minha rua'],
        ]];

        $this->enviar($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job): bool {
            $dados = $this->payloadDoJob($job);

            return $dados['message_type'] === 'image'
                && $dados['has_media'] === true
                && $dados['text'] === 'Olha o buraco na minha rua'
                && $dados['metadata']['media_id'] === '1234';
        });
    }

    /**
     * Nota de voz chega como `audio` com `voice`, e não como tipo próprio: sem
     * isso não dá para distingui-la de um arquivo anexado.
     */
    public function test_nota_de_voz_e_marcada_como_tal(): void
    {
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'] = [[
            'from' => '5549991613378',
            'id' => 'wamid.AUDIO',
            'timestamp' => '1786000000',
            'type' => 'audio',
            'audio' => ['id' => '999', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true],
        ]];

        $this->enviar($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job): bool {
            $dados = $this->payloadDoJob($job);

            return $dados['message_type'] === 'audio' && $dados['metadata']['voice'] === true;
        });
    }

    /**
     * Reação é um emoji, e é o que a pessoa disse.
     *
     * O alvo vem em `reaction.message_id`, e não em `context.id`: ler só o
     * segundo descartava a mensagem reagida, e sem ela a reação chega como um
     * emoji pairando sobre coisa nenhuma — impossível saber se responde à
     * pergunta de permissão ou a uma mensagem de três semanas atrás.
     */
    public function test_reacao_vira_o_emoji(): void
    {
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'] = [[
            'from' => '5549991613378',
            'id' => 'wamid.REACT',
            'timestamp' => '1786000000',
            'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.ANTERIOR', 'emoji' => '👍'],
        ]];

        $this->enviar($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingMessageJob::class, fn (ProcessIncomingMessageJob $job): bool => $this->payloadDoJob($job)['text'] === '👍');

        Queue::assertPushed(
            ProcessIncomingMessageJob::class,
            fn (ProcessIncomingMessageJob $job): bool => $this->payloadDoJob($job)['quoted_external_message_id'] === 'wamid.ANTERIOR',
        );
    }

    /**
     * Confirmação de entrega ainda não é aplicada, mas não pode virar mensagem
     * recebida: ela é o eco do que nós mandamos.
     */
    public function test_confirmacao_de_entrega_nao_vira_mensagem(): void
    {
        $this->enviar([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA', 'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['display_phone_number' => '5549988882424', 'phone_number_id' => '123'],
                    'statuses' => [[
                        'id' => 'wamid.ENVIADA',
                        'status' => 'delivered',
                        'timestamp' => '1786000000',
                        'recipient_id' => '5549991613378',
                    ]],
                ],
            ]]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    // --- Ajudantes -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function payloadDoJob(ProcessIncomingMessageJob $job): array
    {
        $propriedade = new \ReflectionProperty($job, 'payload');
        $propriedade->setAccessible(true);

        return $propriedade->getValue($job);
    }

    /** @param array<string, mixed> $payload */
    private function enviar(array $payload): \Illuminate\Testing\TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call(
            'POST',
            route('internal.whatsapp.meta.receive'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $corpo, self::SEGREDO),
            ],
            $corpo,
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '5549988882424', 'phone_number_id' => '123456789'],
                        'contacts' => [['profile' => ['name' => 'Fabielle'], 'wa_id' => '5549991613378']],
                        'messages' => [[
                            'from' => '5549991613378',
                            'id' => 'wamid.HBgNNTU0OTk5MTYxMzM3OA',
                            'timestamp' => '1786000000',
                            'type' => 'text',
                            'text' => ['body' => 'Boa tarde'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
