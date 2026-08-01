<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationQuestionOrder;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Services\ConversationAutomation\ConversationQuestionSelector;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ordem em que a pesquisa faz as perguntas.
 *
 * O sorteio ponderado cobre muitos temas com poucas perguntas por pessoa. Um
 * questionário quer o contrário: todo mundo respondendo as mesmas perguntas na
 * mesma ordem, senão as respostas não se comparam. A escolha e por fluxo, e o
 * padrão continua sendo o sorteio para não mudar fluxo nenhum que já existe.
 */
class OrdemDasPerguntasDoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_padrao_continua_sendo_sorteio(): void
    {
        $flow = ConversationFlow::factory()->create();

        $this->assertSame(ConversationQuestionOrder::Sorteio, $flow->question_order);
    }

    public function test_em_sequencia_as_perguntas_saem_na_ordem_cadastrada(): void
    {
        $state = $this->pesquisa(ConversationQuestionOrder::Sequencia);
        $selector = app(ConversationQuestionSelector::class);

        $textos = [];

        for ($i = 0; $i < 4; $i++) {
            $textos[] = $selector->select($state)?->question_snapshot;
        }

        $this->assertSame(['Primeira', 'Segunda', 'Terceira', 'Quarta'], $textos);
    }

    public function test_em_sequencia_o_peso_e_ignorado(): void
    {
        $state = $this->pesquisa(ConversationQuestionOrder::Sequencia);

        // A quarta pergunta tem peso esmagador; em sorteio ela sairia quase
        // sempre primeiro. Em sequência, ela e a última.
        $this->assertSame('Primeira', app(ConversationQuestionSelector::class)->select($state)?->question_snapshot);
    }

    public function test_em_sorteio_o_peso_continua_valendo(): void
    {
        $state = $this->pesquisa(ConversationQuestionOrder::Sorteio);

        $sorteadas = [];

        for ($i = 0; $i < 20; $i++) {
            $novo = $this->pesquisa(ConversationQuestionOrder::Sorteio);
            $sorteadas[] = app(ConversationQuestionSelector::class)->select($novo)?->question_snapshot;
        }

        $this->assertContains('Quarta', $sorteadas, 'Com peso 500 contra 1, a quarta precisa aparecer no sorteio.');
        $this->assertNotNull(app(ConversationQuestionSelector::class)->select($state));
    }

    private function pesquisa(ConversationQuestionOrder $ordem): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create([
            'question_order' => $ordem,
            'max_main_questions' => 4,
        ]);

        foreach ([
            ['Primeira', 1, 1],
            ['Segunda', 2, 1],
            ['Terceira', 3, 1],
            ['Quarta', 4, 500],
        ] as [$texto, $ordemExibicao, $peso]) {
            ConversationFlowQuestion::factory()->create([
                'conversation_flow_id' => $flow->id,
                'text' => $texto,
                'display_order' => $ordemExibicao,
                'weight' => $peso,
                'is_active' => true,
            ]);
        }

        $conversation = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::PermissionGranted,
            'expires_at' => now()->addDay(),
        ]);
    }
}
