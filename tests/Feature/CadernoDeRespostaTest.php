<?php

namespace Tests\Feature;

use App\Enums\InsightUrgency;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O caderno de resposta gerado em HTML, antes de existir tela.
 *
 * O que este teste protege não é formatação: é que a frase da pessoa chegue
 * inteira, que a página seja dela e de mais ninguém, e que uma leitura de
 * confiança baixa avise antes de alguém responder em cima dela.
 */
class CadernoDeRespostaTest extends TestCase
{
    use RefreshDatabase;

    private string $saida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);

        $this->saida = 'storage/app/private/caderno-de-teste.html';
    }

    protected function tearDown(): void
    {
        if (file_exists(base_path($this->saida))) {
            unlink(base_path($this->saida));
        }

        parent::tearDown();
    }

    public function test_gera_uma_pagina_por_pessoa_com_a_frase_literal(): void
    {
        $this->insight(
            nome: 'Marta',
            cidade: 'Chapecó',
            frase: 'O posto de saúde do meu bairro abre às sete e a fila já dobra o quarteirão.',
        );
        $this->insight(
            nome: 'Jonas',
            cidade: 'Xanxerê',
            frase: 'A creche mais próxima fica a quatro quilômetros e não tem linha de ônibus.',
        );

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $html = $this->html();

        $this->assertSame(2, substr_count($html, 'class="pessoa"'), 'Cada pessoa tem a sua própria página.');
        $this->assertStringContainsString('Marta', $html);
        $this->assertStringContainsString('Jonas', $html);
        $this->assertStringContainsString('a fila já dobra o quarteirão.', $html);
        $this->assertStringContainsString('não tem linha de ônibus.', $html);
    }

    /**
     * A frase vai inteira. Resumir trocaria o que o eleitor escreveu pelo que o
     * sistema achou que ele quis dizer, e é justamente a citação literal que dá
     * força ao caderno.
     */
    public function test_a_frase_nao_e_cortada(): void
    {
        $frase = 'A rua que liga o meu bairro ao centro não tem calçada em nenhum dos lados, '
            .'então todo mundo caminha no acostamento, inclusive as crianças que vão para a escola '
            .'às sete da manhã, e no inverno ainda está escuro nesse horário.';

        $this->insight(nome: 'Cida', cidade: 'Palmitos', frase: $frase);

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $this->assertStringContainsString(htmlspecialchars($frase, ENT_QUOTES, 'UTF-8'), $this->html());
    }

    public function test_avisa_quando_a_confianca_esta_abaixo_do_limiar(): void
    {
        $this->insight(nome: 'Rita', cidade: 'Seara', frase: 'Falta remédio.', confianca: 0.30);

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $html = $this->html();

        $this->assertStringContainsString('Confiança baixa', $html);
        $this->assertStringContainsString('Confira a mensagem original antes de responder.', $html);
    }

    public function test_nao_avisa_quando_a_confianca_esta_acima_do_limiar(): void
    {
        $this->insight(nome: 'Rita', cidade: 'Seara', frase: 'Falta remédio.', confianca: 0.95);

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $this->assertStringNotContainsString('Confiança baixa', $this->html());
    }

    /**
     * Urgência alta primeiro; empatada, a resposta mais longa primeiro. Quem
     * escreveu muito investiu mais na conversa, e ignorar essa resposta custa
     * mais do que ignorar uma de três palavras.
     */
    public function test_ordena_por_urgencia_e_depois_por_tamanho(): void
    {
        $this->insight(nome: 'Baixa', cidade: 'A', frase: 'Curta.', urgencia: InsightUrgency::Low);
        $this->insight(nome: 'AltaCurta', cidade: 'B', frase: 'Urgente.', urgencia: InsightUrgency::High);
        $this->insight(
            nome: 'AltaLonga',
            cidade: 'C',
            frase: 'Urgente e com muito mais detalhe do que a outra mensagem urgente deste caderno.',
            urgencia: InsightUrgency::High,
        );

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $html = $this->html();

        $this->assertLessThan(
            strpos($html, 'AltaCurta'),
            strpos($html, 'AltaLonga'),
            'Entre duas urgências altas, a resposta mais longa vem primeiro.',
        );
        $this->assertLessThan(
            strpos($html, '>Baixa<'),
            strpos($html, 'AltaCurta'),
            'Urgência alta vem antes de urgência baixa.',
        );
    }

    /**
     * A linha vermelha ainda não existe, e o caderno diz isso. Seção ausente em
     * silêncio seria lida como "não há nada a evitar aqui", que é o contrário
     * do que a ausência significa.
     */
    public function test_reserva_o_espaco_da_linha_vermelha(): void
    {
        $this->insight(nome: 'Marta', cidade: 'Chapecó', frase: 'Qualquer coisa.');

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $html = $this->html();

        $this->assertStringContainsString('Linha vermelha — o que não prometer', $html);
        $this->assertStringContainsString('Ainda não escrita para o tema', $html);
    }

    public function test_a_capa_diz_o_que_o_documento_e_e_o_que_nao_e(): void
    {
        $this->insight(nome: 'Marta', cidade: 'Chapecó', frase: 'Qualquer coisa.');

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida, '--por' => 'Felipe'])->assertSuccessful();

        $html = $this->html();

        $this->assertStringContainsString('Documento nominal.', $html);
        $this->assertStringContainsString('Não é pesquisa eleitoral registrada', $html);
        $this->assertStringContainsString('Felipe', $html);
    }

    public function test_restringe_ao_fluxo_pedido(): void
    {
        $fluxo = ConversationFlow::factory()->create();
        $outro = ConversationFlow::factory()->create();

        $this->insight(nome: 'Dentro', cidade: 'A', frase: 'Do fluxo pedido.', fluxo: $fluxo);
        $this->insight(nome: 'Fora', cidade: 'B', frase: 'De outro fluxo.', fluxo: $outro);

        $this->artisan('relatorios:caderno', ['--saida' => $this->saida, '--fluxo' => $fluxo->id])
            ->assertSuccessful();

        $html = $this->html();

        $this->assertStringContainsString('Dentro', $html);
        $this->assertStringNotContainsString('>Fora<', $html);
    }

    public function test_periodo_vazio_nao_escreve_arquivo(): void
    {
        $this->artisan('relatorios:caderno', ['--saida' => $this->saida])->assertSuccessful();

        $this->assertFileDoesNotExist(base_path($this->saida));
    }

    private function html(): string
    {
        $caminho = base_path($this->saida);

        $this->assertFileExists($caminho);

        return (string) file_get_contents($caminho);
    }

    private function insight(
        string $nome,
        string $cidade,
        string $frase,
        float $confianca = 0.90,
        InsightUrgency $urgencia = InsightUrgency::Medium,
        ?ConversationFlow $fluxo = null,
    ): ConversationInsight {
        $contato = Contact::factory()->create(['first_name' => $nome, 'name' => $nome, 'city' => $cidade]);
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
            'conversation_flow_id' => $fluxo?->id,
            'insight_topic_id' => InsightTopic::factory()->create(['name' => 'Saúde'])->id,
            'confidence' => $confianca,
            'urgency' => $urgencia->value,
        ]);
    }
}
