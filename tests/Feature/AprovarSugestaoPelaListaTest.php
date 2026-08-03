<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ReplySuggestionStatus;
use App\Jobs\SendApprovedReplyJob;
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
 * Aprovar direto da lista de sugestões.
 *
 * A aprovação continua uma a uma, com confirmação, e o texto aparece inteiro na
 * linha. Aprovar o que não se leu seria o mesmo que aprovação em massa com
 * passos a mais: o que protege aqui não e o número de cliques, e a pessoa ter
 * lido o que vai chegar no WhatsApp de alguém.
 */
class AprovarSugestaoPelaListaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Queue::fake();

        // Sem um modo que permita envio, o guard recusa antes de olhar a
        // sugestão — e o que estaria sob teste seria a configuração, não o botão.
        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'ai.response.mode'],
            ['group' => 'ai', 'value' => 'approval_required', 'type' => 'string', 'is_public' => false]
        );

        app(\App\Services\SystemSettingService::class)->forget();
    }

    public function test_a_lista_mostra_o_texto_inteiro_e_oferece_aprovar(): void
    {
        $longo = 'Você mencionou a falta de manutenção nos espaços públicos e disse que isso afeta principalmente quem usa a praça no fim de semana com as crianças pequenas.';
        $sugestao = $this->sugestao(['generated_text' => $longo]);

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk()
            ->assertSee($longo)
            ->assertSee('Aprovar');
    }

    public function test_aprovar_pela_lista_enfileira_o_envio(): void
    {
        $sugestao = $this->sugestao();

        $this->actingAs($this->comPapel('administrador'))
            ->post(route('admin.reply-suggestions.approve', $sugestao))
            ->assertRedirect();

        $sugestao->refresh();
        $this->assertSame(ReplySuggestionStatus::Sent, $sugestao->status);
        $this->assertNotNull($sugestao->approved_by);
        Queue::assertPushed(SendApprovedReplyJob::class);
    }

    /**
     * Operador não aprova — a separação entre operar e liberar texto para o
     * cidadão e do desenho da etapa, e o botão não pode furá-la.
     */
    public function test_operador_nao_ve_nem_usa_o_botao(): void
    {
        $sugestao = $this->sugestao();

        $this->actingAs($this->comPapel('operador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk()
            ->assertDontSee('>Aprovar<', false);

        $this->actingAs($this->comPapel('operador'))
            ->post(route('admin.reply-suggestions.approve', $sugestao))
            ->assertForbidden();
    }

    /**
     * Sugestão obsoleta e a que mais convida ao erro: o texto parece bom, mas a
     * pessoa já escreveu outra coisa depois.
     */
    public function test_sugestao_obsoleta_nao_oferece_aprovar(): void
    {
        $sugestao = $this->sugestao();

        ConversationMessage::factory()->create([
            'conversation_id' => $sugestao->conversation_id,
            'direction' => 'incoming',
            'body' => 'Na verdade o problema maior e outro',
        ]);

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.reply-suggestions.index'))
            ->assertOk()
            ->assertSee('Obsoleta')
            ->assertDontSee('>Aprovar<', false);
    }

    private function sugestao(array $atributos = []): ConversationReplySuggestion
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
            'body' => 'Falta manutenção na praça',
        ]);

        return ConversationReplySuggestion::factory()->create(array_merge([
            'conversation_id' => $conversa->id,
            'conversation_flow_state_id' => $state->id,
            'conversation_flow_id' => $flow->id,
            'source_message_id' => $origem->id,
            'active_source_message_id' => $origem->id,
            'status' => ReplySuggestionStatus::Pending,
            'generated_text' => 'O que você acha que mudaria com essa manutenção?',
            'confidence' => 0.9,
        ], $atributos));
    }

    private function comPapel(string $papel): User
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
