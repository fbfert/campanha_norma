<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Enums\ReplySuggestionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sugestão pendente fica obsoleta quando o sistema já respondeu.
 *
 * A obsolescência olhava só para mensagens recebidas: a sugestão morria se a
 * pessoa escrevesse de novo, porque o texto tinha sido pensado para o que ela
 * disse antes. Faltava a outra metade — o sistema já ter respondido por outro
 * caminho.
 *
 * Aconteceu com a Diangeli: uma tentativa de reprocessamento gerou a sugestão,
 * o fluxo mandou a pergunta seguinte, e a sugestão saiu depois. Ela recebeu
 * duas mensagens em setenta e cinco segundos, as duas sobre o mesmo assunto.
 */
class SugestaoObsoletaPorRespostaJaEnviadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_saida_posterior_a_mensagem_de_origem_torna_a_sugestao_obsoleta(): void
    {
        $conversa = $this->conversa();
        $recebida = $this->mensagem($conversa, ConversationMessageDirection::Incoming, 'Oi');

        $sugestao = ConversationReplySuggestion::factory()->create([
            'conversation_id' => $conversa->id,
            'source_message_id' => $recebida->id,
            'active_source_message_id' => $recebida->id,
            'status' => ReplySuggestionStatus::Pending,
        ]);

        $this->assertFalse($sugestao->isStale(), 'Sem resposta ainda, a sugestão continua válida.');

        $this->mensagem($conversa, ConversationMessageDirection::Outgoing, 'A prof Norma gostaria de saber sua opinião.');

        $this->assertTrue($sugestao->isStale());
    }

    /**
     * A saída anterior à mensagem de origem é o convite que provocou a
     * resposta — ela não pode invalidar a sugestão.
     */
    public function test_saida_anterior_nao_invalida_a_sugestao(): void
    {
        $conversa = $this->conversa();
        $this->mensagem($conversa, ConversationMessageDirection::Outgoing, 'Oi, posso te fazer uma pergunta?');
        $recebida = $this->mensagem($conversa, ConversationMessageDirection::Incoming, 'Pode');

        $sugestao = ConversationReplySuggestion::factory()->create([
            'conversation_id' => $conversa->id,
            'source_message_id' => $recebida->id,
            'active_source_message_id' => $recebida->id,
            'status' => ReplySuggestionStatus::Pending,
        ]);

        $this->assertFalse($sugestao->isStale());
    }

    /**
     * A regra antiga continua valendo: pessoa que escreve de novo invalida o
     * texto pensado para a mensagem anterior.
     */
    public function test_mensagem_recebida_mais_nova_continua_invalidando(): void
    {
        $conversa = $this->conversa();
        $recebida = $this->mensagem($conversa, ConversationMessageDirection::Incoming, 'Oi');

        $sugestao = ConversationReplySuggestion::factory()->create([
            'conversation_id' => $conversa->id,
            'source_message_id' => $recebida->id,
            'active_source_message_id' => $recebida->id,
            'status' => ReplySuggestionStatus::Pending,
        ]);

        $this->mensagem($conversa, ConversationMessageDirection::Incoming, 'Na verdade, deixa pra lá.');

        $this->assertTrue($sugestao->isStale());
    }

    private function conversa(): Conversation
    {
        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);

        return Conversation::factory()->create(['contact_id' => $contato->id]);
    }

    private function mensagem(Conversation $conversa, ConversationMessageDirection $direcao, string $corpo): ConversationMessage
    {
        $entrada = $direcao === ConversationMessageDirection::Incoming;

        return ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => $direcao,
            'message_type' => 'text',
            'provider' => 'web',
            'body' => $corpo,
            'status' => $entrada ? ConversationMessageStatus::Received : ConversationMessageStatus::Sent,
            'received_at' => $entrada ? now() : null,
            'sent_at' => $entrada ? null : now(),
        ]);
    }
}
