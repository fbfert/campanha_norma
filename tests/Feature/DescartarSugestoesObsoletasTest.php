<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ReplySuggestionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Descarte das sugestões que perderam a validade.
 *
 * Uma sugestão fica obsoleta quando a pessoa escreve de novo: o texto responde
 * a uma mensagem que já não e a última. O envio recusa essas de qualquer forma,
 * então elas so ocupam espaço — e fila cheia de item morto e o que faz alguém
 * parar de ler a fila.
 *
 * Não e aprovação em massa: nada e enviado. A distinção importa, porque
 * aprovação em massa foi deliberadamente deixada de fora do sistema.
 */
class DescartarSugestoesObsoletasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
    }

    public function test_obsoleta_sai_da_fila_sem_enviar_nada(): void
    {
        $obsoleta = $this->sugestao(comMensagemNova: true);

        $this->actingAs($this->comPapel('administrador'))
            ->post(route('admin.reply-suggestions.discard-stale'))
            ->assertRedirect();

        $this->assertSame(ReplySuggestionStatus::Superseded, $obsoleta->refresh()->status);
        $this->assertSame('sugestao_obsoleta', $obsoleta->blocked_reason);
        $this->assertNull($obsoleta->active_source_message_id);

        $this->assertSame(
            0,
            ConversationMessage::query()->where('conversation_id', $obsoleta->conversation_id)->where('direction', 'outgoing')->count(),
            'Descartar não e aprovar: nenhuma mensagem pode sair.'
        );
    }

    public function test_sugestao_valida_continua_pendente(): void
    {
        $valida = $this->sugestao(comMensagemNova: false);

        $this->actingAs($this->comPapel('administrador'))->post(route('admin.reply-suggestions.discard-stale'));

        $this->assertSame(
            ReplySuggestionStatus::Pending,
            $valida->refresh()->status,
            'O que ainda vale precisa continuar esperando uma pessoa.'
        );
    }

    public function test_perfil_de_consulta_nao_descarta(): void
    {
        $obsoleta = $this->sugestao(comMensagemNova: true);

        $this->actingAs($this->comPapel('consulta'))
            ->post(route('admin.reply-suggestions.discard-stale'))
            ->assertForbidden();

        $this->assertSame(ReplySuggestionStatus::Pending, $obsoleta->refresh()->status);
    }

    public function test_fila_limpa_avisa_que_nao_havia_nada(): void
    {
        $this->sugestao(comMensagemNova: false);

        $this->actingAs($this->comPapel('administrador'))
            ->post(route('admin.reply-suggestions.discard-stale'))
            ->assertSessionHas('success', 'Nenhuma sugestão obsoleta na fila.');
    }

    public function test_a_tela_oferece_o_botao(): void
    {
        $this->sugestao(comMensagemNova: true);

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk()
            ->assertSee('Descartar obsoletas');
    }

    // --- Aprovação em massa ---------------------------------------------------

    /**
     * A ausência de aprovação em massa era deliberada e esta documentada. O
     * botão existe por decisão de quem opera a campanha, depois de a objeção
     * ter sido apresentada — e o que o código garante e que ele não vire uma
     * porta lateral: cada sugestão continua passando por todos os guards.
     */
    public function test_aprovar_todas_envia_as_validas(): void
    {
        $this->comEnvioPermitido();
        $valida = $this->sugestao(comMensagemNova: false);

        $this->actingAs($this->comPapel('administrador'))
            ->post(route('admin.reply-suggestions.approve-all'))
            ->assertRedirect();

        $this->assertSame(ReplySuggestionStatus::Sent, $valida->refresh()->status);
        $this->assertNotNull($valida->approved_by);
    }

    public function test_aprovar_todas_nao_toca_nas_obsoletas(): void
    {
        $this->comEnvioPermitido();
        $obsoleta = $this->sugestao(comMensagemNova: true);

        $this->actingAs($this->comPapel('administrador'))->post(route('admin.reply-suggestions.approve-all'));

        $this->assertSame(
            0,
            ConversationMessage::query()->where('conversation_id', $obsoleta->conversation_id)->where('direction', 'outgoing')->count(),
            'Obsoleta seria recusada no envio; incluí-la so encheria o relatório de falha previsível.'
        );
    }

    /**
     * Operador não aprova, nem uma nem todas: a separação entre operar e
     * liberar texto para o cidadão e do desenho da etapa.
     */
    public function test_operador_nao_aprova_em_massa(): void
    {
        $this->comEnvioPermitido();
        $valida = $this->sugestao(comMensagemNova: false);

        $this->actingAs($this->comPapel('operador'))
            ->post(route('admin.reply-suggestions.approve-all'))
            ->assertForbidden();

        $this->assertSame(ReplySuggestionStatus::Pending, $valida->refresh()->status);
    }

    /**
     * O guard continua valendo em massa. Sem modo que permita envio, nada sai —
     * e a tela precisa dizer por que, senão quem clicou acha que aprovou tudo.
     */
    public function test_guard_recusado_e_relatado_na_tela(): void
    {
        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'ai.response.mode'],
            ['group' => 'ai', 'value' => 'draft_only', 'type' => 'string', 'is_public' => false]
        );
        app(\App\Services\SystemSettingService::class)->forget();

        $valida = $this->sugestao(comMensagemNova: false);

        $resposta = $this->actingAs($this->comPapel('administrador'))->post(route('admin.reply-suggestions.approve-all'));

        $resposta->assertSessionHas('success', fn (string $mensagem): bool => str_contains($mensagem, 'recusada'));
        $this->assertNotSame(ReplySuggestionStatus::Sent, $valida->refresh()->status);
    }

    private function comEnvioPermitido(): void
    {
        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'ai.response.mode'],
            ['group' => 'ai', 'value' => 'approval_required', 'type' => 'string', 'is_public' => false]
        );

        app(\App\Services\SystemSettingService::class)->forget();
    }

    private function sugestao(bool $comMensagemNova): ConversationReplySuggestion
    {
        $flow = ConversationFlow::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::AnswerReceived,
            'expires_at' => now()->addDay(),
        ]);

        $origem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Falta praça no bairro.',
        ]);

        $sugestao = ConversationReplySuggestion::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_state_id' => $state->id,
            'conversation_flow_id' => $flow->id,
            'source_message_id' => $origem->id,
            'active_source_message_id' => $origem->id,
            'status' => ReplySuggestionStatus::Pending,
            'generated_text' => 'O que mudaria com essa praça?',
            'confidence' => 0.9,
        ]);

        if ($comMensagemNova) {
            ConversationMessage::factory()->create([
                'conversation_id' => $conversa->id,
                'direction' => 'incoming',
                'body' => 'Na verdade o problema maior e outro.',
            ]);
        }

        return $sugestao;
    }

    private function comPapel(string $papel): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
