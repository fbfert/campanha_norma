<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Reagir é responder.
 *
 * Reagir é a resposta mais barata que o WhatsApp oferece, e por isso é a que
 * muita gente dá. Até aqui um 👍 no convite não chegava a lugar nenhum: pelo
 * provedor `web` o evento sequer era assinado, e pela Meta a reação virava uma
 * mensagem de tipo `reaction` que nenhum ramo do roteamento pegava. A pessoa
 * tinha respondido; do nosso lado, ela continuava calada.
 *
 * O que este teste protege é a regra que impede o contrário do problema: um
 * emoji não pode virar consentimento sozinho. Só conta a reação feita na
 * mensagem que fez a pergunta.
 */
class ReacaoNaConversaViraOptInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
        Config::set('queue.default', 'database');

        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    /**
     * @return array{0: Contact, 1: Conversation, 2: ConversationFlowState, 3: ConversationMessage}
     */
    private function cenario(ConversationFlowStage $estagio = ConversationFlowStage::WaitingPermission): array
    {
        $contato = Contact::factory()->create([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5549999990001',
            'consent_status' => ConsentStatus::NotInformed,
        ]);

        $fluxo = ConversationFlow::factory()->create();
        ConversationFlowQuestion::factory()->count(3)->create(['conversation_flow_id' => $fluxo->id]);

        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $fluxo->id,
            'current_stage' => $estagio,
        ]);

        $pergunta = $this->nossa($conversa, 'Posso te fazer três perguntas rápidas sobre o bairro?');

        return [$contato, $conversa, $state, $pergunta];
    }

    private function nossa(Conversation $conversa, string $texto): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Automation,
            'status' => ConversationMessageStatus::Sent,
            'message_type' => 'text',
            'body' => $texto,
            'external_message_id' => 'saida-'.uniqid(),
        ]);
    }

    private function reacao(Conversation $conversa, string $emoji, ?ConversationMessage $alvo): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => ConversationMessage::TYPE_REACTION,
            'body' => $emoji,
            'has_media' => false,
            'quoted_message_id' => $alvo?->external_message_id,
        ]);
    }

    public function test_polegar_na_pergunta_de_permissao_autoriza_e_a_primeira_pergunta_sai(): void
    {
        Queue::fake();
        [, $conversa, $state, $pergunta] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $pergunta));

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->current_stage);
        $this->assertNotNull($state->selected_question_id);
    }

    /**
     * A finalidade fica escrita com a frase exata a que a pessoa respondeu.
     *
     * Sem ela o banco diria apenas que alguém consentiu, sem dizer com o quê —
     * e um consentimento que não se sabe descrever não sustenta nada seis meses
     * depois.
     */
    public function test_reacao_positiva_grava_consentimento_com_a_pergunta_dentro(): void
    {
        Queue::fake();
        [$contato, $conversa, , $pergunta] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $pergunta));

        $contato->refresh();

        $this->assertSame(ConsentStatus::Granted, $contato->consent_status);
        $this->assertSame('reacao_na_conversa', $contato->consent_source);
        $this->assertStringContainsString('👍', (string) $contato->consent_text);
        $this->assertStringContainsString('Posso te fazer três perguntas', (string) $contato->consent_text);
    }

    /**
     * Quem pediu para sair pediu para sair. Devolver essa pessoa à base a
     * partir de um emoji seria desfazer, sem ninguém saber, a única decisão que
     * ela tomou por escrito.
     */
    public function test_reacao_positiva_nao_ressuscita_consentimento_revogado(): void
    {
        Queue::fake();
        [$contato, $conversa, , $pergunta] = $this->cenario();
        $contato->forceFill(['consent_status' => ConsentStatus::Revoked])->save();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $pergunta));

        $this->assertSame(ConsentStatus::Revoked, $contato->refresh()->consent_status);
    }

    public function test_reacao_negativa_recusa_sem_marcar_nao_contatar(): void
    {
        Queue::fake();
        [$contato, $conversa, $state, $pergunta] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👎', $pergunta));

        $state->refresh();

        $this->assertSame(ConversationFlowStage::PermissionDenied, $state->current_stage);

        // Recusar não é sair: só a palavra escrita descadastra.
        $this->assertFalse((bool) $contato->refresh()->do_not_contact);
        $this->assertSame(ConsentStatus::NotInformed, $contato->consent_status);
    }

    /**
     * O 👍 numa mensagem antiga é a pessoa acusando recebimento de outra coisa.
     * Ele não responde a pergunta nenhuma, e não pode autorizar nada.
     */
    public function test_reacao_em_mensagem_que_nao_e_a_ultima_nossa_nao_decide_nada(): void
    {
        Queue::fake();
        [$contato, $conversa, $state, $antiga] = $this->cenario();

        // Depois da pergunta, mandamos outra coisa. A pergunta deixou de ser a
        // última mensagem nossa.
        $this->nossa($conversa, 'Ah, e obrigado pelo retorno de ontem!');

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $antiga));

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->refresh()->current_stage);
        $this->assertSame(ConsentStatus::NotInformed, $contato->refresh()->consent_status);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'automation_blocked',
        ]);
    }

    public function test_reacao_sem_alvo_conhecido_nao_decide_nada(): void
    {
        Queue::fake();
        [$contato, $conversa, $state] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', null));

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->refresh()->current_stage);
        $this->assertSame(ConsentStatus::NotInformed, $contato->refresh()->consent_status);
    }

    /**
     * Reagir na própria mensagem não é responder a nós.
     */
    public function test_reacao_em_mensagem_recebida_nao_decide_nada(): void
    {
        Queue::fake();
        [, $conversa, $state] = $this->cenario();

        $dela = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'oi',
            'external_message_id' => 'entrada-1',
        ]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $dela));

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->refresh()->current_stage);
    }

    /**
     * A pergunta aberta pedia texto.
     *
     * Gravar um emoji como resposta produziria o mesmo dado inventado que
     * "batata" produziu em 17/08/2026, quando uma palavra de sorteio virou
     * opinião sobre o problema mais urgente da cidade.
     */
    public function test_reacao_no_meio_da_pesquisa_nao_vira_resposta(): void
    {
        Queue::fake();
        [, $conversa, $state, $pergunta] = $this->cenario(ConversationFlowStage::WaitingAnswer);
        $state->forceFill(['selected_question_snapshot' => 'Qual o problema mais urgente do bairro?'])->save();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '👍', $pergunta));

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversa->id,
            'event_type' => 'automation_blocked',
        ]);
    }

    /**
     * Emoji fora das listas não é sim nem não. Ele aparece na conversa e espera
     * gente — o mesmo destino de uma resposta escrita ambígua.
     */
    public function test_reacao_sem_significado_configurado_nao_autoriza(): void
    {
        Queue::fake();
        [$contato, $conversa, $state, $pergunta] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->reacao($conversa, '🍕', $pergunta));

        $this->assertNotSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
        $this->assertSame(ConsentStatus::NotInformed, $contato->refresh()->consent_status);
    }
}
