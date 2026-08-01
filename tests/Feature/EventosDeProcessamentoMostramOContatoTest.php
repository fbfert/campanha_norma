<?php

namespace Tests\Feature;

use App\Enums\MessageBatchStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageProcessingEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Eventos de processamento identificam o destinatário.
 *
 * A lista mostrava data, tipo e descrição: "Aguardando limite de envio" três
 * vezes seguidas, sem dizer de quem. Para acompanhar um lote em andamento, o
 * evento sem nome informa que algo aconteceu e esconde justamente o que a tela
 * existe para mostrar.
 */
class EventosDeProcessamentoMostramOContatoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_evento_mostra_nome_e_telefone_do_destinatario(): void
    {
        [$batch, $recipient] = $this->loteComDestinatario();

        MessageProcessingEvent::factory()->create([
            'message_batch_id' => $batch->id,
            'message_batch_recipient_id' => $recipient->id,
            'event_type' => 'recipient_waiting',
            'description' => 'Aguardando limite de envio.',
        ]);

        $this->actingAs($this->administrador())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertSee('Aguardando limite de envio.')
            ->assertSee('Paulo Henrique')
            ->assertSee('5549999837254');
    }

    /**
     * O evento guarda o snapshot, e não o cadastro de agora: ele conta o que
     * valia quando aconteceu.
     */
    public function test_o_nome_exibido_e_o_do_momento_do_envio(): void
    {
        [$batch, $recipient] = $this->loteComDestinatario();

        MessageProcessingEvent::factory()->create([
            'message_batch_id' => $batch->id,
            'message_batch_recipient_id' => $recipient->id,
            'event_type' => 'recipient_sent',
            'description' => 'Mensagem enviada.',
        ]);

        $recipient->contact->update(['name' => 'Nome Trocado Depois']);

        $this->actingAs($this->administrador())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertSee('Paulo Henrique')
            ->assertDontSee('Nome Trocado Depois');
    }

    public function test_evento_do_lote_inteiro_nao_inventa_um_contato(): void
    {
        [$batch] = $this->loteComDestinatario();

        MessageProcessingEvent::factory()->create([
            'message_batch_id' => $batch->id,
            'message_batch_recipient_id' => null,
            'event_type' => 'batch_started',
            'description' => 'Lote iniciado.',
        ]);

        $this->actingAs($this->administrador())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertSee('Lote inteiro');
    }

    /** @return array{0: MessageBatch, 1: MessageBatchRecipient} */
    private function loteComDestinatario(): array
    {
        $batch = MessageBatch::factory()->create([
            'status' => MessageBatchStatus::Processing,
            'eligible_total' => 1,
            'selection_total' => 1,
            'prepared_at' => now(),
        ]);

        $contact = Contact::factory()->create(['name' => 'Paulo Henrique Ribeiro Derengovisk']);

        $recipient = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'eligibility_status' => 'eligible',
            'processing_status' => 'pending',
            'contact_name_snapshot' => 'Paulo Henrique Ribeiro Derengovisk',
            'contact_phone_snapshot' => '5549999837254',
            'rendered_message' => 'Oi.',
        ]);

        return [$batch, $recipient];
    }

    private function administrador(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
