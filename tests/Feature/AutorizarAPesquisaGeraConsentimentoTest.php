<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\TranscriptionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\MessageTranscription;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Autorizar a pesquisa registra o consentimento — escrevendo ou reagindo.
 *
 * A primeira versão da leitura de reação gravava `consent_status` só quando a
 * autorização vinha de um 👍. Ficou uma assimetria sem defesa: tocar num emoji
 * registrava mais do que escrever "sim, pode perguntar", quando a palavra
 * escrita é o ato mais deliberado dos dois.
 *
 * Os dois caminhos são o mesmo ato — a pessoa leu uma mensagem que dizia para
 * que serviria e concordou —, e por isso os dois gravam.
 */
class AutorizarAPesquisaGeraConsentimentoTest extends TestCase
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
    private function cenario(): array
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
            'current_stage' => ConversationFlowStage::WaitingPermission,
        ]);

        $pergunta = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Automation,
            'status' => ConversationMessageStatus::Sent,
            'message_type' => 'text',
            'body' => 'Posso te fazer três perguntas rápidas sobre o bairro?',
            'external_message_id' => 'saida-1',
        ]);

        return [$contato, $conversa, $state, $pergunta];
    }

    private function escrita(Conversation $conversa, string $texto): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => 'text',
            'body' => $texto,
        ]);
    }

    public function test_sim_escrito_grava_consentimento_com_a_pergunta_dentro(): void
    {
        Queue::fake();
        [$contato, $conversa] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->escrita($conversa, 'Sim, pode perguntar'));

        $contato->refresh();

        $this->assertSame(ConsentStatus::Granted, $contato->consent_status);
        $this->assertSame('resposta_na_conversa', $contato->consent_source);
        $this->assertStringContainsString('Sim, pode perguntar', (string) $contato->consent_text);
        $this->assertStringContainsString('Posso te fazer três perguntas', (string) $contato->consent_text);
        $this->assertNotNull($contato->consent_at);
    }

    /**
     * A origem diz qual foi o ato. Escrever e reagir são atos diferentes, e
     * quem conferir o registro depois vai querer saber qual dos dois foi.
     */
    public function test_reagir_e_escrever_gravam_origens_distintas(): void
    {
        Queue::fake();
        [$contato, $conversa, , $pergunta] = $this->cenario();

        $reacao = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => ConversationMessage::TYPE_REACTION,
            'body' => '👍',
            'quoted_message_id' => $pergunta->external_message_id,
        ]);

        app(ConversationFlowService::class)->handleIncomingMessage($reacao);

        $this->assertSame('reacao_na_conversa', $contato->refresh()->consent_source);
    }

    public function test_recusa_escrita_nao_grava_consentimento(): void
    {
        Queue::fake();
        [$contato, $conversa] = $this->cenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->escrita($conversa, 'não quero'));

        $this->assertSame(ConsentStatus::NotInformed, $contato->refresh()->consent_status);
    }

    public function test_sim_escrito_nao_ressuscita_consentimento_revogado(): void
    {
        Queue::fake();
        [$contato, $conversa] = $this->cenario();
        $contato->forceFill(['consent_status' => ConsentStatus::Revoked])->save();

        app(ConversationFlowService::class)->handleIncomingMessage($this->escrita($conversa, 'Sim, pode perguntar'));

        $this->assertSame(ConsentStatus::Revoked, $contato->refresh()->consent_status);
    }

    /**
     * Áudio transcrito como "sim" continua autorizando a pergunta — o custo de
     * errar ali é uma pergunta a mais numa conversa que a pessoa abriu.
     *
     * Consentimento é outra coisa: fica no cadastro, sustenta disparo futuro, e
     * um consentimento criado por engano de transcrição é indistinguível, no
     * banco, de um de verdade. É a mesma linha que a inscrição por palavra-chave
     * traça.
     */
    public function test_sim_ouvido_pela_maquina_autoriza_mas_nao_consente(): void
    {
        Queue::fake();
        [$contato, $conversa, $state] = $this->cenario();

        $audio = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => 'ptt',
            'body' => null,
            'has_media' => true,
        ]);

        MessageTranscription::create([
            'conversation_id' => $audio->conversation_id,
            'conversation_message_id' => $audio->id,
            'status' => TranscriptionStatus::Succeeded,
            'media_type' => 'ptt',
            'text' => 'sim, pode perguntar',
        ]);

        app(ConversationFlowService::class)->handleIncomingMessage($audio->refresh());

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->refresh()->current_stage);
        $this->assertSame(ConsentStatus::NotInformed, $contato->refresh()->consent_status);
    }
}
