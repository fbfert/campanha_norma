<?php

namespace Tests\Unit;

use App\Enums\InsightUrgency;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use App\Services\Analytics\ResponseAgendaService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A ordenação da fila e a montagem do dossiê.
 *
 * O que se protege aqui é que a pontuação **ordene sem descartar** e que o
 * dossiê saia inteiro mesmo quando falta a parte que deveria vir do tema.
 */
class PautaDeRespostaTest extends TestCase
{
    use RefreshDatabase;

    private ResponseAgendaService $pauta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $this->seed(SystemSettingSeeder::class);

        $this->pauta = app(ResponseAgendaService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Urgência pesa mais que tamanho, e é isso que os pesos padrão dizem:
     * três contra um.
     */
    public function test_a_fila_vem_ordenada_por_prioridade(): void
    {
        $curtaEUrgente = $this->insight('Urgente', 'Resposta curta.', InsightUrgency::High);
        $longaESemPressa = $this->insight('Longa', str_repeat('detalhe ', 60), InsightUrgency::Low);

        $fila = $this->pauta->queue($this->de(), $this->ate());

        $this->assertSame($curtaEUrgente->id, $fila[0]['insight_id']);
        $this->assertSame($longaESemPressa->id, $fila[1]['insight_id']);
        $this->assertGreaterThan($fila[1]['priority'], $fila[0]['priority']);
    }

    /**
     * Pontuação ordena, nunca descarta. Toda pessoa da fila é para responder, e
     * sumir com a de prioridade mais baixa seria decidir por ela.
     */
    public function test_prioridade_baixa_continua_na_fila(): void
    {
        $semNada = $this->insight('Semnada', 'Oi.', InsightUrgency::Low);

        $fila = $this->pauta->queue($this->de(), $this->ate());

        $this->assertCount(1, $fila);
        $this->assertSame($semNada->id, $fila[0]['insight_id']);
        $this->assertSame(0, $fila[0]['priority']);
    }

    /**
     * Sem tema atribuído não há orientação nem linha vermelha, e o dossiê sai
     * assim mesmo: a frase da pessoa e o que ela levantou não dependem do tema.
     */
    public function test_o_dossie_sai_sem_tema_atribuido(): void
    {
        $insight = $this->insight('Sem tema', 'A ponte do meu bairro está interditada há dois meses.');
        $insight->update(['insight_topic_id' => null]);

        $dossie = $this->pauta->dossier($insight->fresh());

        $this->assertSame('A ponte do meu bairro está interditada há dois meses.', $dossie['sentence']);
        $this->assertNull($dossie['topic']);
        $this->assertNull($dossie['response_guidance']);
        $this->assertNull($dossie['red_lines']);
        $this->assertNull($dossie['official_excerpt']);
        $this->assertSame('Interdição da ponte', $dossie['identified_problem']);
    }

    /**
     * A orientação e a linha vermelha vêm do tema, e chegam ao dossiê sem
     * passar por modelo nenhum: são o texto que uma pessoa escreveu.
     */
    public function test_o_dossie_traz_a_orientacao_e_a_linha_vermelha_do_tema(): void
    {
        $tema = InsightTopic::factory()->create([
            'name' => 'Saúde',
            'response_guidance' => 'A campanha defende ampliar o horário do posto.',
            'red_lines' => 'Não prometer médico novo: a contratação não depende da campanha.',
        ]);

        $insight = $this->insight('Marta', 'O posto fecha cedo demais.');
        $insight->update(['insight_topic_id' => $tema->id]);

        $dossie = $this->pauta->dossier($insight->fresh());

        $this->assertSame('A campanha defende ampliar o horário do posto.', $dossie['response_guidance']);
        $this->assertStringContainsString('Não prometer médico novo', $dossie['red_lines']);
    }

    /**
     * A frase vai literal. É o bloco do dossiê que nenhum resumo melhora.
     */
    public function test_a_frase_do_dossie_e_literal(): void
    {
        $frase = 'A creche mais próxima fica a quatro quilômetros e não tem linha de ônibus.';
        $insight = $this->insight('Jonas', $frase);

        $this->assertSame($frase, $this->pauta->dossier($insight)['sentence']);
    }

    public function test_avisa_confianca_baixa_no_dossie(): void
    {
        $insight = $this->insight('Rita', 'Falta remédio.');
        $insight->update(['confidence' => 0.30]);

        $this->assertTrue($this->pauta->dossier($insight->fresh())['low_confidence']);
    }

    /**
     * A marcação manual é a afirmação de uma pessoa, e a fila diz de onde ela
     * veio: origem diferente é confiança diferente.
     */
    public function test_a_marcacao_manual_aparece_com_a_origem(): void
    {
        $insight = $this->insight('Marta', 'Qualquer coisa.');
        $insight->update(['answered_at' => now()]);

        $fila = $this->pauta->queue($this->de(), $this->ate());

        $this->assertTrue($fila[0]['answered']);
        $this->assertSame('manual', $fila[0]['answered_source']);
    }

    public function test_filtra_por_estado_pendente_ou_respondida(): void
    {
        $pendente = $this->insight('Pendente', 'Ainda não respondida.');
        $respondida = $this->insight('Respondida', 'Já respondida.');
        $respondida->update(['answered_at' => now()]);

        $sofrendo = $this->pauta->queue($this->de(), $this->ate(), null, ['state' => 'pendente']);
        $prontas = $this->pauta->queue($this->de(), $this->ate(), null, ['state' => 'respondida']);

        $this->assertSame([$pendente->id], array_column($sofrendo, 'insight_id'));
        $this->assertSame([$respondida->id], array_column($prontas, 'insight_id'));
    }

    /**
     * O trecho da fila corta no espaço, nunca no meio da palavra: metade de uma
     * palavra numa coluna é ruído, e quem lê a fila está decidindo em quem
     * clicar.
     */
    public function test_o_trecho_da_fila_nao_corta_palavra_ao_meio(): void
    {
        $this->insight('Longa', str_repeat('palavra ', 40));

        $trecho = $this->pauta->queue($this->de(), $this->ate())[0]['excerpt'];

        $this->assertStringEndsWith('…', $trecho);
        $this->assertStringNotContainsString('palav…', $trecho);
    }

    private function de(): Carbon
    {
        return Carbon::parse('2026-08-01')->startOfDay();
    }

    private function ate(): Carbon
    {
        return Carbon::parse('2026-08-31')->endOfDay();
    }

    private function insight(
        string $quem,
        string $frase,
        InsightUrgency $pressa = InsightUrgency::Medium,
    ): ConversationInsight {
        $contato = Contact::factory()->create(['first_name' => $quem, 'name' => $quem, 'city' => 'Chapecó']);
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'body' => $frase,
        ]);

        return ConversationInsight::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'source_message_id' => $mensagem->id,
            'identified_problem' => 'Interdição da ponte',
            'urgency' => $pressa->value,
            'confidence' => 0.90,
        ]);
    }
}
