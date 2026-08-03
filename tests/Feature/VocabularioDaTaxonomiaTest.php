<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\InsightTopicMapper;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O modelo recebe o vocabulário de cada tema.
 *
 * Os sinônimos sempre existiram no cadastro, mas so eram consultados depois,
 * para mapear a resposta do modelo de volta a um tema. Na hora de escolher, ele
 * via apenas uma lista de identificadores e tinha de adivinhar o alcance de
 * cada um — e metade das analises caiu em "outros / nao classificado", que
 * virou o tema mais usado da base.
 */
class VocabularioDaTaxonomiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_tema_vai_para_o_prompt_com_seus_sinonimos(): void
    {
        InsightTopic::factory()->create([
            'name' => 'Cultura',
            'slug' => 'cultura',
            'synonyms' => 'esporte|lazer|praça|biblioteca',
            'is_active' => true,
        ]);

        $temas = app(InsightTopicMapper::class)->promptTopics();

        $this->assertContains('cultura (esporte, lazer, praça, biblioteca)', $temas);
    }

    public function test_tema_sem_sinonimo_vai_so_com_o_identificador(): void
    {
        InsightTopic::factory()->create(['name' => 'Turismo', 'slug' => 'turismo', 'synonyms' => null, 'is_active' => true]);

        $this->assertContains('turismo', app(InsightTopicMapper::class)->promptTopics());
    }

    public function test_tema_inativo_nao_entra(): void
    {
        InsightTopic::factory()->create(['name' => 'Antigo', 'slug' => 'antigo', 'is_active' => false]);

        $this->assertEmpty(array_filter(
            app(InsightTopicMapper::class)->promptTopics(),
            fn (string $tema): bool => str_starts_with($tema, 'antigo'),
        ));
    }

    /**
     * O identificador precisa ficar visualmente separado do vocabulário, senão
     * o modelo devolve um sinônimo no lugar do identificador e o mapeamento
     * volta a cair no fallback.
     */
    public function test_o_contexto_pede_o_identificador_e_lista_um_por_linha(): void
    {
        $contexto = app(AiContextBuilder::class)->forExtraction(
            $this->mensagem(),
            null,
            ['saude (hospital, posto de saude)', 'cultura (esporte, lazer)'], // ortografia:ignorar - slug e sinonimo normalizado, comparados sem acento
            'outros',
        );

        $this->assertStringContainsString('responda com o identificador, antes dos parênteses', $contexto);
        $this->assertStringContainsString("- saude (hospital, posto de saude)\n- cultura (esporte, lazer)", $contexto); // ortografia:ignorar
    }

    public function test_o_mapeamento_de_volta_continua_aceitando_sinonimo(): void
    {
        $tema = InsightTopic::factory()->create([
            'name' => 'Saúde',
            'slug' => 'saude',
            'synonyms' => 'hospital|posto de saúde',
            'is_active' => true,
        ]);

        $mapper = app(InsightTopicMapper::class);

        $this->assertSame($tema->id, $mapper->map('saude')?->id);
        $this->assertSame($tema->id, $mapper->map('hospital')?->id, 'O modelo que devolver sinônimo ainda precisa cair no tema certo.');
    }

    private function mensagem(): ConversationMessage
    {
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => 'Falta praça e lugar para as crianças brincarem.',
        ]);
    }
}
