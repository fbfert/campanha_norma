<?php

namespace Tests\Feature;

use App\Enums\MessageBatchStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Atualizar o lote com o cadastro corrigido.
 *
 * O caminho real: o lote acusa "falta a cidade", alguém completa o cadastro, e
 * antes disso era preciso refazer o lote inteiro para o contato voltar a ser
 * apto — na prática, perder a ordem sorteada e os snapshots de todo mundo por
 * causa de um campo de uma pessoa.
 */
class AtualizarLoteComCadastroCorrigidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_contato_completado_volta_a_ser_apto(): void
    {
        [$batch, $recipient] = $this->loteComInapto();

        $recipient->contact->update(['city' => 'Lages']);

        $this->actingAs($this->administrador())
            ->post(route('admin.message-batches.revalidate', $batch))
            ->assertRedirect();

        $recipient->refresh();
        $this->assertSame('eligible', $recipient->eligibility_status->value);
        $this->assertSame('Lages', $recipient->contact_city_snapshot);
        $this->assertStringContainsString('Lages', (string) $recipient->rendered_message);

        $batch->refresh();
        $this->assertSame(1, $batch->eligible_total);
        $this->assertSame(0, $batch->ineligible_total);
    }

    public function test_contato_que_piorou_deixa_de_ser_apto(): void
    {
        [$batch, $recipient] = $this->loteComInapto();
        $recipient->contact->update(['city' => 'Lages']);
        $this->actingAs($this->administrador())->post(route('admin.message-batches.revalidate', $batch));

        $recipient->contact->update(['do_not_contact' => true]);
        $this->actingAs($this->administrador())->post(route('admin.message-batches.revalidate', $batch));

        $this->assertSame('excluded', $recipient->refresh()->eligibility_status->value);
        $this->assertStringContainsString('não contatar', (string) $recipient->ineligibility_reason);
    }

    /**
     * Reescrever a mensagem de quem já recebeu apagaria o registro do que foi
     * enviado de fato.
     */
    public function test_quem_ja_recebeu_nao_e_tocado(): void
    {
        [$batch, $recipient] = $this->loteComInapto();

        $recipient->forceFill([
            'sent_at' => now(),
            'external_message_id' => 'wamid.enviado',
            'rendered_message' => 'Texto que foi enviado de verdade.',
        ])->save();

        $recipient->contact->update(['city' => 'Lages', 'name' => 'Nome Trocado']);

        $this->actingAs($this->administrador())->post(route('admin.message-batches.revalidate', $batch));

        $recipient->refresh();
        $this->assertSame('Texto que foi enviado de verdade.', $recipient->rendered_message);
        $this->assertNotSame('Nome Trocado', $recipient->contact_name_snapshot);
    }

    public function test_lote_em_processamento_nao_pode_ser_atualizado(): void
    {
        [$batch] = $this->loteComInapto();
        $batch->forceFill(['status' => MessageBatchStatus::Processing])->save();

        $this->actingAs($this->administrador())
            ->post(route('admin.message-batches.revalidate', $batch))
            ->assertSessionHasErrors('batch');
    }

    public function test_operador_sem_permissao_nao_atualiza(): void
    {
        [$batch] = $this->loteComInapto();

        $this->actingAs($this->comPapel('consulta'))
            ->post(route('admin.message-batches.revalidate', $batch))
            ->assertForbidden();
    }

    public function test_a_tela_oferece_o_atalho_para_o_cadastro_do_contato(): void
    {
        [$batch, $recipient] = $this->loteComInapto();

        $this->actingAs($this->administrador())
            ->get(route('admin.message-batches.show', $batch))
            ->assertOk()
            ->assertSee(route('admin.contacts.edit', $recipient->contact_id), false)
            ->assertSee('Atualizar lote');
    }

    /** @return array{0: MessageBatch, 1: MessageBatchRecipient} */
    private function loteComInapto(): array
    {
        $batch = MessageBatch::factory()->create([
            'status' => MessageBatchStatus::Ready,
            'message_body_snapshot' => 'Oi {primeiro_nome}, o que melhorar em {cidade}?',
            'eligible_total' => 0,
            'ineligible_total' => 1,
            'selection_total' => 1,
            'prepared_at' => now(),
        ]);

        $contact = Contact::factory()->create(['first_name' => 'Paulo', 'city' => null]);

        $recipient = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'eligibility_status' => 'excluded',
            'ineligibility_reason' => 'O campo Cidade e obrigatório para esta mensagem.',
            'rendered_message' => null,
            'contact_name_snapshot' => $contact->name,
            'contact_city_snapshot' => null,
        ]);

        return [$batch, $recipient];
    }

    private function administrador(): User
    {
        return $this->comPapel('administrador');
    }

    private function comPapel(string $papel): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
