<?php

namespace Tests\Feature;

use App\Actions\MessageBatches\StartMessageBatchAction;
use App\Enums\ConversationFlowStage;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Models\Contact;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\SendingSetting;
use App\Models\User;
use App\Services\MessageProcessing\RecipientProcessingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Vínculo entre lote e fluxo conversacional.
 *
 * Sem esse vínculo o motor de automação nunca entra em ação: o estado do fluxo
 * só nasce no envio do lote, e `handleIncomingMessage` sai calado quando não
 * encontra estado para a conversa. O que precisa ser garantido aqui e que o
 * vínculo seja gravado, que fluxo inativo não entre, e que o envio realmente
 * abra o fluxo na conversa do destinatário.
 */
class LoteVinculadoAFluxoConversacionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
    }

    public function test_lote_criado_com_fluxo_guarda_vinculo_e_snapshot(): void
    {
        $admin = $this->userWithRole('administrador');
        $flow = ConversationFlow::factory()->create(['name' => 'Pesquisa Assembleia']);

        $this->actingAs($admin)
            ->post(route('admin.message-batches.store'), $this->batchPayload(['conversation_flow_id' => $flow->id]))
            ->assertRedirect();

        $batch = MessageBatch::firstOrFail();
        $this->assertSame($flow->id, $batch->conversation_flow_id);
        $this->assertSame('Pesquisa Assembleia', $batch->conversation_flow_snapshot['name']);
        $this->assertSame(48, $batch->conversation_flow_snapshot['validity_hours']);
        $this->assertDatabaseHas('message_batch_events', ['message_batch_id' => $batch->id, 'event_type' => 'conversation_flow_linked']);
    }

    public function test_lote_sem_fluxo_continua_sem_resposta_automatica(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)->post(route('admin.message-batches.store'), $this->batchPayload())->assertRedirect();

        $batch = MessageBatch::firstOrFail();
        $this->assertNull($batch->conversation_flow_id);
        $this->assertNull($batch->conversation_flow_snapshot);
    }

    public function test_fluxo_inativo_nao_pode_ser_vinculado(): void
    {
        $admin = $this->userWithRole('administrador');
        $draft = ConversationFlow::factory()->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.message-batches.store'), $this->batchPayload(['conversation_flow_id' => $draft->id]))
            ->assertSessionHasErrors('conversation_flow_id');

        $this->assertSame(0, MessageBatch::count());
    }

    public function test_edicao_preserva_fluxo_que_foi_pausado_depois_do_vinculo(): void
    {
        $admin = $this->userWithRole('administrador');
        $flow = ConversationFlow::factory()->create();

        $this->actingAs($admin)->post(route('admin.message-batches.store'), $this->batchPayload(['conversation_flow_id' => $flow->id]));
        $batch = MessageBatch::firstOrFail();

        $flow->update(['status' => \App\Enums\ConversationFlowStatus::Paused]);

        $this->actingAs($admin)->get(route('admin.message-batches.edit', $batch))->assertOk()->assertSee($flow->name);
        $this->actingAs($admin)
            ->put(route('admin.message-batches.update', $batch), $this->batchPayload([
                'name' => 'Lote renomeado',
                'conversation_flow_id' => $flow->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($flow->id, $batch->fresh()->conversation_flow_id);
    }

    public function test_envio_do_lote_abre_o_fluxo_na_conversa_do_destinatario(): void
    {
        Queue::fake();
        $this->allowAnyTimeSending();
        $this->fakeConnectedProvider();

        $admin = $this->userWithRole('administrador');
        $flow = ConversationFlow::factory()->create();
        ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id, 'is_active' => true]);

        $batch = app(StartMessageBatchAction::class)->execute($this->readyBatch($flow), $admin);
        $recipient = $batch->recipients()->first();
        $recipient->forceFill(['request_id' => 'fixed-request'])->save();

        app(RecipientProcessingService::class)->process($recipient, $batch->processing_version);

        $this->assertSame(MessageRecipientProcessingStatus::Sent, $recipient->fresh()->processing_status);
        $this->assertDatabaseHas('conversation_flow_states', [
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingPermission->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation_automation.activated']);
    }

    public function test_lote_sem_fluxo_nao_abre_estado_de_conversa(): void
    {
        Queue::fake();
        $this->allowAnyTimeSending();
        $this->fakeConnectedProvider();

        $admin = $this->userWithRole('administrador');
        $batch = app(StartMessageBatchAction::class)->execute($this->readyBatch(), $admin);
        $recipient = $batch->recipients()->first();
        $recipient->forceFill(['request_id' => 'fixed-request'])->save();

        app(RecipientProcessingService::class)->process($recipient, $batch->processing_version);

        $this->assertSame(MessageRecipientProcessingStatus::Sent, $recipient->fresh()->processing_status);
        $this->assertDatabaseCount('conversation_flow_states', 0);
    }

    private function allowAnyTimeSending(): void
    {
        SendingSetting::query()->first()->update([
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'minimum_interval_seconds' => 0,
        ]);
    }

    private function fakeConnectedProvider(): void
    {
        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/test-message' => Http::response(['success' => true, 'data' => [
                'request_id' => 'fixed-request',
                'status' => 'sent',
                'external_message_id' => 'wamid.test',
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);
    }

    private function readyBatch(?ConversationFlow $flow = null): MessageBatch
    {
        $contact = Contact::factory()->create();
        $batch = MessageBatch::factory()->create([
            'status' => MessageBatchStatus::Ready,
            'conversation_flow_id' => $flow?->id,
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

    private function batchPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lote com fluxo',
            'description' => 'Lote de teste',
            'message_body' => 'Oi {primeiro_nome}.',
            'selection_type' => 'manual',
            'contact_ids' => [Contact::factory()->create()->id],
            'filters' => [],
        ], $overrides);
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
