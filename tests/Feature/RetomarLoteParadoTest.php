<?php

namespace Tests\Feature;

use App\Actions\MessageBatches\ResumeStoppedMessageBatchAction;
use App\Actions\MessageBatches\StopMessageBatchAction;
use App\Enums\ContactStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus as Status;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Retomar um lote que havia sido parado.
 *
 * Parar e destrutivo: cancela todo destinatário pendente. Retomar desfaz esses
 * cancelamentos — e o que precisa ser garantido aqui e que ele **não desfaça
 * mais do que isso**: nem cancelamento individual, que foi decisão de pessoa,
 * nem envio para quem ficou inapto no meio-tempo.
 */
class RetomarLoteParadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'administrador')->firstOrFail());

        return $user;
    }

    private function contato(array $atributos = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5511'.random_int(100000000, 999999999),
        ], $atributos));
    }

    private function destinatario(MessageBatch $batch, Status $situacao, array $contato = []): MessageBatchRecipient
    {
        return MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $this->contato($contato)->id,
            'processing_status' => $situacao,
        ]);
    }

    /** Lote em processamento com destinatários, parado de verdade pela ação de parar. */
    private function loteParado(): MessageBatch
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);

        $this->destinatario($batch, Status::Pending);
        $this->destinatario($batch, Status::WaitingSchedule);
        $this->destinatario($batch, Status::Sent);

        app(StopMessageBatchAction::class)->execute($batch, $this->admin(), 'teste');

        return $batch->refresh();
    }

    // --- O caminho principal --------------------------------------------------

    public function test_retomar_devolve_a_fila_quem_a_parada_cancelou(): void
    {
        $batch = $this->loteParado();

        $resumo = app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $this->assertSame(2, $resumo['retomados']);
        $this->assertSame(MessageBatchStatus::Queued, $batch->refresh()->status);
        $this->assertSame(2, $batch->recipients()->where('processing_status', Status::Pending)->count());
    }

    /**
     * Quem já recebeu não pode receber de novo. Enviados não estão cancelados,
     * então não entram no filtro — mas isto precisa estar travado.
     */
    public function test_quem_ja_recebeu_nao_volta_para_a_fila(): void
    {
        $batch = $this->loteParado();

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $this->assertSame(1, $batch->recipients()->where('processing_status', Status::Sent)->count());
    }

    /**
     * Este e o limite mais delicado. Cancelar um destinatário e decisão tomada
     * sobre aquela pessoa; uma ação de lote não pode desfazê-la por tabela.
     */
    public function test_cancelamento_individual_nao_e_desfeito_por_tabela(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);
        $daParada = $this->destinatario($batch, Status::Pending);

        // Cancelado a mão antes da parada: marca própria, sem BATCH_STOPPED.
        $aMao = $this->destinatario($batch, Status::Cancelled);
        $aMao->forceFill(['error_code' => 'RECIPIENT_CANCELLED', 'cancelled_at' => now()])->save();

        app(StopMessageBatchAction::class)->execute($batch, $this->admin(), 'teste');
        app(ResumeStoppedMessageBatchAction::class)->execute($batch->refresh(), $this->admin());

        $this->assertSame(Status::Pending, $daParada->refresh()->processing_status);
        $this->assertSame(Status::Cancelled, $aMao->refresh()->processing_status, 'O cancelamento individual precisa sobreviver à retomada.');
        $this->assertSame('RECIPIENT_CANCELLED', $aMao->error_code);
    }

    /**
     * Entre a parada e a retomada alguém pode ter pedido para sair.
     * Ressuscitar esse envio seria o pior erro possível desta tela.
     */
    public function test_quem_ficou_inapto_no_meio_tempo_continua_de_fora(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);
        $segue = $this->destinatario($batch, Status::Pending);
        $saiu = $this->destinatario($batch, Status::Pending);

        app(StopMessageBatchAction::class)->execute($batch, $this->admin(), 'teste');

        $saiu->contact->update(['do_not_contact' => true]);

        $resumo = app(ResumeStoppedMessageBatchAction::class)->execute($batch->refresh(), $this->admin());

        $this->assertSame(1, $resumo['retomados']);
        $this->assertSame(1, $resumo['mantidos_fora']);
        $this->assertSame(Status::Pending, $segue->refresh()->processing_status);
        $this->assertSame(Status::Cancelled, $saiu->refresh()->processing_status);
    }

    public function test_recusa_quando_ninguem_pode_mais_receber(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);
        $unico = $this->destinatario($batch, Status::Pending);

        app(StopMessageBatchAction::class)->execute($batch, $this->admin(), 'teste');
        $unico->contact->update(['do_not_contact' => true]);

        $this->expectException(RuntimeException::class);

        try {
            app(ResumeStoppedMessageBatchAction::class)->execute($batch->refresh(), $this->admin());
        } finally {
            $this->assertSame(MessageBatchStatus::Stopped, $batch->refresh()->status);
        }
    }

    // --- Estados e proteções ---------------------------------------------------

    public function test_so_retoma_lote_parado(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Paused]);

        $this->expectException(RuntimeException::class);

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());
    }

    /**
     * O caso que apareceu em produção: com o lote parado, alguém desfez o
     * cancelamento de cada destinatário. Eles voltaram para `pending` sem a
     * marca `BATCH_STOPPED`, e o botão de retomar sumiu — o lote ficou parado,
     * com gente pronta para receber, e sem caminho de volta pela tela.
     */
    public function test_retoma_lote_parado_cujos_destinatarios_foram_descancelados_a_mao(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);
        $this->destinatario($batch, Status::Pending);
        $this->destinatario($batch, Status::Pending);

        app(StopMessageBatchAction::class)->execute($batch, $this->admin(), 'teste');

        // Desfaz o cancelamento de cada um, com o lote ainda parado.
        foreach ($batch->recipients()->get() as $recipient) {
            app(\App\Actions\MessageBatches\UncancelMessageRecipientAction::class)
                ->execute($recipient, $this->admin());
        }

        $batch->refresh();
        $this->assertSame(MessageBatchStatus::Stopped, $batch->status, 'O lote continua parado.');
        $this->assertSame(2, ResumeStoppedMessageBatchAction::candidatos($batch)->count(), 'O botão precisa aparecer.');

        $resumo = app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $this->assertSame(2, $resumo['retomados']);
        $this->assertSame(MessageBatchStatus::Queued, $batch->refresh()->status);
    }

    /**
     * Quem já estava esperando por uma regra mantem o motivo da espera: a
     * retomada religa o lote, não apaga o histórico de cada linha.
     */
    public function test_quem_ja_esperava_mantem_a_situacao(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Stopped]);
        $esperando = $this->destinatario($batch, Status::WaitingSchedule);
        $esperando->forceFill(['error_code' => 'WAITING_SCHEDULE', 'error_message' => 'Fora do horário permitido.'])->save();

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $this->assertSame(Status::WaitingSchedule, $esperando->refresh()->processing_status);
        $this->assertSame('WAITING_SCHEDULE', $esperando->error_code);
    }

    public function test_recusa_lote_parado_sem_ninguem_para_retomar(): void
    {
        // Lote em que todo mundo já recebeu: não ha o que retomar.
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Stopped]);
        $this->destinatario($batch, Status::Sent);

        $this->expectException(RuntimeException::class);

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());
    }

    /**
     * A versão de processamento sobe para que trabalhos antigos ainda na fila
     * sejam descartados ao acordar, em vez de disputarem com os novos.
     */
    public function test_a_versao_de_processamento_sobe(): void
    {
        $batch = $this->loteParado();
        $antes = $batch->processing_version;

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $this->assertGreaterThan($antes, $batch->refresh()->processing_version);
    }

    public function test_o_motivo_da_parada_e_limpo(): void
    {
        $batch = $this->loteParado();
        $this->assertNotNull($batch->cancel_reason);

        app(ResumeStoppedMessageBatchAction::class)->execute($batch, $this->admin());

        $batch->refresh();
        $this->assertNull($batch->cancel_reason);
        $this->assertNull($batch->stopped_at);
    }

    // --- Tela e permissão -------------------------------------------------------

    public function test_a_tela_mostra_o_botao_com_a_quantidade(): void
    {
        $batch = $this->loteParado();

        $this->actingAs($this->admin())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertSee('Retomar envios (2)');
    }

    public function test_a_tela_nao_mostra_o_botao_em_lote_que_nao_esta_parado(): void
    {
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Processing]);

        $this->actingAs($this->admin())
            ->get(route('admin.message-batches.processing', $batch))
            ->assertOk()
            ->assertDontSee('Retomar envios');
    }

    public function test_quem_nao_pode_iniciar_nao_pode_retomar(): void
    {
        $batch = $this->loteParado();

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'consulta')->firstOrFail());

        $this->actingAs($user)
            ->post(route('admin.message-batches.resume-stopped', $batch))
            ->assertForbidden();

        $this->assertSame(MessageBatchStatus::Stopped, $batch->refresh()->status);
    }
}
