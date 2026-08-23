<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Jobs\EvaluateConversationFlowJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reagir ao convite inscreve na campanha.
 *
 * Isto afrouxa, de propósito, a regra escrita em
 * `docs/gatilhos-de-palavra-chave.md`: o casamento lê `body` justamente para
 * que áudio transcrito não inscreva ninguém, porque transcrição é a máquina
 * supondo o que foi dito e uma inscrição criada por engano é indistinguível de
 * uma de verdade.
 *
 * A reação é diferente do áudio em três pontos, e são eles que sustentam a
 * exceção: o ato é da própria pessoa, o alvo fica gravado, e o texto sobre o
 * qual ela reagiu é um texto que ela leu. Nada disso vale para uma transcrição.
 *
 * O que continua igual: reação numa mensagem que não fala da campanha não
 * inscreve, reação na própria mensagem não inscreve, e reação negativa não
 * inscreve.
 */
class ReacaoInscreveNaCampanhaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function convite(Conversation $conversa, string $texto = 'Escreva SORTEIO aqui para concorrer a uma bolsa de curso.'): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $conversa->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Manual,
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
            'sender_phone_snapshot' => str_replace('@c.us', '', (string) $conversa->external_chat_id),
            'sender_name_snapshot' => null,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => ConversationMessage::TYPE_REACTION,
            'body' => $emoji,
            'has_media' => false,
            'quoted_message_id' => $alvo?->external_message_id,
        ]);
    }

    public function test_reacao_positiva_no_convite_inscreve(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $this->convite($conversa))->id);

        $this->assertSame(1, KeywordCampaignParticipation::count());
        $this->assertSame('sorteio', KeywordCampaignParticipation::firstOrFail()->matched_keyword);
    }

    /**
     * A prova de origem é a própria reação: é a linha que diz quem reagiu, com
     * quê, e em que mensagem.
     */
    public function test_a_inscricao_aponta_para_a_reacao_como_prova_de_origem(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();
        $reacao = $this->reacao($conversa, '👍', $this->convite($conversa));

        EvaluateConversationFlowJob::dispatchSync($reacao->id);

        $this->assertSame($reacao->id, KeywordCampaignParticipation::firstOrFail()->conversation_message_id);
    }

    public function test_numero_desconhecido_vira_contato_com_o_consentimento_descrevendo_a_reacao(): void
    {
        KeywordCampaign::factory()->create(['name' => 'Sorteio de cursos']);
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $this->convite($conversa))->id);

        $contato = Contact::where('phone_normalized', '5549999990001')->firstOrFail();

        $this->assertSame(ContactSource::Gatilho, $contato->source);
        $this->assertSame(ConsentStatus::Granted, $contato->consent_status);
        $this->assertSame('reacao_na_campanha', $contato->consent_source);
        $this->assertStringContainsString('Reagiu com 👍', (string) $contato->consent_text);
        $this->assertStringContainsString('Sorteio de cursos', (string) $contato->consent_text);
        $this->assertStringContainsString('não o recebimento de disparo', (string) $contato->consent_text);
    }

    /**
     * Inscrição e pesquisa são dois consentimentos, não um.
     *
     * Quem reage no convite respondeu ao convite, e entra na campanha. O que a
     * reação negativa diz é sobre a outra coisa: que ela não quer responder à
     * pesquisa. Ela fica na lista do sorteio e não é convidada a nada.
     *
     * A prova de que a pesquisa não abriu está em `CampanhaAbrePesquisaTest`,
     * que roda o caminho inteiro até a mensagem sair.
     */
    public function test_reacao_negativa_no_convite_inscreve_do_mesmo_jeito(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👎', $this->convite($conversa))->id);

        $this->assertSame(1, KeywordCampaignParticipation::count());
        $this->assertStringContainsString(
            'Reagiu com 👎',
            (string) Contact::where('phone_normalized', '5549999990001')->firstOrFail()->consent_text,
            'O registro precisa dizer qual foi o ato, inclusive quando ele foi um não.',
        );
    }

    /**
     * Emoji fora das listas não é resposta a nada. Um 🍕 no convite é alguém
     * achando graça, não alguém se inscrevendo.
     */
    public function test_reacao_sem_significado_configurado_nao_inscreve(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '🍕', $this->convite($conversa))->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_reacao_em_mensagem_nossa_que_nao_fala_da_campanha_nao_inscreve(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        $outra = $this->convite($conversa, 'Bom dia! Obrigado pelo seu retorno de ontem.');

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $outra)->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_reacao_na_propria_mensagem_da_pessoa_nao_inscreve(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        $dela = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => 'quero o sorteio',
            'external_message_id' => 'entrada-1',
        ]);

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $dela)->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_reacao_sem_alvo_conhecido_nao_inscreve(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', null)->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_campanha_encerrada_nao_inscreve_por_reacao(): void
    {
        KeywordCampaign::factory()->encerrada()->create();
        $conversa = $this->conversa();

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $this->convite($conversa))->id);

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    /**
     * Reagir duas vezes é uma pessoa só. O índice único da tabela é quem
     * garante isso, e não uma conferência de aplicação.
     */
    public function test_segunda_reacao_da_mesma_pessoa_nao_duplica_inscricao(): void
    {
        KeywordCampaign::factory()->create();
        $conversa = $this->conversa();
        $convite = $this->convite($conversa);

        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '👍', $convite)->id);
        EvaluateConversationFlowJob::dispatchSync($this->reacao($conversa, '❤️', $convite)->id);

        $this->assertSame(1, KeywordCampaignParticipation::count());
    }
}
