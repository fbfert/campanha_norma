<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\SendResult;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Jobs\EvaluateConversationFlowJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A campanha por palavra-chave abrindo uma pesquisa.
 *
 * A pessoa manda a palavra, recebe numa mensagem só a confirmação e o pedido de
 * permissão, e a partir do "sim" quem conduz é o motor da 9A — que já existia e
 * não foi tocado. O que estes testes cobrem é a costura entre os dois.
 */
class CampanhaAbrePesquisaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);

        /*
         | O motor da 9A nasce desligado, e são duas chaves, não uma.
         |
         | `enabled` libera a avaliação; sem ela a conversa nunca sai de
         | `waiting_permission`. `auto_send_enabled` libera o envio; sem ela o
         | fluxo concede a permissão e a pergunta não sai — que é o estado mais
         | confuso possível, porque parece que funcionou.
         |
         | As duas precisam estar ligadas em produção para a campanha perguntar
         | alguma coisa. Está registrado em `docs/gatilhos-de-palavra-chave.md`.
         */
        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);

        KeywordCampaignTrigger::esquecerCache();
        $this->fakeProvedor();
    }

    private function fluxo(string $apresentacao = 'Posso te fazer uma pergunta rápida?'): ConversationFlow
    {
        $fluxo = ConversationFlow::factory()->create([
            'name' => 'Pesquisa da campanha',
            'status' => ConversationFlowStatus::Active,
            'presentation_text' => $apresentacao,
            'max_followups' => 0,
        ]);

        ConversationFlowQuestion::factory()->create([
            'conversation_flow_id' => $fluxo->id,
            'text' => 'O que mais falta na sua cidade hoje?',
            'is_active' => true,
        ]);

        return $fluxo;
    }

    private function mensagem(string $telefone = '5549999990001', string $texto = 'quero o sorteio'): ConversationMessage
    {
        $conversa = Conversation::firstOrCreate(
            ['provider' => 'web', 'external_chat_id' => "{$telefone}@c.us"],
            Conversation::factory()->make(['contact_id' => null])->only([
                'contact_id', 'connection_id', 'status', 'priority',
                'last_message_direction', 'last_message_at', 'last_incoming_message_at',
                'unread_count', 'is_archived',
            ]),
        );

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'sender_phone_snapshot' => $telefone,
            'sender_name_snapshot' => 'Maria da Silva',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $texto,
        ]);
    }

    /** Roda o gatilho e, na sequência, o envio da confirmação. */
    private function receber(string $telefone = '5549999990001', string $texto = 'quero o sorteio'): ConversationMessage
    {
        $mensagem = $this->mensagem($telefone, $texto);
        EvaluateConversationFlowJob::dispatchSync($mensagem->id);

        return $mensagem;
    }

    private function saidas(): array
    {
        return ConversationMessage::query()
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }

    // -------------------------------------------------------------------------

    /**
     * O caminho inteiro, do passo 1 ao 3.
     */
    public function test_confirmacao_e_convite_saem_na_mesma_mensagem(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => $this->fluxo()->id,
            'confirmation_text' => 'Inscrição confirmada! Boa sorte.',
        ]);

        $this->receber();

        $saidas = $this->saidas();

        $this->assertCount(1, $saidas, 'Confirmação e convite são uma fala só, não duas mensagens.');
        $this->assertStringContainsString('Inscrição confirmada!', $saidas[0]);
        $this->assertStringContainsString('Posso te fazer uma pergunta rápida?', $saidas[0]);
    }

    public function test_pesquisa_abre_esperando_permissao(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $mensagem = $this->receber();

        $estado = ConversationFlowState::where('conversation_id', $mensagem->conversation_id)->firstOrFail();

        $this->assertSame(ConversationFlowStage::WaitingPermission, $estado->current_stage);
    }

    /**
     * O detalhe que faz a diferença entre funcionar e inscrever sozinho.
     *
     * Sem a palavra-chave marcada como processada, o motor leria "quero o
     * sorteio" como resposta ao pedido de permissão — e dispararia a pergunta
     * sem ninguém ter dito sim.
     */
    public function test_a_palavra_chave_nao_e_lida_como_resposta_a_permissao(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $mensagem = $this->receber();
        $estado = ConversationFlowState::where('conversation_id', $mensagem->conversation_id)->firstOrFail();

        $this->assertSame($mensagem->id, $estado->last_processed_message_id);
        $this->assertSame(ConversationFlowStage::WaitingPermission, $estado->current_stage);
        $this->assertCount(1, $this->saidas(), 'A pergunta não pode sair antes do sim.');
    }

    /**
     * O passo 3: a pessoa diz sim e a pergunta sai.
     */
    public function test_resposta_positiva_dispara_a_pergunta_configurada(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $inscricao = $this->receber();

        $sim = ConversationMessage::factory()->create([
            'conversation_id' => $inscricao->conversation_id,
            'contact_id' => $inscricao->contact_id,
            'sender_phone_snapshot' => '5549999990001',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'pode sim',
        ]);

        EvaluateConversationFlowJob::dispatchSync($sim->id);

        $estado = ConversationFlowState::where('conversation_id', $inscricao->conversation_id)->firstOrFail();

        $this->assertNotSame(
            ConversationFlowStage::WaitingPermission,
            $estado->current_stage,
            'Depois do sim o fluxo precisa sair da espera por permissão.',
        );

        $this->assertTrue(
            collect($this->saidas())->contains(fn (string $t): bool => str_contains($t, 'O que mais falta na sua cidade hoje?')),
            'A pergunta do fluxo precisa ter saído.',
        );
    }

    /**
     * O passo 4 é do motor da 9A: a resposta fica registrada e a conversa
     * segue. A interpretação por IA está desligada nos testes, então o que se
     * verifica aqui é que a resposta chegou ao estágio certo.
     */
    public function test_resposta_a_pergunta_avanca_o_fluxo(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $inscricao = $this->receber();

        foreach (['pode sim', 'falta médico especialista aqui'] as $texto) {
            $m = ConversationMessage::factory()->create([
                'conversation_id' => $inscricao->conversation_id,
                'contact_id' => $inscricao->contact_id,
                'sender_phone_snapshot' => '5549999990001',
                'direction' => ConversationMessageDirection::Incoming,
                'message_type' => 'text',
                'body' => $texto,
            ]);
            EvaluateConversationFlowJob::dispatchSync($m->id);
        }

        $estado = ConversationFlowState::where('conversation_id', $inscricao->conversation_id)->firstOrFail();

        $this->assertNotSame(ConversationFlowStage::WaitingPermission, $estado->current_stage);
        $this->assertSame(1, KeywordCampaignParticipation::count(), 'A inscrição não pode ser afetada pela pesquisa.');
    }

    /**
     * Campanha sem fluxo é o padrão: só sorteia.
     */
    public function test_campanha_sem_fluxo_nao_abre_pesquisa(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => null,
            'confirmation_text' => 'Inscrição confirmada!',
        ]);

        $mensagem = $this->receber();

        $this->assertSame(['Inscrição confirmada!'], $this->saidas());
        $this->assertSame(0, ConversationFlowState::where('conversation_id', $mensagem->conversation_id)->count());
    }

    /**
     * Pesquisa encerrada não bloqueia: ela é reaberta.
     *
     * O caso que apareceu em produção, na conversa 425. Tratar "tem estado" como
     * "está no meio de uma pesquisa" fechava a porta para quase toda a base — e
     * em silêncio, porque a pessoa recebia só a confirmação e ninguém notava.
     */
    public function test_pesquisa_concluida_e_reaberta(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => $this->fluxo()->id,
            'confirmation_text' => 'Inscrição confirmada!',
        ]);

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);

        $antigo = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'current_stage' => ConversationFlowStage::Completed,
            'completed_at' => now()->subWeek(),
            'expires_at' => now()->subWeek(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'sender_phone_snapshot' => '5549999990001',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'quero o sorteio',
        ]);

        EvaluateConversationFlowJob::dispatchSync($mensagem->id);

        $depois = $antigo->fresh();

        $this->assertSame(ConversationFlowStage::WaitingPermission, $depois->current_stage);
        $this->assertSame($mensagem->id, $depois->last_processed_message_id);
        $this->assertStringContainsString('Posso te fazer uma pergunta', implode(' ', $this->saidas()));

        // A chave única obriga a reusar a linha: continua sendo uma só.
        $this->assertSame(1, ConversationFlowState::where('conversation_id', $conversa->id)->count());
        $this->assertSame($antigo->id, $depois->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'keyword_campaign.survey_reopened']);
    }

    /**
     * Pesquisa abandonada — não terminal, mas com o prazo vencido — também é
     * reaberta. Eram 67 das 69 conversas com estado na base.
     */
    public function test_pesquisa_com_prazo_vencido_e_reaberta(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);

        ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'current_stage' => ConversationFlowStage::WaitingPermission,
            'expires_at' => now()->subDays(3),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'sender_phone_snapshot' => '5549999990001',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'quero o sorteio',
        ]);

        EvaluateConversationFlowJob::dispatchSync($mensagem->id);

        $estado = ConversationFlowState::where('conversation_id', $conversa->id)->firstOrFail();

        $this->assertSame(ConversationFlowStage::WaitingPermission, $estado->current_stage);
        $this->assertSame($mensagem->id, $estado->last_processed_message_id);
        $this->assertStringContainsString('Posso te fazer uma pergunta', implode(' ', $this->saidas()));
    }

    /**
     * Quem está no meio de uma pesquisa VIVA se inscreve e não é convidado:
     * duas perguntas na mesma conversa competiriam entre si.
     */
    public function test_quem_ja_esta_em_pesquisa_se_inscreve_sem_receber_convite(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => $this->fluxo()->id,
            'confirmation_text' => 'Inscrição confirmada!',
        ]);

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);

        $estadoAnterior = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            // No prazo: é o que a torna viva, e viva é o que barra o convite.
            'expires_at' => now()->addDay(),
        ]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'sender_phone_snapshot' => '5549999990001',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'quero o sorteio',
        ]);

        EvaluateConversationFlowJob::dispatchSync($mensagem->id);

        $this->assertSame(1, KeywordCampaignParticipation::count(), 'A inscrição continua valendo.');
        $this->assertSame(
            ['Inscrição confirmada!'],
            collect($this->saidas())->filter(fn (string $t): bool => str_contains($t, 'Inscrição confirmada!'))->values()->all(),
        );
        $this->assertStringNotContainsString(
            'Posso te fazer uma pergunta',
            implode(' ', $this->saidas()),
            'Não se convida para uma segunda pesquisa quem já está numa.',
        );
        $this->assertSame(1, ConversationFlowState::where('conversation_id', $conversa->id)->count());
        $this->assertSame($estadoAnterior->conversation_flow_id, $estadoAnterior->fresh()->conversation_flow_id);
    }

    /**
     * Quem já está inscrito e manda de novo recebe o texto de já inscrito, sem
     * um segundo convite.
     */
    public function test_ja_inscrito_nao_recebe_convite_de_novo(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => $this->fluxo()->id,
            'already_enrolled_text' => 'Você já está na lista.',
        ]);

        $this->receber();
        $saidasAntes = count($this->saidas());

        $this->receber();

        $novas = array_slice($this->saidas(), $saidasAntes);

        $this->assertNotEmpty($novas);
        $this->assertStringContainsString('Você já está na lista.', implode(' ', $novas));
        $this->assertStringNotContainsString('Posso te fazer uma pergunta', implode(' ', $novas));
    }

    /**
     * O convite pode ter texto próprio: a frase de um sorteio não é a mesma de
     * uma abertura fria.
     */
    public function test_texto_proprio_do_convite_vence_o_do_fluxo(): void
    {
        KeywordCampaign::factory()->create([
            'conversation_flow_id' => $this->fluxo()->id,
            'confirmation_text' => 'Inscrição confirmada!',
            'survey_invite_text' => 'Além disso, posso te fazer uma pergunta sobre a sua cidade?',
        ]);

        $this->receber();

        $this->assertStringContainsString('Além disso, posso te fazer uma pergunta', $this->saidas()[0]);
        $this->assertStringNotContainsString('Posso te fazer uma pergunta rápida?', $this->saidas()[0]);
    }

    /**
     * A pesquisa abre depois de a confirmação sair, não antes.
     *
     * Numa rajada a confirmação pode ficar minutos na fila. Abrindo o fluxo ao
     * enfileirar, qualquer coisa escrita nesse intervalo seria lida como
     * resposta a um pedido que a pessoa ainda não recebeu.
     */
    public function test_pesquisa_nao_abre_enquanto_a_confirmacao_nao_sai(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        // O provedor recusa: a confirmação falha e nada de pesquisa.
        $this->mock(WhatsAppProviderManager::class, function ($mock): void {
            $provedor = Mockery::mock(WhatsAppProvider::class);
            $provedor->shouldReceive('sendMessage')->andThrow(new \RuntimeException('sessão caída'));
            $mock->shouldReceive('provider')->andReturn($provedor);
        });

        $mensagem = $this->receber();

        $this->assertSame(1, KeywordCampaignParticipation::count(), 'A inscrição não depende do envio.');
        $this->assertSame(
            0,
            ConversationFlowState::where('conversation_id', $mensagem->conversation_id)->count(),
            'Sem confirmação entregue, não se coloca ninguém esperando permissão.',
        );
        $this->assertSame(
            ConversationMessageStatus::Failed,
            ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->firstOrFail()->status,
        );
    }

    /**
     * Número desconhecido: o contato nasce da palavra-chave e a pesquisa abre
     * sobre ele. É o caminho principal de uma divulgação.
     */
    public function test_numero_desconhecido_vira_contato_e_entra_na_pesquisa(): void
    {
        KeywordCampaign::factory()->create(['conversation_flow_id' => $this->fluxo()->id]);

        $this->assertSame(0, Contact::count());

        $mensagem = $this->receber();

        $this->assertSame(1, Contact::count());
        $this->assertSame(1, ConversationFlowState::where('conversation_id', $mensagem->conversation_id)->count());
    }

    private function fakeProvedor(): void
    {
        $this->mock(WhatsAppProviderManager::class, function ($mock): void {
            $provedor = Mockery::mock(WhatsAppProvider::class);
            // Identificador único por envio: `conversation_messages` tem chave
            // única em (provedor, identificador externo), e uma pesquisa manda
            // mais de uma mensagem na mesma conversa.
            $enviadas = 0;
            $provedor->shouldReceive('sendMessage')->andReturnUsing(function () use (&$enviadas): SendResult {
                $enviadas++;

                return new SendResult('pedido-'.$enviadas, 'sent', 'externo-'.$enviadas, CarbonImmutable::now());
            });
            $mock->shouldReceive('provider')->andReturn($provedor);
        });
    }
}
