<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\QrCodeResult;
use App\Data\WhatsApp\SendResult;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\SendManualConversationReplyJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationResolverService;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use App\Services\IncomingMessages\IncomingWebhookSignatureService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InboxIncomingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
        Config::set('whatsapp.incoming.secret', 'segredo-interno');
        Config::set('queue.default', 'database');
    }

    public function test_webhook_assinado_enfileira_evento_e_rejeita_assinatura_invalida(): void
    {
        Queue::fake();
        $payload = $this->incomingPayload();
        $headers = $this->signedHeaders($payload, 'nonce-1');

        $this->postJson(route('internal.whatsapp.incoming'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(ProcessIncomingMessageJob::class);

        $this->postJson(route('internal.whatsapp.incoming'), $payload, [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => 'nonce-2',
            'X-Webhook-Signature' => 'sha256=invalida',
        ])->assertUnauthorized();
    }

    public function test_replay_timestamp_antigo_e_content_type_invalido_sao_rejeitados(): void
    {
        $payload = $this->incomingPayload();
        $headers = $this->signedHeaders($payload, 'nonce-replay');

        $this->postJson(route('internal.whatsapp.incoming'), $payload, $headers)->assertOk();
        $this->postJson(route('internal.whatsapp.incoming'), $payload, $headers)->assertUnauthorized();

        $old = $this->signedHeaders($payload, 'nonce-old', now()->subMinutes(20)->timestamp);
        $this->postJson(route('internal.whatsapp.incoming'), $payload, $old)->assertUnauthorized();

        $this->post(route('internal.whatsapp.incoming'), $payload, ['Content-Type' => 'text/plain'])->assertStatus(415);
    }

    public function test_processamento_cria_conversa_marca_resposta_e_interrompe_pendentes(): void
    {
        $contact = Contact::factory()->create(['phone' => '(49) 99999-9999', 'phone_normalized' => '5549999999999', 'has_replied' => false]);
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);
        $pending = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'contact_phone_snapshot' => '5549999999999',
            'processing_status' => MessageRecipientProcessingStatus::Pending,
        ]);
        $sentBatch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Completed]);
        $sent = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $sentBatch->id,
            'contact_id' => $contact->id,
            'contact_phone_snapshot' => '5549999999999',
            'processing_status' => MessageRecipientProcessingStatus::Sent,
            'sent_at' => now()->subMinute(),
        ]);

        (new ProcessIncomingMessageJob($this->incomingPayload()))->handle(
            app(IncomingMessageNormalizerService::class),
            app(ContactMatcherService::class),
            app(ConversationResolverService::class),
            app(ReplyInterruptionService::class),
            app(ConversationEventService::class),
            app(AuditLogger::class),
        );

        $this->assertDatabaseHas('conversations', ['contact_id' => $contact->id, 'status' => 'waiting_operator', 'unread_count' => 1]);
        $this->assertDatabaseHas('conversation_messages', ['external_message_id' => 'wamid.in.1', 'direction' => 'incoming', 'body' => 'Sim, pode perguntar.']);
        $this->assertTrue($contact->fresh()->has_replied);
        $this->assertSame('skipped', $pending->fresh()->processing_status->value);
        $this->assertSame('CONTACT_REPLIED', $pending->fresh()->error_code);
        $this->assertSame('sent', $sent->fresh()->processing_status->value);
    }

    public function test_idempotencia_nao_duplica_mensagem_conversa_ou_interrupcao(): void
    {
        $contact = Contact::factory()->create(['phone_normalized' => '5549999999999']);
        MessageBatchRecipient::factory()->create(['contact_id' => $contact->id, 'contact_phone_snapshot' => '5549999999999', 'processing_status' => MessageRecipientProcessingStatus::Pending]);
        $job = new ProcessIncomingMessageJob($this->incomingPayload());

        $dependencies = [
            app(IncomingMessageNormalizerService::class),
            app(ContactMatcherService::class),
            app(ConversationResolverService::class),
            app(ReplyInterruptionService::class),
            app(ConversationEventService::class),
            app(AuditLogger::class),
        ];
        $job->handle(...$dependencies);
        $job->handle(...$dependencies);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ConversationMessage::where('external_message_id', 'wamid.in.1')->count());
    }

    public function test_caixa_marca_lida_atribui_e_adiciona_nota(): void
    {
        $admin = $this->userWithRole('administrador');
        $conversation = Conversation::factory()->create(['unread_count' => 1, 'assigned_user_id' => null]);
        ConversationMessage::factory()->create(['conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id, 'read_at' => null]);

        $this->actingAs($admin)->get(route('admin.inbox.index'))->assertOk()->assertSee('Caixa de entrada');
        $this->actingAs($admin)->get(route('admin.inbox.show', $conversation))->assertOk()->assertSee('Mensagem recebida de teste');

        $this->assertSame(0, $conversation->fresh()->unread_count);
        $this->actingAs($admin)->post(route('admin.inbox.assign', $conversation))->assertRedirect();
        $this->assertSame($admin->id, $conversation->fresh()->assigned_user_id);

        $this->actingAs($admin)->post(route('admin.inbox.notes.store', $conversation), ['body' => 'Pessoa pediu retorno.'])->assertRedirect();
        $this->assertDatabaseHas('conversation_notes', ['conversation_id' => $conversation->id, 'body' => 'Pessoa pediu retorno.']);
    }

    public function test_resposta_manual_cria_mensagem_e_job_envia_pelo_provider(): void
    {
        Queue::fake();
        $operator = $this->userWithRole('operador');
        $contact = Contact::factory()->create(['status' => 'active', 'do_not_contact' => false, 'phone_normalized' => '5549999999999']);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'assigned_user_id' => $operator->id]);

        $this->actingAs($operator)->post(route('admin.inbox.reply', $conversation), ['body' => 'Resposta manual.'])->assertRedirect();
        Queue::assertPushed(SendManualConversationReplyJob::class);
        $message = ConversationMessage::where('direction', 'outgoing')->firstOrFail();
        $this->assertSame('pending', $message->status->value);

        $provider = new class implements WhatsAppProvider
        {
            public function getStatus(): ConnectionStatus
            {
                return new ConnectionStatus(WhatsAppConnectionStatus::Connected);
            }

            public function requestQrCode(): QrCodeResult
            {
                throw new \RuntimeException('unused');
            }

            public function connect(): ConnectionResult
            {
                throw new \RuntimeException('unused');
            }

            public function reconnect(): ConnectionResult
            {
                throw new \RuntimeException('unused');
            }

            public function disconnect(): ConnectionResult
            {
                throw new \RuntimeException('unused');
            }

            public function clearSession(): ConnectionResult
            {
                throw new \RuntimeException('unused');
            }

            public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
            {
                return $this->sendMessage($phone, $message, $requestId);
            }

            public function sendMessage(string $phone, string $message, string $requestId): SendResult
            {
                return new SendResult($requestId, 'sent', 'wamid.manual.1', now()->toImmutable());
            }
        };
        $manager = $this->mock(WhatsAppProviderManager::class);
        $manager->shouldReceive('provider')->andReturn($provider);

        (new SendManualConversationReplyJob($message->id))->handle(
            $manager,
            app(ConversationEventService::class),
            app(AuditLogger::class),
        );

        $this->assertSame(ConversationMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(ConversationStatus::WaitingContact, $conversation->fresh()->status);
    }

    public function test_consulta_nao_responde(): void
    {
        $reader = $this->userWithRole('consulta');
        $conversation = Conversation::factory()->create();

        $this->actingAs($reader)->get(route('admin.inbox.index'))->assertOk();
        $this->actingAs($reader)->post(route('admin.inbox.reply', $conversation), ['body' => 'Nao pode'])->assertForbidden();
    }

    private function signedHeaders(array $payload, string $nonce, ?int $timestamp = null): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();
        $signature = app(IncomingWebhookSignatureService::class)->sign((string) $raw, (string) $timestamp, $nonce, 'segredo-interno');

        return [
            'Content-Type' => 'application/json',
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => $signature,
        ];
    }

    private function incomingPayload(): array
    {
        return [
            'event_id' => '2f2bce01-e176-4f06-8a2d-e23e147c01a4',
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'wamid.in.1',
            'sender_phone' => '5549999999999',
            'sender_name' => 'Mariana',
            'recipient_phone' => '5549888888888',
            'message_type' => 'text',
            'text' => 'Sim, pode perguntar.',
            'sent_at' => now()->toIso8601String(),
            'received_at' => now()->toIso8601String(),
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => false,
            'quoted_external_message_id' => null,
            'metadata' => [],
        ];
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
