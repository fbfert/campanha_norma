<?php

namespace Tests\Feature;

use App\Actions\MessageBatches\StartMessageBatchAction;
use App\Enums\ContactStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\SendingSetting;
use App\Models\User;
use App\Services\MessageProcessing\RecipientProcessingService;
use App\Services\MessageProcessing\SendingWindowService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageProcessingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
    }

    public function test_administrador_acessa_e_atualiza_configuracoes_de_envio(): void
    {
        $admin = $this->userWithRole('administrador');
        $operator = $this->userWithRole('operador');

        $this->actingAs($admin)->get(route('admin.message-settings.edit'))->assertOk();
        $this->actingAs($operator)->put(route('admin.message-settings.update'), $this->settingsPayload())->assertForbidden();

        $this->actingAs($admin)->put(route('admin.message-settings.update'), $this->settingsPayload([
            'max_per_minute' => 20,
            'max_per_hour' => 10,
        ]))->assertSessionHasErrors('max_per_minute');

        $this->actingAs($admin)->put(route('admin.message-settings.update'), $this->settingsPayload())->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_settings.updated']);
    }

    public function test_janela_de_envio_trata_dias_horarios_e_meia_noite(): void
    {
        $service = app(SendingWindowService::class);
        $settings = SendingSetting::factory()->create([
            'allowed_weekdays' => [1, 2, 3, 4, 5],
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $this->assertTrue($service->check($settings, Carbon::parse('2026-07-24 10:00:00', 'America/Sao_Paulo')->toImmutable())['allowed']);
        $this->assertFalse($service->check($settings, Carbon::parse('2026-07-25 10:00:00', 'America/Sao_Paulo')->toImmutable())['allowed']);
        $this->assertFalse($service->check($settings, Carbon::parse('2026-07-24 08:00:00', 'America/Sao_Paulo')->toImmutable())['allowed']);

        $night = SendingSetting::factory()->create([
            'allowed_weekdays' => [5],
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
        ]);

        $this->assertTrue($service->check($night, Carbon::parse('2026-07-24 23:00:00', 'America/Sao_Paulo')->toImmutable())['allowed']);
        $this->assertTrue($service->check($night, Carbon::parse('2026-07-25 01:00:00', 'America/Sao_Paulo')->toImmutable())['allowed']);
    }

    public function test_lote_pronto_pode_iniciar_e_gera_request_id(): void
    {
        Queue::fake();
        $admin = $this->userWithRole('administrador');
        $batch = $this->readyBatch();

        $this->actingAs($admin)->post(route('admin.message-batches.start', $batch))->assertRedirect();

        $batch->refresh();
        $this->assertSame(MessageBatchStatus::Queued, $batch->status);
        $this->assertSame(MessageRecipientProcessingStatus::Pending, $batch->recipients()->first()->processing_status);
        $this->assertNotNull($batch->recipients()->first()->request_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_batch.started']);
        Queue::assertPushed(DispatchMessageBatchJob::class);
    }

    public function test_lote_rascunho_nao_pode_iniciar_e_inicio_duplicado_e_rejeitado(): void
    {
        // O teste é sobre recusar o início duplicado, e não sobre enviar. Sem
        // isto o despacho roda inline e vai falar com o serviço do WhatsApp de
        // verdade — era o que acontecia antes de `preventStrayRequests`.
        Queue::fake();

        $admin = $this->userWithRole('administrador');
        $draft = MessageBatch::factory()->create(['status' => MessageBatchStatus::Draft, 'eligible_total' => 1]);

        $this->actingAs($admin)->post(route('admin.message-batches.start', $draft))->assertSessionHas('error');

        $ready = $this->readyBatch();
        app(StartMessageBatchAction::class)->execute($ready, $admin);

        $this->actingAs($admin)->post(route('admin.message-batches.start', $ready->fresh()))->assertSessionHas('error');
    }

    public function test_pausa_retomada_parada_e_cancelamento_de_destinatario(): void
    {
        Queue::fake();
        $admin = $this->userWithRole('administrador');
        $batch = app(StartMessageBatchAction::class)->execute($this->readyBatch(), $admin);

        $this->actingAs($admin)->post(route('admin.message-batches.pause', $batch))->assertRedirect();
        $this->assertSame(MessageBatchStatus::Paused, $batch->fresh()->status);

        $this->actingAs($admin)->post(route('admin.message-batches.resume', $batch->fresh()))->assertRedirect();
        $this->assertSame(MessageBatchStatus::Queued, $batch->fresh()->status);

        $recipient = $batch->fresh()->recipients()->first();
        $this->actingAs($admin)->post(route('admin.message-batches.recipients.cancel', [$batch, $recipient]))->assertRedirect();
        $this->assertSame(MessageRecipientProcessingStatus::Cancelled, $recipient->fresh()->processing_status);

        $second = $this->readyBatch();
        $started = app(StartMessageBatchAction::class)->execute($second, $admin);
        $this->actingAs($admin)->post(route('admin.message-batches.stop', $started), ['reason' => 'Teste'])->assertRedirect();
        $this->assertSame(MessageBatchStatus::Stopped, $started->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_batch.stopped']);
    }

    public function test_envio_individual_do_lote_usa_provider_e_registra_tentativa(): void
    {
        Queue::fake();
        SendingSetting::query()->first()->update([
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'minimum_interval_seconds' => 0,
        ]);
        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/test-message' => Http::response(['success' => true, 'data' => [
                'request_id' => 'fixed-request',
                'status' => 'sent',
                'external_message_id' => 'wamid.test',
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);

        $admin = $this->userWithRole('administrador');
        $batch = app(StartMessageBatchAction::class)->execute($this->readyBatch(), $admin);
        $recipient = $batch->recipients()->first();
        $recipient->forceFill(['request_id' => 'fixed-request'])->save();

        app(RecipientProcessingService::class)->process($recipient, $batch->processing_version);

        $this->assertSame(MessageRecipientProcessingStatus::Sent, $recipient->fresh()->processing_status);
        $this->assertDatabaseHas('message_send_attempts', ['message_batch_recipient_id' => $recipient->id, 'status' => 'sent']);
        $this->assertDatabaseHas('message_processing_events', ['event_type' => 'recipient_sent']);
    }

    public function test_contato_bloqueado_apos_preparacao_e_ignorado(): void
    {
        Queue::fake();
        $admin = $this->userWithRole('administrador');
        $batch = app(StartMessageBatchAction::class)->execute($this->readyBatch(), $admin);
        $recipient = $batch->recipients()->first();
        $recipient->contact->update(['status' => ContactStatus::Blocked]);

        app(RecipientProcessingService::class)->process($recipient, $batch->processing_version);

        $this->assertSame(MessageRecipientProcessingStatus::Skipped, $recipient->fresh()->processing_status);
        $this->assertSame('CONTACT_BECAME_INELIGIBLE', $recipient->fresh()->error_code);
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'max_per_minute' => 1,
            'max_per_hour' => 15,
            'max_per_day' => 40,
            'unanswered_lock_threshold' => 10,
            'minimum_interval_seconds' => 60,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5],
            'timezone' => 'America/Sao_Paulo',
            'max_attempts' => 3,
            'retry_interval_minutes' => 15,
            'retry_backoff_type' => 'fixed',
            'pause_when_disconnected' => 1,
        ], $overrides);
    }

    private function readyBatch(): MessageBatch
    {
        $contact = Contact::factory()->create();
        $batch = MessageBatch::factory()->create([
            'status' => MessageBatchStatus::Ready,
            'eligible_total' => 1,
            'selection_total' => 1,
            'ineligible_total' => 0,
            'prepared_at' => now(),
        ]);

        MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'eligibility_status' => 'eligible',
            'processing_status' => 'eligible',
            'contact_phone_snapshot' => $contact->phone,
            'rendered_message' => 'Oi Contato.',
        ]);

        return $batch->refresh();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
