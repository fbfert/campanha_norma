<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use App\Jobs\EvaluateConversationFlowJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationMessage;
use App\Models\InboundAttendanceProfile;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationResolverService;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A reação atravessando o pipeline de entrada.
 *
 * O ponto cego era este: a reação não é texto e não tem mídia, então escapava
 * dos quatro ramos do roteamento de `ProcessIncomingMessageJob` — nenhum job
 * era despachado. Ela ainda assim subia a conversa para o topo da fila de
 * pendentes e interrompia os lotes, como se alguém tivesse escrito algo.
 */
class ReacaoChegaPeloPipelineTest extends TestCase
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
            'inbound_attendance.enabled' => '1',
        ]);
    }

    private function processar(array $payload): void
    {
        (new ProcessIncomingMessageJob($payload))->handle(
            app(IncomingMessageNormalizerService::class),
            app(ContactMatcherService::class),
            app(ConversationResolverService::class),
            app(ReplyInterruptionService::class),
            app(ConversationEventService::class),
            app(AuditLogger::class),
        );
    }

    private function payloadDeReacao(array $extra = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'reacao-1',
            'sender_phone' => '5549999990001',
            'sender_name' => null,
            'recipient_phone' => '5549888888888',
            'message_type' => 'reaction',
            'text' => '👍',
            'sent_at' => now()->toIso8601String(),
            'received_at' => now()->toIso8601String(),
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => false,
            'quoted_external_message_id' => 'saida-1',
            'metadata' => ['type' => 'reaction', 'has_media' => false],
        ], $extra);
    }

    public function test_reacao_entra_como_reacao_e_guarda_a_mensagem_reagida(): void
    {
        Queue::fake();

        $this->processar($this->payloadDeReacao());

        $this->assertDatabaseHas('conversation_messages', [
            'external_message_id' => 'reacao-1',
            'message_type' => 'reaction',
            'body' => '👍',
            'quoted_message_id' => 'saida-1',
            'has_media' => false,
        ]);
    }

    /**
     * Sem este despacho a reação não chegava a decisão nenhuma: era gravada e
     * ficava ali.
     */
    public function test_reacao_e_despachada_para_a_avaliacao_do_fluxo(): void
    {
        Queue::fake();

        $this->processar($this->payloadDeReacao());

        Queue::assertPushed(EvaluateConversationFlowJob::class);
    }

    /**
     * Um 👍 não é alguém puxando assunto.
     *
     * Abrir a saudação de atendimento ali começaria a conversa que a pessoa
     * acabou de encerrar — e, logo depois de um disparo, seria uma saudação
     * para cada emoji no mesmo minuto.
     */
    public function test_reacao_nao_abre_atendimento_de_entrada(): void
    {
        $this->perfilDeAtendimentoAtivo();

        $conversa = Conversation::factory()->create([
            'contact_id' => null,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);

        $reacao = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'sender_phone_snapshot' => '5549999990001',
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => ConversationMessage::TYPE_REACTION,
            'body' => '👍',
            'quoted_message_id' => null,
        ]);

        EvaluateConversationFlowJob::dispatchSync($reacao->id);

        $this->assertSame(
            0,
            DB::table('inbound_attendance_attempts')->where('conversation_id', $conversa->id)->count(),
            'Reação não pode abrir atendimento de entrada.',
        );
    }

    /**
     * A supressão é da reação, não da pessoa: a mensagem escrita dela continua
     * indo para o atendimento como sempre foi.
     */
    public function test_mensagem_escrita_continua_indo_para_o_atendimento_de_entrada(): void
    {
        $this->perfilDeAtendimentoAtivo();

        $conversa = Conversation::factory()->create([
            'contact_id' => null,
            'external_chat_id' => '5549999990002@c.us',
            'provider' => 'web',
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'sender_phone_snapshot' => '5549999990002',
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => 'text',
            'body' => 'bom dia, preciso de ajuda',
        ]);

        EvaluateConversationFlowJob::dispatchSync($mensagem->id);

        $this->assertGreaterThan(
            0,
            DB::table('inbound_attendance_attempts')->where('conversation_id', $conversa->id)->count(),
        );
    }

    private function perfilDeAtendimentoAtivo(): InboundAttendanceProfile
    {
        return InboundAttendanceProfile::create([
            'name' => 'Atendimento geral',
            'status' => InboundAttendanceProfileStatus::Active,
            'is_fallback' => true,
            'match_expressions' => null,
            'match_priority' => 100,
            'conversation_flow_id' => ConversationFlow::factory()->create()->id,
            'opening_mode' => InboundOpeningMode::SurveyOnly,
            'presentation_text' => 'Olá! Posso te fazer uma pergunta?',
            'daily_start_limit' => 50,
            'homologation_threshold' => 0,
        ]);
    }
}
