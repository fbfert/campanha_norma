<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O botão de retomar a pesquisa, onde a pessoa está.
 *
 * A automação pausa a conversa quando não sabe seguir sozinha, e responder ao
 * contato não desfaz isso: a pesquisa dele fica parada. A ação de retomar
 * existia só na tela de Pesquisa conversacional, o que obrigava sair da
 * conversa, achar o estado em outra tela e voltar — na prática, não retomar.
 *
 * O que estes testes cobram é o botão aparecer exatamente quando há o que
 * retomar. Botão que não faz nada ensina a ignorar botão, e botão que falta
 * onde precisa manda a pessoa para o terminal.
 */
class BotaoRetomarFluxoNasConversasTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversa_com_fluxo_pausado_mostra_o_botao_nas_duas_telas(): void
    {
        $conversa = $this->conversaComFluxo(pausado: true);
        $usuario = $this->admin();

        $this->actingAs($usuario)->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Retomar fluxo')
            ->assertSee('pesquisa pausada');

        $this->actingAs($usuario)->get(route('admin.conversations.show', $conversa))
            ->assertOk()
            ->assertSee('Retomar fluxo');
    }

    public function test_fluxo_rodando_nao_mostra_botao(): void
    {
        $conversa = $this->conversaComFluxo(pausado: false);

        $this->actingAs($this->admin())->get(route('admin.conversations.show', $conversa))
            ->assertOk()
            ->assertDontSee('Retomar fluxo');
    }

    public function test_conversa_sem_fluxo_nenhum_nao_mostra_botao(): void
    {
        $contato = Contact::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);

        $this->actingAs($this->admin())->get(route('admin.conversations.show', $conversa))
            ->assertOk()
            ->assertDontSee('Retomar fluxo');
    }

    /**
     * Quem não controla a automação não vê o botão.
     *
     * Ver a conversa e mandar a pesquisa voltar a falar sozinha com a pessoa
     * são permissões diferentes, e a segunda é a que mexe no que sai daqui.
     *
     * O papel usado é `consulta`, e não `operador`: operador **tem**
     * `conversation_automation.control` e vê o botão com razão — quem opera o
     * atendimento é justamente quem precisa retomar. Escrever este teste com
     * operador foi engano meu, e ele reprovou apontando o engano.
     */
    public function test_sem_permissao_de_controle_o_botao_some(): void
    {
        $conversa = $this->conversaComFluxo(pausado: true);

        $this->seed(RolePermissionSeeder::class);
        $consulta = User::factory()->create();
        $consulta->roles()->attach(Role::where('slug', 'consulta')->firstOrFail());

        $this->actingAs($consulta)
            ->get(route('admin.conversations.show', $conversa))
            ->assertOk()
            ->assertDontSee('Retomar fluxo');
    }

    /**
     * Operador vê, porque é quem atende.
     */
    public function test_operador_ve_o_botao(): void
    {
        $conversa = $this->conversaComFluxo(pausado: true);

        $this->seed(RolePermissionSeeder::class);
        $operador = User::factory()->create();
        $operador->roles()->attach(Role::where('slug', 'operador')->firstOrFail());

        $this->actingAs($operador)
            ->get(route('admin.conversations.show', $conversa))
            ->assertOk()
            ->assertSee('Retomar fluxo');
    }

    public function test_o_botao_retoma_de_verdade(): void
    {
        $conversa = $this->conversaComFluxo(pausado: true);
        $estado = $conversa->flowState;

        $this->actingAs($this->admin())
            ->post(route('admin.conversation-automation.resume', $estado))
            ->assertRedirect();

        $estado->refresh();

        $this->assertFalse($estado->is_paused);
        $this->assertFalse($estado->needs_human_review);
    }

    /**
     * O filtro junta numa tela só quem parou no meio.
     *
     * Sem ele as conversas pausadas ficam espalhadas pela listagem inteira. No
     * dia em que este teste foi escrito havia doze, dez delas paradas fazia
     * mais de uma semana — o botão de retomar existia e ninguém as achava.
     */
    public function test_o_filtro_mostra_so_quem_esta_com_a_pesquisa_pausada(): void
    {
        $pausada = $this->conversaComFluxo(pausado: true);
        $rodando = $this->conversaComFluxo(pausado: false);

        $semFluxo = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $resposta = $this->actingAs($this->admin())
            ->get(route('admin.conversations.index', ['paused_flow' => 1]))
            ->assertOk();

        $resposta->assertSee($pausada->contact->name);
        $resposta->assertDontSee($rodando->contact->name);
        $resposta->assertDontSee($semFluxo->contact->name);
    }

    public function test_sem_o_filtro_a_lista_traz_todas(): void
    {
        $pausada = $this->conversaComFluxo(pausado: true);
        $rodando = $this->conversaComFluxo(pausado: false);

        $this->actingAs($this->admin())
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee($pausada->contact->name)
            ->assertSee($rodando->contact->name);
    }

    private function conversaComFluxo(bool $pausado): Conversation
    {
        $contato = Contact::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);

        ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => ConversationFlow::factory()->create()->id,
            'is_paused' => $pausado,
            'needs_human_review' => $pausado,
        ]);

        return $conversa->fresh();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'administrador')->firstOrFail());

        return $user;
    }
}
