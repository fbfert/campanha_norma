<?php

namespace Tests\Feature;

use App\Enums\HandoffReason;
use App\Enums\InsightReviewReason;
use App\Enums\MessageClassification;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageClassification;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saudação no começo do bloco não derruba a resposta inteira.
 *
 * Quem escreve em blocos abre com "oi", "em um geral" — fragmentos que,
 * isolados, não significam nada e sempre voltam do classificador como
 * incertos. O motivo de encaminhamento era o primeiro encontrado no bloco, e
 * bastava um "Oiee" na frente para a conversa inteira ir para atendimento
 * humano.
 *
 * Aconteceu com uma resposta clara sobre turismo: as três mensagens seguintes
 * vinham com 0,95 de confiança e nenhuma chegou a ser lida.
 *
 * Incerteza não é conteúdo. Ameaça, relato sensível e pedido de gente falam do
 * que a pessoa disse e valem onde quer que apareçam; `low_confidence` fala do
 * classificador, não dela.
 */
class SaudacaoNoBlocoNaoDerrubaARespostaTest extends TestCase
{
    use RefreshDatabase;

    public function test_saudacao_incerta_nao_encaminha_quando_o_bloco_foi_entendido(): void
    {
        $conversa = $this->conversa();

        $this->classificar($conversa, 'Oiee', MessageClassification::Ambiguous, 0.4, InsightReviewReason::LowConfidence);
        $this->classificar($conversa, 'Em um geral', MessageClassification::Ambiguous, 0.4, InsightReviewReason::LowConfidence);
        $ultima = $this->classificar($conversa, 'Investir no turismo', MessageClassification::QuestionAnswer, 0.95, null);

        $this->assertNull($this->motivo($ultima));
    }

    /**
     * Se nada no bloco foi entendido, a incerteza continua valendo: aí não há
     * o que responder mesmo.
     */
    public function test_bloco_inteiro_incerto_continua_encaminhando(): void
    {
        $conversa = $this->conversa();

        $this->classificar($conversa, 'Oiee', MessageClassification::Ambiguous, 0.4, InsightReviewReason::LowConfidence);
        $ultima = $this->classificar($conversa, 'hm', MessageClassification::Ambiguous, 0.3, InsightReviewReason::LowConfidence);

        $this->assertSame(HandoffReason::LowConfidence, $this->motivo($ultima));
    }

    /**
     * Motivo que fala do conteúdo vale onde quer que apareça, e não espera o
     * bloco terminar: pedido de gente no meio de uma resposta clara continua
     * sendo pedido de gente.
     */
    public function test_motivo_de_conteudo_encaminha_mesmo_com_o_bloco_entendido(): void
    {
        $conversa = $this->conversa();

        $this->classificar($conversa, 'Investir no turismo', MessageClassification::QuestionAnswer, 0.95, null);
        $this->classificar($conversa, 'Quero falar com uma pessoa', MessageClassification::HumanRequested, 0.9, null);
        $ultima = $this->classificar($conversa, 'por favor', MessageClassification::QuestionAnswer, 0.9, null);

        $this->assertNotNull($this->motivo($ultima));
    }

    private function motivo(ConversationMessage $mensagem): ?HandoffReason
    {
        $metodo = new \ReflectionMethod(ConversationSuggestionService::class, 'forcedHandoffReason');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(ConversationSuggestionService::class), $mensagem);
    }

    private function classificar(
        Conversation $conversa,
        string $corpo,
        MessageClassification $classificacao,
        float $confianca,
        ?InsightReviewReason $motivo,
    ): ConversationMessage {
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'body' => $corpo,
        ]);

        ConversationMessageClassification::factory()->create([
            'conversation_id' => $conversa->id,
            'conversation_message_id' => $mensagem->id,
            'classification' => $classificacao,
            'confidence' => $confianca,
            'requires_human_review' => $motivo !== null,
            'review_reason' => $motivo?->value,
        ]);

        return $mensagem;
    }

    private function conversa(): Conversation
    {
        return Conversation::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);
    }
}
