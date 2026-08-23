<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A reação aparece na conversa, e aparece dizendo em quê.
 *
 * Mostrar só o emoji seria indistinguível de alguém que mandou um emoji solto —
 * e é a mensagem reagida que decide se aquilo respondeu alguma coisa. Quem lê a
 * conversa precisa das duas informações juntas para entender por que a pergunta
 * seguinte saiu.
 */
class ReacaoApareceNaConversaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function conversaComReacao(string $emoji = '👍', bool $comAlvo = true): array
    {
        $conversa = Conversation::factory()->create();

        $pergunta = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Automation,
            'status' => ConversationMessageStatus::Sent,
            'message_type' => 'text',
            'body' => 'Posso te fazer três perguntas rápidas sobre o bairro?',
            'external_message_id' => 'saida-1',
        ]);

        $reacao = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => ConversationMessage::TYPE_REACTION,
            'body' => $emoji,
            'has_media' => false,
            'quoted_message_id' => $comAlvo ? $pergunta->external_message_id : 'saida-que-nao-existe',
        ]);

        return [$conversa, $reacao];
    }

    public function test_a_conversa_mostra_o_emoji_e_a_mensagem_reagida(): void
    {
        [$conversa] = $this->conversaComReacao();

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.inbox.show', $conversa))
            ->assertOk()
            ->assertSee('👍', false)
            ->assertSee('Reagiu a esta mensagem')
            ->assertSee('Posso te fazer três perguntas rápidas sobre o bairro?', false);
    }

    /**
     * A mensagem reagida pode ser anterior à sincronização e simplesmente não
     * existir aqui. A tela diz isso, em vez de mostrar uma citação vazia.
     */
    public function test_reacao_sem_alvo_no_banco_explica_a_ausencia(): void
    {
        [$conversa] = $this->conversaComReacao(comAlvo: false);

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.inbox.show', $conversa))
            ->assertOk()
            ->assertSee('👍', false)
            ->assertSee('não está nesta conversa', false);
    }

    /**
     * Reação nunca cai no "Mensagem sem conteúdo": ela tem conteúdo, e o
     * conteúdo é o emoji.
     */
    public function test_reacao_nao_e_tratada_como_mensagem_vazia(): void
    {
        [$conversa] = $this->conversaComReacao();

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.inbox.show', $conversa))
            ->assertOk()
            ->assertDontSee('Mensagem sem conteúdo');
    }

    /**
     * A conversa atualiza sozinha por polling, e o mesmo partial serve os dois
     * caminhos. Uma reação que chega depois de a tela estar aberta precisa
     * aparecer igual.
     */
    public function test_reacao_que_chega_pelo_polling_aparece_igual(): void
    {
        [$conversa, $reacao] = $this->conversaComReacao();

        $html = $this->actingAs($this->userWithRole('administrador'))
            ->getJson(route('admin.inbox.messages', [$conversa, 'after_id' => $reacao->id - 1]))
            ->assertOk()
            ->json('html');

        // O corpo trafega dentro de JSON, onde o emoji vai escapado. O que
        // chega ao navegador é o mesmo HTML da carga inicial.
        $this->assertStringContainsString('👍', (string) $html);
        $this->assertStringContainsString('Reagiu a esta mensagem', (string) $html);
        $this->assertStringContainsString('Posso te fazer três perguntas', (string) $html);
    }

    /**
     * Na lista de conversas a prévia é uma linha só. Um emoji sozinho ali não
     * diz nada — quem olha a fila precisa saber que aquilo foi uma reação.
     */
    public function test_lista_de_conversas_diz_que_a_ultima_mensagem_foi_uma_reacao(): void
    {
        [$conversa] = $this->conversaComReacao();

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('Reagiu com 👍', false);

        $this->assertDatabaseHas('conversations', ['id' => $conversa->id]);
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
