<?php

namespace Tests\Feature;

use App\Actions\MessageBatches\RetryMessageRecipientAction;
use App\Actions\MessageBatches\UncancelMessageRecipientAction;
use App\Enums\ContactStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus as Status;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Desfazer o cancelamento e reprocessar um destinatário.
 *
 * As duas ações devolvem alguém à fila, então o que precisa ser garantido não e
 * que elas funcionem — e que não sirvam de atalho: nem para furar a janela de
 * horário, nem para alcançar quem pediu para não ser contatado, nem para fazer
 * um lote pausado voltar a enviar.
 */
class DestinatarioCancelarEReprocessarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'administrador')->firstOrFail());

        return $user;
    }

    private function destinatario(Status $situacao, array $contato = [], MessageBatchStatus $loteEm = MessageBatchStatus::Processing): MessageBatchRecipient
    {
        $batch = MessageBatch::factory()->create(['status' => $loteEm]);

        $contact = Contact::factory()->create(array_merge([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5511999990000',
        ], $contato));

        return MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'processing_status' => $situacao,
        ]);
    }

    // --- Desfazer o cancelamento --------------------------------------------

    public function test_um_destinatario_cancelado_volta_para_a_fila_de_espera(): void
    {
        Queue::fake();

        $recipient = $this->destinatario(Status::Cancelled);
        $recipient->forceFill(['cancelled_at' => now(), 'error_code' => 'RECIPIENT_CANCELLED'])->save();

        app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());

        $recipient->refresh();
        $this->assertSame(Status::Pending, $recipient->processing_status);
        $this->assertNull($recipient->cancelled_at);
        $this->assertNull($recipient->error_code);
    }

    /**
     * Volta para `Pending`, e não direto para a fila de envio. A diferença e
     * que em `Pending` ele refaz todas as conferências antes de sair.
     */
    public function test_o_descancelamento_nao_pula_a_fila(): void
    {
        Queue::fake();

        $recipient = $this->destinatario(Status::Cancelled);

        app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());

        $this->assertNotSame(Status::Queued, $recipient->refresh()->processing_status);
        $this->assertSame(Status::Pending, $recipient->processing_status);
    }

    /**
     * Este e o limite que mais importa: desfazer um cancelamento não pode
     * rearmar o envio para quem pediu para sair.
     */
    public function test_nao_descancela_quem_pediu_para_nao_ser_contatado(): void
    {
        $recipient = $this->destinatario(Status::Cancelled, ['do_not_contact' => true]);

        $this->expectException(RuntimeException::class);

        try {
            app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());
        } finally {
            $this->assertSame(Status::Cancelled, $recipient->refresh()->processing_status);
        }
    }

    public function test_nao_descancela_contato_inativo_nem_sem_telefone(): void
    {
        foreach ([['status' => ContactStatus::Inactive], ['phone_normalized' => null]] as $contato) {
            $recipient = $this->destinatario(Status::Cancelled, $contato);

            try {
                app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());
                $this->fail('Deveria ter recusado.');
            } catch (RuntimeException) {
                $this->assertSame(Status::Cancelled, $recipient->refresh()->processing_status);
            }
        }
    }

    public function test_so_descancela_quem_esta_cancelado(): void
    {
        $recipient = $this->destinatario(Status::Sent);

        $this->expectException(RuntimeException::class);

        app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());
    }

    /**
     * Desfazer um cancelamento num lote pausado prepara o destinatário, mas não
     * pode fazer o lote voltar a enviar: quem pausou decide quando retomar.
     */
    public function test_descancelar_em_lote_pausado_nao_retoma_o_lote(): void
    {
        Queue::fake();

        $recipient = $this->destinatario(Status::Cancelled, [], MessageBatchStatus::Paused);

        app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());

        Queue::assertNotPushed(DispatchMessageBatchJob::class);
        $this->assertSame(Status::Pending, $recipient->refresh()->processing_status);
    }

    public function test_descancelar_em_lote_rodando_empurra_o_processamento(): void
    {
        Queue::fake();

        $recipient = $this->destinatario(Status::Cancelled, [], MessageBatchStatus::Processing);

        app(UncancelMessageRecipientAction::class)->execute($recipient, $this->admin());

        Queue::assertPushed(DispatchMessageBatchJob::class);
    }

    // --- Reprocessar ----------------------------------------------------------

    /**
     * O caso que motivou o botão: o destinatário parou porque estava fora do
     * horário permitido, e não havia nada a fazer além de esperar.
     */
    #[DataProvider('situacoesDeEspera')]
    public function test_um_destinatario_parado_por_regra_pode_ser_reprocessado(Status $situacao): void
    {
        Queue::fake();

        $recipient = $this->destinatario($situacao);

        app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());

        $this->assertSame(Status::Pending, $recipient->refresh()->processing_status);
    }

    /** @return array<string, array{Status}> */
    public static function situacoesDeEspera(): array
    {
        return [
            'fora do horário' => [Status::WaitingSchedule],
            'limite por minuto' => [Status::WaitingMinuteLimit],
            'limite por hora' => [Status::WaitingHourLimit],
            'limite por dia' => [Status::WaitingDayLimit],
            'falha temporária' => [Status::FailedTemporary],
        ];
    }

    /**
     * Reprocessar reavalia; não autoriza o envio. Quem decide se pode sair
     * continua sendo a conferência de janela e limites, no momento do envio.
     */
    public function test_reprocessar_nao_marca_como_enviavel_por_conta_propria(): void
    {
        Queue::fake();

        $recipient = $this->destinatario(Status::WaitingSchedule);

        app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());

        $recipient->refresh();
        $this->assertNotSame(Status::Sent, $recipient->processing_status);
        $this->assertNotSame(Status::Queued, $recipient->processing_status);
        $this->assertSame(Status::Pending, $recipient->processing_status);
    }

    /**
     * A garantia central do botão: reprocessar reavalia, não fura a regra.
     *
     * Com a janela fechada, o destinatário volta para a espera — e isso e o
     * comportamento certo. Um botão na tela não e motivo para mandar mensagem
     * de madrugada.
     */
    public function test_com_a_janela_fechada_o_reprocessamento_devolve_para_a_espera(): void
    {
        // A janela de envio vem de `sending_settings`. Uma faixa de madrugada
        // deixa o teste determinístico: fechada a qualquer hora que ele rode.
        app(\App\Services\MessageProcessing\SendingSettingsService::class)->current()->update([
            'start_time' => '03:00:00',
            'end_time' => '03:30:00',
        ]);

        $recipient = $this->destinatario(Status::WaitingSchedule);

        app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());

        $recipient->refresh();
        $this->assertNotSame(Status::Sent, $recipient->processing_status, 'Reprocessar não pode furar a janela.');
        $this->assertSame(Status::WaitingSchedule, $recipient->processing_status);
    }

    public function test_nao_reprocessa_quem_pediu_para_nao_ser_contatado(): void
    {
        $recipient = $this->destinatario(Status::WaitingSchedule, ['do_not_contact' => true]);

        $this->expectException(RuntimeException::class);

        try {
            app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());
        } finally {
            $this->assertSame(Status::WaitingSchedule, $recipient->refresh()->processing_status);
        }
    }

    public function test_nao_reprocessa_o_que_ja_foi_enviado(): void
    {
        $recipient = $this->destinatario(Status::Sent);

        $this->expectException(RuntimeException::class);

        app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());
    }

    /**
     * Cancelado não e reprocessável: para voltar, o caminho e desfazer o
     * cancelamento, que e explícito e fica registrado como tal.
     */
    public function test_cancelado_nao_e_reprocessavel(): void
    {
        $recipient = $this->destinatario(Status::Cancelled);

        $this->expectException(RuntimeException::class);

        app(RetryMessageRecipientAction::class)->execute($recipient, $this->admin());
    }

    // --- Tela e permissão -----------------------------------------------------

    public function test_a_tela_oferece_desfazer_para_cancelado_e_reprocessar_para_espera(): void
    {
        $cancelado = $this->destinatario(Status::Cancelled);
        $batch = $cancelado->batch;

        MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => Contact::factory()->create(['phone_normalized' => '5511888880000'])->id,
            'processing_status' => Status::WaitingSchedule,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertSee('Desfazer cancelamento')
            ->assertSee('Reprocessar');
    }

    public function test_quem_nao_pode_cancelar_nao_pode_desfazer(): void
    {
        $recipient = $this->destinatario(Status::Cancelled);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'consulta')->firstOrFail());

        $this->actingAs($user)
            ->post(route('admin.message-batches.recipients.uncancel', [$recipient->batch, $recipient]))
            ->assertForbidden();

        $this->assertSame(Status::Cancelled, $recipient->refresh()->processing_status);
    }

    /**
     * Um destinatário de outro lote não pode ser alcançado pela URL deste.
     */
    public function test_nao_alcanca_destinatario_de_outro_lote(): void
    {
        $recipient = $this->destinatario(Status::Cancelled);
        $outroLote = MessageBatch::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.message-batches.recipients.uncancel', [$outroLote, $recipient]))
            ->assertNotFound();
    }
}
