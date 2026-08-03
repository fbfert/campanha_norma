<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\ResponseGeneration\ResponseContextBuilder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Varias mensagens seguidas são uma resposta so.
 *
 * Quem responde por WhatsApp quebra o raciocínio em mensagens curtas. O
 * contexto rebaixava as primeiras a "mensagens recentes" e marcava so a última
 * como a resposta — e o modelo, obedecendo, devolvia uma pergunta sobre a
 * última ideia, como se as anteriores não existissem.
 *
 * Aconteceu em produção com uma respondente que escreveu, em sequência:
 * "fomento das políticas públicas", "expansão para o município" e "incentivo
 * para formação de novos profissionais". Ela recebeu de volta uma pergunta
 * apenas sobre a formação de profissionais, três vezes seguidas, em blocos
 * diferentes.
 */
class BlocoDeMensagensViraUmaRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_o_bloco_inteiro_vai_como_resposta(): void
    {
        $state = $this->conversa();

        $this->recebida($state, 'Fomento das políticas públicas;');
        $this->recebida($state, 'Expansão para o município, empregabilidade;');
        $ultima = $this->recebida($state, 'Incentivo para formação de novos profissionais;');

        $contexto = app(ResponseContextBuilder::class)->build($ultima, $state, null, null);

        $this->assertStringContainsString('3 mensagens seguidas, trate como uma resposta só', $contexto);
        $this->assertStringContainsString('1. Fomento das políticas públicas;', $contexto);
        $this->assertStringContainsString('2. Expansão para o município, empregabilidade;', $contexto);
        $this->assertStringContainsString('3. Incentivo para formação de novos profissionais;', $contexto);
    }

    public function test_mensagem_unica_continua_como_ultima_resposta(): void
    {
        $state = $this->conversa();
        $unica = $this->recebida($state, 'Falta praça no bairro.');

        $contexto = app(ResponseContextBuilder::class)->build($unica, $state, null, null);

        $this->assertStringContainsString('ÚLTIMA RESPOSTA DA PESSOA', $contexto);
        $this->assertStringNotContainsString('mensagens seguidas', $contexto);
    }

    /**
     * O bloco começa depois da última resposta enviada. O que veio antes dela e
     * histórico, e não parte da resposta atual.
     */
    public function test_o_bloco_nao_atravessa_uma_resposta_enviada(): void
    {
        $state = $this->conversa();

        $this->recebida($state, 'Isso eu já falei antes.');

        ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'outgoing',
            'body' => 'Entendi. E o que mais?',
        ]);

        $this->recebida($state, 'Agora falta iluminação;');
        $ultima = $this->recebida($state, 'e transporte à noite.');

        $contexto = app(ResponseContextBuilder::class)->build($ultima, $state, null, null);

        $this->assertStringContainsString('2 mensagens seguidas', $contexto);
        $this->assertStringContainsString('1. Agora falta iluminação;', $contexto);
        $this->assertStringContainsString('MENSAGENS RECENTES', $contexto);
        $this->assertStringNotContainsString('3. ', $contexto);
    }

    private function conversa(): ConversationFlowState
    {
        $flow = ConversationFlow::factory()->create(['max_followups' => 15]);
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationFlowState::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::AnswerReceived,
            'selected_question_snapshot' => 'O que pode ser feito para melhorar sua cidade?',
            'expires_at' => now()->addDay(),
        ]);
    }

    private function recebida(ConversationFlowState $state, string $texto): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $state->conversation_id,
            'direction' => 'incoming',
            'body' => $texto,
        ]);
    }
}
