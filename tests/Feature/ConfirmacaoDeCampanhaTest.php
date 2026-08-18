<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\SendResult;
use App\Enums\ContactStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Jobs\EnviarConfirmacaoDeCampanhaJob;
use App\Jobs\EvaluateConversationFlowJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\KeywordCampaigns\ConfirmationThrottle;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A confirmação da inscrição, e o teto que impede que ela derrube o número.
 *
 * O risco que justifica o desenho: divulgação bem-sucedida gera centenas de
 * mensagens recebidas em minutos, e responder todas no ritmo do worker é o
 * comportamento que mais rápido leva um número do WhatsApp Web a bloqueio. Um
 * número bloqueado interrompe a operação inteira, não apenas a campanha.
 */
class ConfirmacaoDeCampanhaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KeywordCampaignTrigger::esquecerCache();
    }

    private function ajustar(string $chave, string $valor): void
    {
        SystemSetting::updateOrCreate(
            ['key' => $chave],
            ['group' => 'keyword_campaigns', 'value' => $valor, 'type' => 'integer', 'is_public' => false],
        );

        app(SystemSettingService::class)->forget();
    }

    /**
     * A segunda mensagem da mesma pessoa cai na mesma conversa, como na vida
     * real: `conversations` tem chave única em provedor mais chat externo.
     */
    private function mensagemRecebida(string $telefone = '5549999990001', string $texto = 'quero o sorteio'): ConversationMessage
    {
        $conversa = Conversation::firstOrCreate(
            ['provider' => 'web', 'external_chat_id' => "{$telefone}@c.us"],
            Conversation::factory()->make()->only([
                'contact_id',
                'connection_id',
                'status',
                'priority',
                'last_message_direction',
                'last_message_at',
                'last_incoming_message_at',
                'unread_count',
                'is_archived',
            ]),
        );

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'sender_phone_snapshot' => $telefone,
            'sender_name_snapshot' => 'Maria da Silva',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $texto,
        ]);
    }

    public function test_inscricao_enfileira_a_confirmacao_da_campanha(): void
    {
        Bus::fake([EnviarConfirmacaoDeCampanhaJob::class]);

        KeywordCampaign::factory()->create(['confirmation_text' => 'Inscrição confirmada! Boa sorte.']);

        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);

        $enviada = ConversationMessage::query()
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->firstOrFail();

        $this->assertSame('Inscrição confirmada! Boa sorte.', $enviada->body);
        $this->assertSame(ConversationMessageStatus::Pending, $enviada->status);

        Bus::assertDispatched(EnviarConfirmacaoDeCampanhaJob::class);
    }

    /**
     * Quem já está inscrito e manda de novo recebe o texto próprio, e não uma
     * segunda inscrição.
     */
    public function test_ja_inscrito_recebe_o_texto_de_ja_inscrito_e_nao_duplica(): void
    {
        Bus::fake([EnviarConfirmacaoDeCampanhaJob::class]);

        KeywordCampaign::factory()->create([
            'confirmation_text' => 'Inscrição confirmada!',
            'already_enrolled_text' => 'Você já está na lista, pode ficar tranquilo.',
        ]);

        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);
        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);

        $this->assertSame(1, KeywordCampaignParticipation::count());

        $textos = ConversationMessage::query()
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->orderBy('id')
            ->pluck('body')
            ->all();

        $this->assertSame(['Inscrição confirmada!', 'Você já está na lista, pode ficar tranquilo.'], $textos);
    }

    /**
     * Campanha ativa cujo período já passou continua reconhecendo a própria
     * palavra, para poder dizer que acabou. Sem isso, quem viu o cartaz uma
     * semana depois escreve, não recebe nada, e conclui que se inscreveu.
     */
    public function test_fora_da_vigencia_responde_o_texto_de_encerramento(): void
    {
        Bus::fake([EnviarConfirmacaoDeCampanhaJob::class]);

        KeywordCampaign::factory()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'out_of_window_text' => 'As inscrições se encerraram ontem. Obrigado pelo interesse!',
        ]);

        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
        $this->assertSame(
            'As inscrições se encerraram ontem. Obrigado pelo interesse!',
            ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->firstOrFail()->body,
        );
    }

    /**
     * Texto de encerramento nulo é silêncio deliberado: responder reabre a
     * conversa com quem chegou tarde.
     */
    public function test_fora_da_vigencia_sem_texto_nao_responde_nada(): void
    {
        KeywordCampaign::factory()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'out_of_window_text' => null,
        ]);

        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);

        $this->assertSame(0, ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    /**
     * Campanha encerrada na tela cala de vez: é assim que o operador desliga.
     */
    public function test_campanha_encerrada_nao_responde_mesmo_com_texto(): void
    {
        KeywordCampaign::factory()->encerrada()->create([
            'out_of_window_text' => 'As inscrições se encerraram.',
        ]);

        EvaluateConversationFlowJob::dispatchSync($this->mensagemRecebida()->id);

        $this->assertSame(0, ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    /**
     * A rajada é espalhada, não descartada.
     *
     * Cem inscrições com teto de vinte por minuto: vinte saem agora e oitenta
     * são adiadas. Nenhuma some.
     */
    public function test_rajada_respeita_o_teto_e_adia_o_resto_sem_descartar(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '20');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '0');

        Carbon::setTestNow('2026-09-01 10:00:00');

        $limitador = app(ConfirmationThrottle::class);

        $permitidas = 0;
        $adiadas = 0;

        for ($i = 0; $i < 100; $i++) {
            $decisao = $limitador->reservar();

            $decisao->permitida ? $permitidas++ : $adiadas++;
        }

        $this->assertSame(20, $permitidas);
        $this->assertSame(80, $adiadas, 'Nenhuma confirmação pode ser descartada: o excedente é adiado.');
        $this->assertSame(20, $limitador->usadoNoMinuto());

        Carbon::setTestNow();
    }

    /**
     * O contador não pode ficar inflado pelas tentativas recusadas, senão o
     * minuto seguinte nasce com o teto já consumido.
     */
    public function test_tentativa_recusada_devolve_a_vaga(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '3');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '0');

        Carbon::setTestNow('2026-09-01 10:00:00');

        $limitador = app(ConfirmationThrottle::class);

        for ($i = 0; $i < 30; $i++) {
            $limitador->reservar();
        }

        $this->assertSame(3, $limitador->usadoNoMinuto());

        Carbon::setTestNow();
    }

    /**
     * O defeito que este limitador não repete.
     *
     * `SendingRateLimiterService` lê em `check()` e incrementa em `consume()`
     * sem trava, então dois workers passam os dois pela leitura antes de
     * qualquer um incrementar. Aqui o incremento vem primeiro, e a decisão sai
     * do valor que ele devolveu — a corrida acontece dentro de uma operação
     * atômica, e não em volta de duas.
     */
    public function test_dois_workers_concorrentes_nao_furam_o_teto(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '5');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '0');

        Carbon::setTestNow('2026-09-01 10:00:00');

        $primeiro = app(ConfirmationThrottle::class);
        $segundo = app(ConfirmationThrottle::class);

        $permitidas = 0;

        // Intercalado de propósito: é a forma mais próxima de dois workers
        // drenando a mesma fila que um teste síncrono alcança.
        for ($i = 0; $i < 10; $i++) {
            $permitidas += $primeiro->reservar()->permitida ? 1 : 0;
            $permitidas += $segundo->reservar()->permitida ? 1 : 0;
        }

        $this->assertSame(5, $permitidas);

        Carbon::setTestNow();
    }

    /**
     * O minuto seguinte começa limpo.
     */
    public function test_o_teto_e_por_minuto(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '2');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '0');

        $limitador = app(ConfirmationThrottle::class);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->assertTrue($limitador->reservar()->permitida);
        $this->assertTrue($limitador->reservar()->permitida);
        $this->assertFalse($limitador->reservar()->permitida);

        Carbon::setTestNow('2026-09-01 10:01:00');
        $this->assertTrue($limitador->reservar()->permitida);

        Carbon::setTestNow();
    }

    /**
     * O intervalo mínimo é reservado por uma chave que expira sozinha, e não
     * por comparação de horário: comparar deixa dois workers lerem a mesma hora
     * e concluírem os dois que podem enviar.
     */
    public function test_intervalo_minimo_segura_a_segunda_confirmacao(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '100');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '2');

        $limitador = app(ConfirmationThrottle::class);

        $this->assertTrue($limitador->reservar()->permitida);

        $segunda = $limitador->reservar();

        $this->assertFalse($segunda->permitida);
        $this->assertSame('intervalo_minimo', $segunda->motivo);
        $this->assertGreaterThan(0, $segunda->tentarEmSegundos);
    }

    /**
     * A confirmação sai fora da janela de horário da automação de conversas.
     *
     * Quem escreve às 23h está com o celular na mão. Segurar até as 8h produz a
     * segunda e a terceira mensagem da mesma pessoa perguntando se deu certo,
     * que é pior para a reputação do número do que ter respondido.
     */
    public function test_confirmacao_sai_as_23h(): void
    {
        Carbon::setTestNow('2026-09-01 23:00:00');

        $this->fakeProvedor();

        $mensagem = $this->confirmacaoPendente();

        EnviarConfirmacaoDeCampanhaJob::dispatchSync($mensagem->id, $mensagem->conversation->id);

        $this->assertSame(ConversationMessageStatus::Sent, $mensagem->fresh()->status);

        Carbon::setTestNow();
    }

    /**
     * A cota só é consumida quando o envio sai de verdade.
     *
     * Existe no projeto o padrão inverso — incrementar na criação e ainda poder
     * bloquear no envio — e ele infla contador com mensagem que nunca saiu.
     */
    public function test_envio_bloqueado_nao_consome_cota(): void
    {
        $this->fakeProvedor();

        $mensagem = $this->confirmacaoPendente();
        $mensagem->conversation->contact->forceFill(['do_not_contact' => true])->save();

        EnviarConfirmacaoDeCampanhaJob::dispatchSync($mensagem->id, 1);

        $this->assertSame(ConversationMessageStatus::Failed, $mensagem->fresh()->status);
        $this->assertSame(0, app(ConfirmationThrottle::class)->usadoNoMinuto());
    }

    public function test_confirmacao_recusada_pelo_teto_volta_para_a_fila(): void
    {
        $this->ajustar('keyword_campaigns.confirmation_max_per_minute', '1');
        $this->ajustar('keyword_campaigns.confirmation_min_interval_seconds', '0');

        $this->fakeProvedor();
        Queue::fake();

        // Consome a única vaga do minuto.
        app(ConfirmationThrottle::class)->reservar();

        $mensagem = $this->confirmacaoPendente();

        $job = new EnviarConfirmacaoDeCampanhaJob($mensagem->id, 1);
        $job->handle(
            app(WhatsAppProviderManager::class),
            app(ConfirmationThrottle::class),
            app(ConversationEventService::class),
            app(AuditLogger::class),
        );

        $this->assertSame(
            ConversationMessageStatus::Pending,
            $mensagem->fresh()->status,
            'Recusa do limitador é adiamento, não falha.',
        );
    }

    /**
     * O alarme de rajada não freia nada — o freio é o limitador.
     *
     * Ele existe para que alguém saiba que a divulgação pegou mais do que se
     * esperava enquanto ainda está acontecendo, e não no relatório do dia
     * seguinte.
     */
    public function test_teto_por_hora_marca_a_campanha_e_registra_o_alarme(): void
    {
        $this->fakeProvedor();

        $campanha = KeywordCampaign::factory()->create(['hourly_alert_threshold' => 3]);
        KeywordCampaignParticipation::factory()->count(3)->create([
            'keyword_campaign_id' => $campanha->id,
            'created_at' => now()->subMinutes(10),
        ]);

        $mensagem = $this->confirmacaoPendente();
        EnviarConfirmacaoDeCampanhaJob::dispatchSync($mensagem->id, $campanha->id);

        $this->assertNotNull($campanha->fresh()->hourly_alert_raised_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'keyword_campaign.hourly_threshold_reached']);
    }

    /**
     * Um alarme por hora basta. Mais que isso vira ruído que ninguém lê.
     */
    public function test_alarme_nao_se_repete_dentro_da_mesma_hora(): void
    {
        $this->fakeProvedor();

        $campanha = KeywordCampaign::factory()->create([
            'hourly_alert_threshold' => 1,
            'hourly_alert_raised_at' => now()->subMinutes(5),
        ]);
        KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);

        $mensagem = $this->confirmacaoPendente();
        EnviarConfirmacaoDeCampanhaJob::dispatchSync($mensagem->id, $campanha->id);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'keyword_campaign.hourly_threshold_reached']);
    }

    public function test_campanha_sem_teto_por_hora_nao_alarma(): void
    {
        $this->fakeProvedor();

        $campanha = KeywordCampaign::factory()->create(['hourly_alert_threshold' => null]);
        KeywordCampaignParticipation::factory()->count(50)->create(['keyword_campaign_id' => $campanha->id]);

        $mensagem = $this->confirmacaoPendente();
        EnviarConfirmacaoDeCampanhaJob::dispatchSync($mensagem->id, $campanha->id);

        $this->assertNull($campanha->fresh()->hourly_alert_raised_at);
    }

    private function confirmacaoPendente(): ConversationMessage
    {
        $contato = Contact::factory()->create([
            'phone_normalized' => '5549999990001',
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
        ]);

        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
        ]);

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'recipient_phone_snapshot' => '5549999990001',
            'body' => 'Inscrição confirmada!',
            'status' => ConversationMessageStatus::Pending,
            'received_at' => null,
        ]);
    }

    private function fakeProvedor(): void
    {
        $this->mock(WhatsAppProviderManager::class, function ($mock): void {
            $provedor = \Mockery::mock(WhatsAppProvider::class);
            $provedor->shouldReceive('sendMessage')->andReturn(
                new SendResult('pedido-1', 'sent', 'externo-1', CarbonImmutable::now()),
            );
            $mock->shouldReceive('provider')->andReturn($provedor);
        });
    }
}
