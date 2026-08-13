<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Conversations\InternalNumbers;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A equipe não é atendida pelo próprio sistema.
 *
 * O sistema não distingue quem atende de quem é atendido. A conversa de
 * trabalho com a candidata — almoço com o candidato a vice, estratégia de
 * campanha — caiu no mesmo funil de quem responde a uma pesquisa, e em
 * 07/08/2026 ela recebeu "Recebemos sua mensagem, muito obrigado! Nossa equipe
 * vai ler com atenção." duas vezes no mesmo segundo.
 *
 * Nenhuma regra de conteúdo pega isso: naquele dia ela tinha escrito "Oiii",
 * que é o que qualquer eleitor escreve. O que distingue é quem está do outro
 * lado, e isso só uma lista de gente diz.
 */
class EquipeNaoEAtendidaPeloProprioSistemaTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNO = '5549991326174';

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
            'inbound_attendance.enabled' => '1',
        ]);
    }

    public function test_a_rede_de_seguranca_nao_responde_numero_interno(): void
    {
        [$conversa, $mensagem] = $this->conversaDaEquipe();

        $resultado = app(\App\Services\ConversationAutomation\PendingReplyResolver::class)
            ->resolve($conversa, $mensagem);

        $this->assertSame('numero_interno', $resultado['outcome']);

        $this->assertSame(
            0,
            ConversationMessage::where('conversation_id', $conversa->id)
                ->where('direction', ConversationMessageDirection::Outgoing)
                ->count(),
            'Nada pode ser criado: nem enviado, nem gravado como falha.',
        );
    }

    public function test_o_atendimento_de_entrada_recusa_numero_interno_ate_no_clique(): void
    {
        [$conversa] = $this->conversaDaEquipe();

        // Convidar a própria candidata a responder a pesquisa dela não é uma
        // decisão que alguém queira ter tomado por engano ao marcar tudo.
        $check = app(\App\Services\InboundAttendance\InboundAttendanceGuard::class)
            ->canStart($conversa, $this->perfil(), manual: true);

        $this->assertFalse($check['allowed']);
        $this->assertSame('numero_interno', $check['reason']);
    }

    public function test_quem_nao_esta_na_lista_continua_sendo_atendido(): void
    {
        [$conversa] = $this->conversaDaEquipe('5549988887777');

        $check = app(\App\Services\InboundAttendance\InboundAttendanceGuard::class)
            ->canStart($conversa->refresh(), $this->perfil(), manual: true);

        $this->assertTrue($check['allowed'], 'A lista precisa ser estreita: ela cala o sistema para quem está nela.');
    }

    public function test_a_comparacao_ignora_formatacao_do_telefone(): void
    {
        app(SystemSettingService::class)->updateMany([
            'conversations.internal_phones' => '+55 (49) 99132-6174',
        ]);

        // Telefone chega normalizado num lugar e digitado à mão no outro. Uma
        // trava que não casa com as duas formas existe no papel e não impede
        // nada.
        $this->assertTrue(app(InternalNumbers::class)->contains(self::INTERNO));
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function conversaDaEquipe(string $telefone = self::INTERNO): array
    {
        app(SystemSettingService::class)->updateMany(['conversations.internal_phones' => self::INTERNO]);

        $contato = Contact::factory()->create([
            'name' => 'Candidata',
            'phone_normalized' => preg_replace('/\D/', '', $telefone),
        ]);

        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'last_incoming_message_at' => now()->subHour(),
        ])->load('contact');

        $mensagem = ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'message_type' => 'text',
            'body' => 'Oiii',
            'status' => ConversationMessageStatus::Received,
            'sender_phone_snapshot' => $telefone,
            'received_at' => now()->subHour(),
        ]);

        return [$conversa, $mensagem];
    }

    private function perfil(): \App\Models\InboundAttendanceProfile
    {
        $flow = \App\Models\ConversationFlow::factory()->create([
            'status' => \App\Enums\ConversationFlowStatus::Active,
        ]);

        return \App\Models\InboundAttendanceProfile::create([
            'name' => 'Atendimento geral',
            'status' => \App\Enums\InboundAttendanceProfileStatus::Active,
            'is_fallback' => true,
            'match_priority' => 900,
            'conversation_flow_id' => $flow->id,
            'opening_mode' => \App\Enums\InboundOpeningMode::SurveyOnly,
            'presentation_text' => 'Posso te fazer uma pergunta?',
            'daily_start_limit' => 50,
            'homologation_threshold' => 0,
        ]);
    }
}
