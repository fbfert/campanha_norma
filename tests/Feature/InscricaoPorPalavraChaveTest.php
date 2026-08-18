<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\KeywordEnrollmentOutcome;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\KeywordCampaigns\ParticipationRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O registro da inscrição, do casamento até a linha no banco.
 *
 * O caminho principal desta etapa é o número desconhecido: uma captação por
 * palavra-chave é feita quase inteiramente de gente que ainda não está na base.
 */
class InscricaoPorPalavraChaveTest extends TestCase
{
    use RefreshDatabase;

    private function registrar(): ParticipationRegistrar
    {
        return app(ParticipationRegistrar::class);
    }

    /**
     * A segunda mensagem da mesma pessoa cai na mesma conversa, como na vida
     * real: `conversations` tem chave única em provedor mais chat externo.
     */
    private function mensagem(string $telefone = '5549999990001', ?string $nome = 'Maria da Silva', ?Contact $contato = null): ConversationMessage
    {
        $conversa = Conversation::firstOrCreate(
            ['provider' => 'web', 'external_chat_id' => "{$telefone}@c.us"],
            Conversation::factory()->make(['contact_id' => $contato?->id])->only([
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
            'contact_id' => $contato?->id,
            'sender_phone_snapshot' => $telefone,
            'sender_name_snapshot' => $nome,
            'body' => 'quero o sorteio',
        ]);
    }

    public function test_numero_desconhecido_vira_contato_com_origem_consentimento_e_etiqueta(): void
    {
        $campanha = KeywordCampaign::factory()->create(['name' => 'Sorteio de cursos']);

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::Registrada, $resultado->outcome);

        $contato = Contact::where('phone_normalized', '5549999990001')->firstOrFail();

        $this->assertSame(ContactSource::Gatilho, $contato->source);
        $this->assertSame(ConsentStatus::Granted, $contato->consent_status);
        $this->assertSame(ContactStatus::Active, $contato->status);
        $this->assertSame('Maria da Silva', $contato->name);

        // A finalidade fica escrita, e é ela que distingue este consentimento
        // do consentimento para disparo.
        $this->assertStringContainsString('Sorteio de cursos', (string) $contato->consent_text);
        $this->assertStringContainsString('não o recebimento de disparo', (string) $contato->consent_text);

        $this->assertTrue($contato->tags()->where('name', 'Campanha: Sorteio de cursos')->exists());
    }

    /**
     * O cadastro é mais confiável que um apelido escolhido pela pessoa, que ela
     * troca quando quiser.
     */
    public function test_contato_conhecido_e_reaproveitado_sem_perder_o_nome_cadastrado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $contato = Contact::factory()->create([
            'name' => 'Mariana Souza',
            'phone_normalized' => '5549999990001',
            'source' => ContactSource::Importacao,
        ]);

        $this->registrar()->registrar($campanha, $this->mensagem(nome: 'mari ✨'), 'sorteio');

        $contato->refresh();

        $this->assertSame('Mariana Souza', $contato->name);
        $this->assertSame(ContactSource::Importacao, $contato->source);
        $this->assertSame(1, Contact::count());

        // O nome de perfil não vai para o cadastro, mas vai para a
        // participação: é o que a tela mostra ao lado da inscrição.
        $this->assertSame('mari ✨', KeywordCampaignParticipation::firstOrFail()->captured_name);
    }

    public function test_toda_participacao_guarda_a_mensagem_de_origem(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $mensagem = $this->mensagem();

        $this->registrar()->registrar($campanha, $mensagem, 'sorteio');

        $this->assertSame($mensagem->id, KeywordCampaignParticipation::firstOrFail()->conversation_message_id);
    }

    /**
     * Sem nome de perfil, a inscrição continua valendo. Bloquear aqui
     * transformaria um campo de cadastro vazio em exclusão de participante.
     */
    public function test_participacao_sem_nome_e_valida(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(nome: null), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::Registrada, $resultado->outcome);
        $this->assertSame(KeywordParticipationStatus::SemNome, $resultado->participation->status);
        $this->assertTrue($resultado->participation->status->contaComoValida());
    }

    public function test_nome_so_com_espaco_conta_como_ausencia_de_nome(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(nome: '   '), 'sorteio');

        $this->assertSame(KeywordParticipationStatus::SemNome, $resultado->participation->status);
        $this->assertNull($resultado->participation->captured_name);
    }

    /**
     * Escolher um dos dois contatos no automático inscreveria uma pessoa e
     * deixaria outra de fora sem que ninguém soubesse.
     */
    public function test_telefone_ambiguo_entra_em_revisao_e_nao_conta_como_valido(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        Contact::factory()->count(2)->create(['phone_normalized' => '5549999990001']);

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::EmRevisao, $resultado->outcome);
        $this->assertSame(KeywordParticipationStatus::EmRevisao, $resultado->participation->status);
        $this->assertFalse($resultado->participation->status->contaComoValida());
        $this->assertSame(0, $campanha->fresh()->validParticipations()->count());
    }

    public function test_telefone_invalido_nao_cria_contato_nem_participacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(telefone: '123'), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::TelefoneInvalido, $resultado->outcome);
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_segunda_mensagem_da_mesma_pessoa_nao_duplica(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');
        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::JaInscrita, $resultado->outcome);
        $this->assertNotNull($resultado->participation);
        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    /**
     * A corrida de verdade: as duas chamadas montam a participação sem que
     * nenhuma tenha visto a outra. É o que dois workers fazem ao processar
     * mensagens que chegaram no mesmo segundo.
     *
     * O contato já existe aqui de propósito, para isolar a corrida na chave
     * única da participação.
     */
    public function test_duas_gravacoes_simultaneas_nao_duplicam(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        Contact::factory()->create(['phone_normalized' => '5549999990001']);

        $primeira = $this->mensagem();
        $segunda = $this->mensagem();

        $this->registrar()->registrar($campanha, $primeira, 'sorteio');
        $resultado = $this->registrar()->registrar($campanha, $segunda, 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::JaInscrita, $resultado->outcome);
        $this->assertSame(1, KeywordCampaignParticipation::count());

        // A participação que sobrou aponta para a primeira mensagem: quem
        // chegou antes é quem se inscreveu.
        $this->assertSame($primeira->id, KeywordCampaignParticipation::firstOrFail()->conversation_message_id);
    }

    public function test_fora_da_vigencia_nao_registra(): void
    {
        $campanha = KeywordCampaign::factory()->encerrada()->create();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::ForaDeVigencia, $resultado->outcome);
        $this->assertSame(0, KeywordCampaignParticipation::count());
        $this->assertSame(0, Contact::count());
    }

    public function test_lista_congelada_nao_registra(): void
    {
        $campanha = KeywordCampaign::factory()->create(['frozen_at' => now()]);

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::ListaCongelada, $resultado->outcome);
        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    /**
     * O limite é conferido antes de criar contato.
     *
     * Criar e só depois descobrir que não havia vaga deixaria na base uma
     * pessoa com consentimento gravado para uma campanha em que ela não está
     * inscrita.
     */
    public function test_limite_atingido_nao_registra_nem_cria_contato(): void
    {
        $campanha = KeywordCampaign::factory()->create(['participant_limit' => 1]);
        KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);

        $contatosAntes = Contact::count();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(KeywordEnrollmentOutcome::LimiteAtingido, $resultado->outcome);
        $this->assertSame($contatosAntes, Contact::count());
    }

    public function test_inscricao_nasce_com_elegibilidade_nao_verificada(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $this->assertSame(
            KeywordParticipationEligibility::NaoVerificada,
            $resultado->participation->eligibility,
        );
    }

    public function test_a_palavra_que_casou_fica_gravada(): void
    {
        $campanha = KeywordCampaign::factory()->create(['keywords' => ['sorteio', 'curso']]);

        $resultado = $this->registrar()->registrar($campanha, $this->mensagem(), 'curso');

        $this->assertSame('curso', $resultado->participation->matched_keyword);
    }

    public function test_inscricao_deixa_rastro_no_historico_e_na_auditoria(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->registrar()->registrar($campanha, $this->mensagem(), 'sorteio');

        $contato = Contact::firstOrFail();

        $this->assertTrue($contato->history()->where('action', 'created')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'keyword_campaign.participation_created']);
    }

    /**
     * A mesma pessoa em duas campanhas vigentes é o caso normal.
     */
    public function test_a_mesma_pessoa_se_inscreve_em_duas_campanhas(): void
    {
        $primeira = KeywordCampaign::factory()->create(['name' => 'Sorteio de agosto']);
        $segunda = KeywordCampaign::factory()->create(['name' => 'Sorteio de setembro']);

        $this->assertTrue($this->registrar()->registrar($primeira, $this->mensagem(), 'sorteio')->registrou());
        $this->assertTrue($this->registrar()->registrar($segunda, $this->mensagem(), 'sorteio')->registrou());

        $this->assertSame(1, Contact::count());
        $this->assertSame(2, KeywordCampaignParticipation::count());
    }
}
