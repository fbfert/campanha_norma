<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ConversationAutomation\ConversationAutomatedReplyService;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Placeholders na pergunta do fluxo conversacional.
 *
 * A automação não renderizava nada: quem escrevesse `{primeiro_nome}` numa
 * pergunta veria o contato receber a chave literal. A tela agora oferece os
 * placeholders, e por isso as duas outras pontas precisam existir — renderizar
 * no envio e recusar chave inexistente ao salvar.
 */
class PlaceholderNaPerguntaDoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Queue::fake();

        foreach (['conversation_automation.window_start' => '00:00', 'conversation_automation.window_end' => '23:59'] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['group' => 'conversation_automation', 'value' => $value, 'type' => 'string', 'is_public' => false]);
        }

        app(SystemSettingService::class)->forget();
    }

    public function test_a_pergunta_sai_com_o_nome_do_contato(): void
    {
        $state = $this->estado(['first_name' => 'Mariana', 'city' => 'Lages']);

        app(ConversationAutomatedReplyService::class)
            ->queue($state, 'Oi {primeiro_nome}, o que precisa melhorar em {cidade}?', 'automated_question_queued');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $state->conversation_id,
            'body' => 'Oi Mariana, o que precisa melhorar em Lages?',
        ]);
    }

    /**
     * Campo sem substituto continua impedindo o envio.
     *
     * A cidade saiu deste caso e ganhou o seu, logo abaixo: quem entra por
     * campanha nasce sem cidade, e recusar deixava a pessoa sem a pergunta.
     */
    public function test_campo_vazio_no_contato_impede_o_envio(): void
    {
        $state = $this->estado(['first_name' => null, 'city' => 'Lages']);

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state, 'Oi {primeiro_nome}, o que precisa melhorar?', 'automated_question_queued');

        $this->assertNull($mensagem, 'Enviar a chave literal para o cidadão e pior que não enviar.');
        $this->assertDatabaseMissing('conversation_messages', ['conversation_id' => $state->conversation_id, 'direction' => 'outgoing']);
        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $state->conversation_id,
            'event_type' => 'automation_placeholder_missing',
        ]);
    }

    public function test_o_formulario_recusa_placeholder_inexistente(): void
    {
        $admin = $this->administrador();
        $flow = ConversationFlow::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.conversation-flows.questions.store', $flow), [
                'internal_title' => 'Pergunta com erro',
                'text' => 'O que precisa melhorar em {cidde}?',
                'weight' => 1,
                'display_order' => 0,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('text');

        $this->assertSame(0, ConversationFlowQuestion::count());
    }

    public function test_o_formulario_aceita_placeholder_do_catalogo(): void
    {
        $admin = $this->administrador();
        $flow = ConversationFlow::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.conversation-flows.questions.store', $flow), [
                'internal_title' => 'Pergunta com nome',
                'text' => 'Oi {primeiro_nome}, o que precisa melhorar em {cidade}?',
                'weight' => 1,
                'display_order' => 0,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ConversationFlowQuestion::count());
    }

    public function test_a_tela_oferece_os_placeholders(): void
    {
        $admin = $this->administrador();
        $flow = ConversationFlow::factory()->create();
        $question = ConversationFlowQuestion::factory()->create(['conversation_flow_id' => $flow->id]);

        $this->actingAs($admin)
            ->get(route('admin.conversation-flows.questions.edit', [$flow, $question]))
            ->assertOk()
            ->assertSee('{primeiro_nome}')
            ->assertSee('{cidade}');
    }

    private function estado(array $contato): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['transparency_enabled' => false]);
        $contact = Contact::factory()->create($contato);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function administrador(): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', 'administrador')->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }

    /**
     * Cidade em branco não impede mais: a pergunta sai com "sua cidade".
     *
     * Em 17/08/2026 uma pessoa se inscreveu por palavra-chave, disse "Pode" ao
     * pedido de permissão e não recebeu pergunta nenhuma — as perguntas do
     * fluxo usam {cidade} e a inscrição só traz nome e telefone.
     */
    public function test_cidade_em_branco_sai_com_o_substituto(): void
    {
        $state = $this->estado(['first_name' => 'Mariana', 'city' => null]);

        $mensagem = app(ConversationAutomatedReplyService::class)
            ->queue($state, 'O que precisa melhorar em {cidade}?', 'automated_question_queued');

        $this->assertNotNull($mensagem);
        $this->assertStringContainsString('sua cidade', (string) $mensagem->body);
        $this->assertStringNotContainsString('{cidade}', (string) $mensagem->body);
    }
}
