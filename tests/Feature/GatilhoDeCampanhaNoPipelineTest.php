<?php

namespace Tests\Feature;

use App\Enums\ContactSource;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use App\Enums\TranscriptionStatus;
use App\Jobs\EvaluateConversationFlowJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\InboundAttendanceProfile;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\MessageTranscription;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O gatilho de campanha dentro do pipeline de entrada.
 *
 * Ele roda em `EvaluateConversationFlowJob`, antes do roteamento e sob a trava
 * por conversa que o job já segura. O que estes testes protegem é sobretudo o
 * que ele NÃO pode fazer: mexer num fluxo em andamento, e responder junto com o
 * atendimento de entrada.
 */
class GatilhoDeCampanhaNoPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | A automação precisa estar LIGADA aqui.
         |
         | Sem estas chaves o motor da 9A é barrado pelo guard e o fluxo fica
         | intacto por acidente — foi assim que o teste crítico abaixo passou
         | por muito tempo sem cobrir nada. Em 17/08/2026, com a automação
         | ligada em produção, a palavra-chave foi gravada como resposta de
         | pesquisa.
         */
        $this->seed(SystemSettingSeeder::class);
        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);

        KeywordCampaignTrigger::esquecerCache();
    }

    private function conversa(string $telefone = '5549999990001', ?Contact $contato = null): Conversation
    {
        return Conversation::factory()->create([
            'contact_id' => $contato?->id,
            'external_chat_id' => "{$telefone}@c.us",
            'provider' => 'web',
        ]);
    }

    private function mensagem(Conversation $conversa, string $texto = 'quero o sorteio', array $extra = []): ConversationMessage
    {
        return ConversationMessage::factory()->create(array_merge([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'sender_phone_snapshot' => str_replace('@c.us', '', (string) $conversa->external_chat_id),
            'sender_name_snapshot' => 'Maria da Silva',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $texto,
        ], $extra));
    }

    private function avaliar(ConversationMessage $mensagem): void
    {
        EvaluateConversationFlowJob::dispatchSync($mensagem->id);
    }

    public function test_palavra_chave_com_campanha_vigente_registra_participacao(): void
    {
        KeywordCampaign::factory()->create();

        $this->avaliar($this->mensagem($this->conversa()));

        $this->assertSame(1, KeywordCampaignParticipation::count());
        $this->assertSame('sorteio', KeywordCampaignParticipation::firstOrFail()->matched_keyword);
    }

    /**
     * O caminho quente. Enquanto não houver campanha, o job precisa se comportar
     * exatamente como antes desta etapa existir.
     */
    public function test_sem_campanha_vigente_nao_faz_nada(): void
    {
        $this->avaliar($this->mensagem($this->conversa()));

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_campanha_encerrada_nao_registra(): void
    {
        KeywordCampaign::factory()->encerrada()->create();

        $this->avaliar($this->mensagem($this->conversa()));

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_mensagem_sem_a_palavra_nao_registra(): void
    {
        KeywordCampaign::factory()->create();

        $this->avaliar($this->mensagem($this->conversa(), 'bom dia, tudo bem?'));

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_eco_de_mensagem_enviada_nao_registra(): void
    {
        KeywordCampaign::factory()->create();

        $this->avaliar($this->mensagem($this->conversa(), extra: [
            'direction' => ConversationMessageDirection::Outgoing,
        ]));

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    /**
     * Áudio não inscreve ninguém, mesmo transcrito.
     *
     * Este é o caminho de verdade do risco: `TranscribeIncomingAudioJob`
     * redispara este job depois de transcrever. Como o casamento lê `body`, e a
     * transcrição mora em outra tabela, a inscrição por engano de transcrição
     * não acontece.
     */
    public function test_audio_transcrito_com_a_palavra_nao_registra(): void
    {
        KeywordCampaign::factory()->create();

        $mensagem = $this->mensagem($this->conversa(), extra: [
            'message_type' => 'ptt',
            'body' => null,
            'has_media' => true,
        ]);

        MessageTranscription::create([
            'conversation_id' => $mensagem->conversation_id,
            'conversation_message_id' => $mensagem->id,
            'status' => TranscriptionStatus::Succeeded,
            'media_type' => 'ptt',
            'text' => 'quero o sorteio',
        ]);

        $this->assertSame('quero o sorteio', $mensagem->fresh()->readableText());

        $this->avaliar($mensagem);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    /**
     * O TESTE CRÍTICO desta fase.
     *
     * Quem está no meio de uma pesquisa e manda SÓ a palavra-chave precisa se
     * inscrever sem que o fluxo perceba. Nada de transicionar estágio, nada de
     * mover a marca da última mensagem processada — se mover, a pesquisa pula
     * uma resposta e ninguém descobre por quê — e nada de gravar a palavra como
     * se fosse a opinião da pessoa.
     *
     * Palavra dentro de uma frase é outro caso, e tem teste próprio logo
     * abaixo: ali a frase é a resposta e precisa chegar ao motor.
     */
    public function test_pesquisa_em_andamento_registra_inscricao_e_nao_e_perturbada(): void
    {
        KeywordCampaign::factory()->create();

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = $this->conversa(contato: $contato);

        $estado = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'last_processed_message_id' => null,
            'automated_messages_count' => 3,
        ]);

        $antes = $estado->only([
            'current_stage',
            'last_processed_message_id',
            'selected_question_id',
            'automated_messages_count',
            'attempts_count',
            'end_reason',
        ]);

        // Só a palavra, como quem responde a um cartaz.
        $this->avaliar($this->mensagem($conversa, 'sorteio'));

        $this->assertSame(1, KeywordCampaignParticipation::count());

        $depois = $estado->fresh();

        $this->assertSame(
            ConversationFlowStage::WaitingAnswer,
            $depois->current_stage,
            'O gatilho não pode transicionar o estágio do fluxo.',
        );
        $this->assertSame(
            $antes['last_processed_message_id'],
            $depois->last_processed_message_id,
            'O gatilho não pode mover a marca da última mensagem processada.',
        );
        $this->assertSame($antes['automated_messages_count'], $depois->automated_messages_count);
        $this->assertNull($depois->end_reason);

        /*
         | E a palavra-chave não pode ter virado resposta.
         |
         | Esta é a asserção que faltava. Sem ela o teste passava com a
         | automação desligada, e em produção "batata" foi gravada como opinião
         | sobre o problema mais urgente da cidade — com a pesquisa avançando
         | para a pergunta seguinte carregando esse dado.
         */
        $this->assertSame(
            0,
            $estado->transitions()->where('to_stage', ConversationFlowStage::AnswerReceived->value)->count(),
            'A palavra-chave não pode ser registrada como resposta da pesquisa.',
        );
    }

    /**
     * Palavra dentro de uma frase é a frase que importa.
     *
     * O contraponto do teste acima: quem está respondendo a pesquisa e escreve
     * uma frase que por acaso contém a palavra-chave está respondendo, não se
     * inscrevendo. Engolir essa mensagem perderia a resposta em silêncio.
     */
    public function test_palavra_dentro_de_frase_continua_sendo_resposta_da_pesquisa(): void
    {
        KeywordCampaign::factory()->create(['keywords' => ['saude']]);

        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);
        $conversa = $this->conversa(contato: $contato);

        $estado = ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'current_stage' => ConversationFlowStage::WaitingAnswer,
            'expires_at' => now()->addDay(),
        ]);

        $this->avaliar($this->mensagem($conversa, 'falta saúde no meu bairro'));

        $this->assertSame(1, KeywordCampaignParticipation::count(), 'A inscrição acontece do mesmo jeito.');
        $this->assertGreaterThan(
            0,
            $estado->transitions()->where('to_stage', ConversationFlowStage::AnswerReceived->value)->count(),
            'A frase é a resposta da pessoa e precisa chegar ao motor.',
        );
    }

    /**
     * Duas divulgações no ar ao mesmo tempo, com a mesma palavra: a pessoa entra
     * nas duas. Não há razão para uma campanha consumir a mensagem da outra.
     */
    public function test_duas_campanhas_vigentes_com_a_mesma_palavra_registram_nas_duas(): void
    {
        KeywordCampaign::factory()->create(['name' => 'Sorteio de agosto']);
        KeywordCampaign::factory()->create(['name' => 'Sorteio de setembro']);

        $this->avaliar($this->mensagem($this->conversa()));

        $this->assertSame(2, KeywordCampaignParticipation::count());
        $this->assertSame(1, Contact::where('source', ContactSource::Gatilho)->count());
    }

    /**
     * A campanha responde, e o atendimento de entrada não abre.
     *
     * Duas mensagens no mesmo minuto para a mesma pessoa é o dobro do volume no
     * pico da divulgação, e é o comportamento que mais rápido leva um número do
     * WhatsApp Web a bloqueio.
     */
    public function test_campanha_que_atende_suprime_a_abertura_do_atendimento_de_entrada(): void
    {
        KeywordCampaign::factory()->create();
        $this->perfilDeAtendimentoAtivo();

        $conversa = $this->conversa();
        $this->avaliar($this->mensagem($conversa));

        $this->assertSame(1, KeywordCampaignParticipation::count());
        $this->assertSame(
            0,
            DB::table('inbound_attendance_attempts')->where('conversation_id', $conversa->id)->count(),
            'O atendimento de entrada não pode abrir para a mensagem que a campanha atendeu.',
        );
    }

    /**
     * A supressão é da mensagem, não da pessoa: sem campanha casando, o
     * atendimento de entrada continua fazendo o que sempre fez.
     */
    public function test_mensagem_sem_palavra_chave_continua_indo_para_o_atendimento_de_entrada(): void
    {
        KeywordCampaign::factory()->create();
        $this->perfilDeAtendimentoAtivo();

        $conversa = $this->conversa();
        $this->avaliar($this->mensagem($conversa, 'bom dia, preciso de ajuda'));

        $this->assertSame(0, KeywordCampaignParticipation::count());
        $this->assertGreaterThan(
            0,
            DB::table('inbound_attendance_attempts')->where('conversation_id', $conversa->id)->count(),
        );
    }

    public function test_registro_deixa_evento_na_conversa(): void
    {
        KeywordCampaign::factory()->create(['name' => 'Sorteio de cursos']);

        $conversa = $this->conversa();
        $this->avaliar($this->mensagem($conversa));

        $evento = ConversationEvent::query()
            ->where('conversation_id', $conversa->id)
            ->where('event_type', 'keyword_campaign_evaluated')
            ->firstOrFail();

        $this->assertStringContainsString('Sorteio de cursos', (string) $evento->description);
    }

    /**
     * Ligar a campanha na tela precisa ligar a campanha de verdade. Sem a
     * limpeza do cache, o primeiro relato seria "mandei a palavra e não
     * aconteceu nada" — impossível de reproduzir depois que o cache expira.
     */
    public function test_gravar_campanha_derruba_o_cache_de_vigentes(): void
    {
        $this->avaliar($this->mensagem($this->conversa('5549999990001')));
        $this->assertSame(0, KeywordCampaignParticipation::count());

        KeywordCampaign::factory()->create();

        $this->avaliar($this->mensagem($this->conversa('5549999990002')));
        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    /**
     * Campanha desativada na tela para de pegar na hora, mesmo com o cache
     * ainda apontando para ela: a vigência é reconferida no banco.
     */
    public function test_campanha_encerrada_depois_do_cache_para_de_pegar(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->avaliar($this->mensagem($this->conversa('5549999990001')));
        $this->assertSame(1, KeywordCampaignParticipation::count());

        // Encerra sem passar pelo model, para que o cache continue apontando
        // para a campanha — é o cenário da borda de relógio.
        DB::table('keyword_campaigns')->where('id', $campanha->id)->update(['status' => 'encerrada']);

        $this->avaliar($this->mensagem($this->conversa('5549999990002')));
        $this->assertSame(1, KeywordCampaignParticipation::count());
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
            // Sem homologação: o que este teste mede é se o atendimento abriu,
            // não se ele esperou por um clique.
            'homologation_threshold' => 0,
        ]);
    }
}
