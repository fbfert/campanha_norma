<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O que a máquina ouviu chega ao motor.
 *
 * `readableText()` existia no modelo e não era chamada por ninguém: o
 * classificador, os construtores de contexto e o gerador de resposta liam
 * `body` direto, que é vazio para áudio. Um áudio transcrito com sucesso
 * chegava ao motor como texto vazio, virava `ambiguous` e ia para atendimento
 * humano — a transcrição era paga, gravada e ignorada.
 *
 * Nunca apareceu porque a transcrição estava desligada em produção. Ligá-la sem
 * isto gastaria API e não mudaria nada.
 */
class AudioTranscritoViraRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/*' => fn () => Http::response(['success' => true, 'data' => [
                'request_id' => (string) Str::uuid(),
                'status' => 'sent',
                'external_message_id' => 'wamid.'.Str::random(16),
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);

        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    public function test_audio_transcrito_e_lido_como_autorizacao_e_nao_como_silencio(): void
    {
        [$state, $mensagem] = $this->conversaEsperandoPermissao();

        $this->transcrever($mensagem, 'sim, pode perguntar');

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $state->refresh();

        $this->assertNotSame(
            ConversationFlowStage::WaitingHuman,
            $state->current_stage,
            'O áudio dizia "sim, pode perguntar": tratá-lo como ambíguo é ignorar a transcrição.',
        );

        $this->assertTrue(
            in_array($state->current_stage, [
                ConversationFlowStage::QuestionSelected,
                ConversationFlowStage::WaitingAnswer,
            ], true),
            'Depois da autorização, a conversa recebe a pergunta.',
        );

        $pergunta = ConversationMessage::query()
            ->where('conversation_id', $mensagem->conversation_id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->first();

        $this->assertNotNull($pergunta, 'A pergunta da pesquisa precisa ter sido enfileirada.');
    }

    public function test_audio_sem_transcricao_continua_sendo_tratado_como_sem_texto(): void
    {
        // Sem transcrição não ha o que ler, e inventar sentido para o silêncio
        // seria pior que encaminhar para gente.
        [$state, $mensagem] = $this->conversaEsperandoPermissao();

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    public function test_transcricao_falha_nao_vira_texto(): void
    {
        [$state, $mensagem] = $this->conversaEsperandoPermissao();

        // Só transcrição bem-sucedida conta. Uma que falhou tem texto nulo, e
        // uma substituída é de uma tentativa anterior.
        MessageTranscription::create([
            'conversation_id' => $mensagem->conversation_id,
            'conversation_message_id' => $mensagem->id,
            'status' => TranscriptionStatus::Failed,
            'media_type' => 'ptt',
            'text' => 'sim, pode perguntar',
        ]);

        app(ConversationFlowService::class)->handleIncomingMessage($mensagem);

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->refresh()->current_stage);
    }

    /** @return array{0: ConversationFlowState, 1: ConversationMessage} */
    private function conversaEsperandoPermissao(): array
    {
        $flow = ConversationFlow::factory()->create([
            'status' => ConversationFlowStatus::Active,
            'max_main_questions' => 1,
            'validity_hours' => 48,
        ]);

        ConversationFlowQuestion::factory()->create([
            'conversation_flow_id' => $flow->id,
            'text' => 'O que a senhora acha que precisa melhorar na cidade?',
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create(['phone_normalized' => '5549988887777']);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        $state = ConversationFlowState::create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingPermission,
            'started_at' => now(),
            'expires_at' => now()->addHours(48),
        ]);

        // Áudio chega sem corpo: é essa ausência que fazia a transcrição ser
        // ignorada mais adiante.
        $mensagem = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'message_type' => 'ptt',
            'body' => null,
            'has_media' => true,
            'status' => ConversationMessageStatus::Received,
            'sender_phone_snapshot' => '5549988887777',
            'received_at' => now(),
        ]);

        return [$state, $mensagem];
    }

    private function transcrever(ConversationMessage $mensagem, string $texto): MessageTranscription
    {
        return MessageTranscription::create([
            'conversation_id' => $mensagem->conversation_id,
            'conversation_message_id' => $mensagem->id,
            'status' => TranscriptionStatus::Succeeded,
            'media_type' => $mensagem->message_type,
            'text' => $texto,
            'transcribed_at' => now(),
        ]);
    }
}
