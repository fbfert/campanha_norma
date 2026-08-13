<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\ConversationAutomationGuard;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O aviso chega a quem nunca entrou em pesquisa.
 *
 * `PendingReplyResolver::sendWithoutFlow` foi escrito exatamente para essa
 * conversa: sem estado de fluxo, o aviso sai pelo serviço de saída comum,
 * porque "ignorar essas deixaria justamente quem mais ficou no vácuo sem
 * retorno".
 *
 * A porta do envio desfazia isso. Ela tirava o contato do estado do fluxo, e
 * sem estado concluía que não havia contato — em conversa com contato
 * identificado. O aviso era criado, enfileirado e recusado no último passo com
 * `contato_nao_identificado`.
 *
 * Na conversa da Norma Rodrigues, contato 1020, isso se repetiu a cada cinco
 * minutos: dezenas de "Recebemos sua mensagem" gravados como falha, e ela sem
 * receber nenhum.
 */
class AvisoChegaSemFluxoNaConversaTest extends TestCase
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
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    public function test_a_porta_encontra_o_contato_na_conversa_sem_estado_de_fluxo(): void
    {
        $conversa = $this->conversaComContato();

        $check = app(ConversationAutomationGuard::class)->canSendSafetyNet(null, $conversa);

        $this->assertTrue(
            $check['allowed'],
            'A conversa tem contato identificado; recusar por falta de contato é olhar no lugar errado.',
        );
    }

    public function test_o_aviso_sai_de_verdade_em_conversa_sem_fluxo(): void
    {
        $conversa = $this->conversaComContato();
        $aviso = $this->avisoPendente($conversa);

        (new SendAutomatedConversationReplyJob($aviso->id, true))->handle(
            app(\App\Services\WhatsApp\WhatsAppProviderManager::class),
            app(\App\Services\Conversations\ConversationEventService::class),
            app(ConversationAutomationGuard::class),
            app(\App\Services\AuditLogger::class),
        );

        $this->assertSame(ConversationMessageStatus::Sent, $aviso->refresh()->status);

        $this->assertFalse(
            ConversationEvent::where('conversation_id', $conversa->id)
                ->where('event_type', 'automated_reply_blocked')
                ->exists(),
            'Nenhum bloqueio deve ser registrado: o contato está identificado.',
        );
    }

    public function test_sem_contato_na_conversa_o_aviso_continua_recusado(): void
    {
        // A proteção que importa continua valendo: sem contato não ha para
        // quem mandar, e isso não é o mesmo que ter contato e olhar no lugar
        // errado.
        $conversa = Conversation::factory()->create(['contact_id' => null]);

        $check = app(ConversationAutomationGuard::class)->canSendSafetyNet(null, $conversa);

        $this->assertFalse($check['allowed']);
        $this->assertSame('contato_nao_identificado', $check['reason']);
    }

    public function test_quem_pediu_para_sair_continua_recusado(): void
    {
        $conversa = $this->conversaComContato();
        $conversa->contact->forceFill(['do_not_contact' => true, 'do_not_contact_at' => now()])->save();

        $check = app(ConversationAutomationGuard::class)->canSendSafetyNet(null, $conversa->refresh());

        $this->assertFalse($check['allowed']);
        $this->assertSame('contato_nao_contatar', $check['reason']);
    }

    private function conversaComContato(): Conversation
    {
        $contato = Contact::factory()->create(['phone_normalized' => '5549988887777']);

        return Conversation::factory()->create(['contact_id' => $contato->id])->load('contact');
    }

    private function avisoPendente(Conversation $conversa): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Automation,
            'message_type' => 'text',
            'body' => 'Recebemos sua mensagem, muito obrigado! Nossa equipe vai ler com atenção.',
            'status' => ConversationMessageStatus::Pending,
            'recipient_phone_snapshot' => '5549988887777',
            'request_id' => (string) Str::uuid(),
        ]);
    }
}
