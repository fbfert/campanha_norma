<?php

namespace Tests\Feature;

use App\Models\CleanupOperation;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use App\Models\KeywordCampaignParticipation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Cleanup\CleanupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A Limpeza.
 *
 * O que estes testes cobram não é a tela: é que a remoção tenha efeito no resto
 * do sistema no instante em que acontece, que ela volte inteira enquanto o
 * prazo não venceu, e que ela não deixe a pessoa impedida de participar de novo.
 *
 * Este último é o que mais custa a aparecer sozinho. Uma inscrição na lixeira
 * continua ocupando o índice único `(campanha, contato)`, e sem a chave
 * sentinela a próxima inscrição da mesma pessoa naquela campanha morreria numa
 * violação de integridade — semanas depois da limpeza, longe da causa.
 */
class LimpezaDeParticipacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_inscricao_limpa_some_das_contagens_na_hora(): void
    {
        [$contato, $campanha, $inscricao] = $this->inscricao();

        $this->assertSame(1, KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)->count());

        $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $this->assertSame(0, KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)->count());
        $this->assertSame(1, KeywordCampaignParticipation::withTrashed()->where('keyword_campaign_id', $campanha->id)->count());
    }

    /**
     * O caso que motivou a chave sentinela.
     *
     * A decisão foi que limpar não impede participar de novo. Sem a chave, o
     * índice único guardaria a linha da lixeira e recusaria a nova inscrição.
     */
    public function test_quem_foi_limpo_consegue_se_inscrever_de_novo_na_mesma_campanha(): void
    {
        [$contato, $campanha, $inscricao] = $this->inscricao();

        $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $nova = KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
        ]);

        $this->assertNotSame($inscricao->id, $nova->id);
        $this->assertSame(1, KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)->count());
    }

    /**
     * A chave vale para quem exclui em massa, não só para a Limpeza.
     *
     * Exclusão pelo construtor de consultas não dispara evento de modelo. Se a
     * garantia dependesse do evento, ela valeria conforme o jeito de chamar.
     */
    public function test_exclusao_em_massa_tambem_libera_o_indice(): void
    {
        [$contato, $campanha] = $this->inscricao();

        KeywordCampaignParticipation::where('contact_id', $contato->id)->delete();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
        ]);

        $this->assertSame(1, KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)->count());
    }

    public function test_o_cupom_atribuido_sai_junto_com_a_inscricao(): void
    {
        [$contato, $campanha, $inscricao] = $this->inscricao();

        $cupom = KeywordCampaignCoupon::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'keyword_campaign_participation_id' => $inscricao->id,
        ]);

        $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $this->assertNull(KeywordCampaignCoupon::find($cupom->id));
        $this->assertNotNull(KeywordCampaignCoupon::withTrashed()->find($cupom->id));
    }

    public function test_restaurar_devolve_tudo_o_que_saiu(): void
    {
        [$contato, $campanha, $inscricao] = $this->inscricao();

        $cupom = KeywordCampaignCoupon::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'keyword_campaign_participation_id' => $inscricao->id,
        ]);

        $operacao = $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        app(CleanupService::class)->restaurar($operacao, $this->admin());

        $this->assertNotNull(KeywordCampaignParticipation::find($inscricao->id));
        $this->assertNotNull(KeywordCampaignCoupon::find($cupom->id));
        $this->assertNotNull($operacao->fresh()->restored_at);
    }

    /**
     * Restaurada, a linha volta com a chave zerada.
     *
     * Se a chave ficasse com o id antigo, a linha restaurada não colidiria com
     * uma segunda inscrição da mesma pessoa — e o índice único deixaria passar
     * a duplicata que ele existe para recusar.
     */
    public function test_a_linha_restaurada_volta_a_ocupar_o_indice(): void
    {
        [$contato, $campanha, $inscricao] = $this->inscricao();

        $operacao = $this->limpar($contato, ['campanhas:'.$inscricao->id]);
        app(CleanupService::class)->restaurar($operacao, $this->admin());

        $this->expectException(QueryException::class);

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
        ]);
    }

    public function test_o_prazo_da_lixeira_sai_das_configuracoes(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'retention.cleanup_trash_days'],
            ['group' => 'retention', 'value' => '7', 'type' => 'integer', 'is_public' => false]
        );

        [$contato, , $inscricao] = $this->inscricao();

        $operacao = $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $this->assertSame(7, (int) $operacao->executed_at->diffInDays($operacao->expires_at, false));
    }

    public function test_vencido_o_prazo_a_restauracao_e_recusada(): void
    {
        [$contato, , $inscricao] = $this->inscricao();

        $operacao = $this->limpar($contato, ['campanhas:'.$inscricao->id]);
        $operacao->update(['expires_at' => now()->subDay()]);

        $this->expectException(ValidationException::class);

        app(CleanupService::class)->restaurar($operacao->fresh(), $this->admin());
    }

    public function test_o_expurgo_apaga_em_definitivo_o_que_venceu(): void
    {
        [$contato, , $inscricao] = $this->inscricao();

        $operacao = $this->limpar($contato, ['campanhas:'.$inscricao->id]);
        $operacao->update(['expires_at' => now()->subDay()]);

        $resultado = app(CleanupService::class)->expurgarVencidas();

        $this->assertSame(1, $resultado['limpezas']);
        $this->assertNull(KeywordCampaignParticipation::withTrashed()->find($inscricao->id));
        $this->assertNotNull($operacao->fresh()->purged_at);
    }

    public function test_o_expurgo_nao_toca_no_que_ainda_esta_no_prazo(): void
    {
        [$contato, , $inscricao] = $this->inscricao();

        $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $resultado = app(CleanupService::class)->expurgarVencidas();

        $this->assertSame(0, $resultado['limpezas']);
        $this->assertNotNull(KeywordCampaignParticipation::withTrashed()->find($inscricao->id));
    }

    public function test_limpeza_sem_motivo_e_recusada(): void
    {
        [$contato, , $inscricao] = $this->inscricao();

        $this->expectException(ValidationException::class);

        app(CleanupService::class)->limpar($contato, ['campanhas:'.$inscricao->id], '   ', $this->admin());
    }

    /**
     * A inscrição é projeção da mensagem que a originou, e o banco leva uma
     * junto com a outra na hora do expurgo. Deixar passar aqui removeria em
     * silêncio, semanas depois, algo que o operador escolheu manter.
     */
    public function test_limpar_a_conversa_sem_a_inscricao_que_nasceu_dela_e_recusado(): void
    {
        [$contato, , $inscricao] = $this->inscricao();
        $conversa = ConversationMessage::find($inscricao->conversation_message_id)->conversation;

        $this->expectException(ValidationException::class);

        app(CleanupService::class)->limpar($contato, ['conversas:'.$conversa->id], 'Pedido de remoção da pessoa.', $this->admin());
    }

    public function test_limpar_a_conversa_com_a_inscricao_junto_e_aceito(): void
    {
        [$contato, , $inscricao] = $this->inscricao();
        $conversa = ConversationMessage::find($inscricao->conversation_message_id)->conversation;

        $operacao = app(CleanupService::class)->limpar(
            $contato,
            ['conversas:'.$conversa->id, 'campanhas:'.$inscricao->id],
            'Pedido de remoção da pessoa.',
            $this->admin(),
        );

        $this->assertSame(2, $operacao->items_count);
        $this->assertNull(Conversation::find($conversa->id));
        $this->assertNull(KeywordCampaignParticipation::find($inscricao->id));
    }

    public function test_a_tela_recusa_telefone_que_nao_confere(): void
    {
        [$contato, , $inscricao] = $this->inscricao();

        $this->actingAs($this->admin())
            ->post(route('admin.cleanup.store', $contato), [
                'modo' => 'selecionados',
                'itens' => ['campanhas:'.$inscricao->id],
                'motivo' => 'Pedido de remoção da pessoa.',
                'telefone_confirmado' => '5511999998888',
            ])
            ->assertSessionHasErrors('telefone_confirmado');

        $this->assertNotNull(KeywordCampaignParticipation::find($inscricao->id));
    }

    public function test_quem_nao_tem_a_permissao_nao_entra(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $contato = Contact::factory()->create();

        $semPermissao = User::factory()->create();
        $semPermissao->roles()->attach(Role::where('slug', 'operador')->firstOrFail());

        $this->actingAs($semPermissao)->get(route('admin.cleanup.index'))->assertForbidden();
        $this->actingAs($semPermissao)->get(route('admin.cleanup.show', $contato))->assertForbidden();
    }

    public function test_limpar_tudo_leva_tudo_de_uma_vez(): void
    {
        [$contato, , $inscricao] = $this->inscricao();
        $conversa = ConversationMessage::find($inscricao->conversation_message_id)->conversation;

        $this->actingAs($this->admin())
            ->post(route('admin.cleanup.store', $contato), [
                'modo' => 'tudo',
                'motivo' => 'Pedido de remoção da pessoa.',
                'telefone_confirmado' => $contato->phone_normalized ?? $contato->phone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(KeywordCampaignParticipation::find($inscricao->id));
        $this->assertNull(Conversation::find($conversa->id));
    }

    /**
     * As três telas abrem.
     *
     * Blade quebra em runtime, não na análise: uma chave renomeada no serviço e
     * a tela vira 500 sem nada avisar antes. O caminho feliz precisa ser
     * percorrido de verdade em algum lugar.
     */
    public function test_as_telas_da_limpeza_abrem(): void
    {
        [$contato, , $inscricao] = $this->inscricao();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.cleanup.index', ['busca' => $contato->name]))->assertOk();
        $this->actingAs($admin)->get(route('admin.cleanup.show', $contato))->assertOk();

        $this->limpar($contato, ['campanhas:'.$inscricao->id]);

        $this->actingAs($admin)->get(route('admin.cleanup.trash'))->assertOk();
    }

    /**
     * @return array{0: Contact, 1: KeywordCampaign, 2: KeywordCampaignParticipation}
     */
    private function inscricao(): array
    {
        $contato = Contact::factory()->create(['phone' => '5549991613378', 'phone_normalized' => '5549991613378']);
        $campanha = KeywordCampaign::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
        ]);

        $inscricao = KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'conversation_message_id' => $mensagem->id,
        ]);

        return [$contato, $campanha, $inscricao];
    }

    /**
     * @param  list<string>  $chaves
     */
    private function limpar(Contact $contato, array $chaves): CleanupOperation
    {
        return app(CleanupService::class)->limpar($contato, $chaves, 'Pedido de remoção da pessoa.', $this->admin());
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'administrador')->firstOrFail());

        return $user;
    }
}
