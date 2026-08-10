<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppConnection;
use App\Services\ConversationAutomation\PendingReplyResolver;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rede de segurança não repete tentativa contra parede.
 *
 * Ela existe para que ninguém fique sem resposta, e por isso dois guardas
 * ignoram saída que falhou de propósito: falha não é resposta, e um aviso que
 * não saiu não pode segurar a próxima tentativa. Sem um teto, os dois juntos
 * produzem repetição sem fim.
 *
 * Foi o que aconteceu em 07/08/2026. A sessão do WhatsApp caiu numa sexta à
 * noite e voltou 64 horas depois. A cada cinco minutos a rede tentou mandar o
 * mesmo agradecimento para duas conversas, e gravou 767 falhas em cada uma: as
 * conversas 355 e 1414 chegaram a 771 mensagens, sendo 13 reais. Metade da
 * tabela de mensagens do sistema virou repetição de duas frases que nunca
 * saíram.
 *
 * O teto é duplo. Sem sessão não se tenta, porque a pessoa está inalcançável de
 * qualquer jeito e o envio falharia com certeza. E, mesmo com sessão, uma
 * mensagem só é tentada um número limitado de vezes — falha que persiste por
 * outro motivo pararia só quando alguém percebesse.
 */
class RedeDeSegurancaNaoRepeteSemConexaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_sem_sessao_conectada_nao_tenta_enviar(): void
    {
        $this->conexao(WhatsAppConnectionStatus::Disconnected);
        [$conversa, $mensagem] = $this->conversaComPerguntaSemResposta();

        $resultado = app(PendingReplyResolver::class)->resolve($conversa, $mensagem);

        $this->assertSame('sem_conexao', $resultado['outcome']);

        // O ponto todo: nenhuma linha nova na conversa.
        $this->assertSame(1, $conversa->messages()->count());
    }

    /**
     * Voltando a conexão, a execução seguinte tenta de novo — a espera não
     * consome tentativa nem desiste da pessoa.
     */
    public function test_com_a_sessao_de_volta_volta_a_tentar(): void
    {
        $this->conexao(WhatsAppConnectionStatus::Disconnected);
        [$conversa, $mensagem] = $this->conversaComPerguntaSemResposta();

        $this->assertSame('sem_conexao', app(PendingReplyResolver::class)->resolve($conversa, $mensagem)['outcome']);

        $this->conexao(WhatsAppConnectionStatus::Connected);

        $this->assertNotSame('sem_conexao', app(PendingReplyResolver::class)->resolve($conversa, $mensagem, simular: true)['outcome']);
    }

    /**
     * Mesmo conectado, a mesma mensagem não é tentada para sempre.
     */
    public function test_o_teto_de_tentativas_para_a_repeticao(): void
    {
        $this->conexao(WhatsAppConnectionStatus::Connected);
        [$conversa, $mensagem] = $this->conversaComPerguntaSemResposta();

        // Cinco tentativas já falhadas, que é o teto padrão.
        for ($i = 0; $i < 5; $i++) {
            $this->tentativaFalhada($conversa);
        }

        $resultado = app(PendingReplyResolver::class)->resolve($conversa, $mensagem);

        $this->assertSame('tentativas_esgotadas', $resultado['outcome']);
    }

    /** Abaixo do teto, continua tentando. */
    public function test_abaixo_do_teto_ainda_tenta(): void
    {
        $this->conexao(WhatsAppConnectionStatus::Connected);
        [$conversa, $mensagem] = $this->conversaComPerguntaSemResposta();

        $this->tentativaFalhada($conversa);

        $this->assertNotSame(
            'tentativas_esgotadas',
            app(PendingReplyResolver::class)->resolve($conversa, $mensagem, simular: true)['outcome']
        );
    }

    /**
     * Reproduz o laço: 64 horas sem sessão, uma execução a cada cinco minutos.
     *
     * Antes do teto isso gravava 767 linhas. Agora não grava nenhuma.
     */
    public function test_sessenta_e_quatro_horas_sem_sessao_nao_geram_lixo(): void
    {
        $this->conexao(WhatsAppConnectionStatus::Disconnected);
        [$conversa, $mensagem] = $this->conversaComPerguntaSemResposta();

        for ($i = 0; $i < 100; $i++) {
            app(PendingReplyResolver::class)->resolve($conversa, $mensagem);
        }

        $this->assertSame(1, $conversa->messages()->count());
    }

    /** @return array{0: Conversation, 1: ConversationMessage} */
    private function conversaComPerguntaSemResposta(): array
    {
        $contato = Contact::factory()->create(['phone_normalized' => '5549991613378']);
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'status' => ConversationMessageStatus::Received,
            'body' => 'Moro em São Cristóvão do Sul, não em Ponte Alta.',
        ]);

        return [$conversa->refresh(), $mensagem];
    }

    private function tentativaFalhada(Conversation $conversa): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'status' => ConversationMessageStatus::Failed,
            'origin' => ConversationMessageOrigin::Automation,
            'body' => 'Recebemos sua mensagem, muito obrigado! Nossa equipe vai ler com atenção.',
        ]);
    }

    private function conexao(WhatsAppConnectionStatus $status): WhatsAppConnection
    {
        WhatsAppConnection::query()->delete();

        return WhatsAppConnection::create([
            'status' => $status,
            'phone_number' => '554991888242',
            'connected_at' => now()->subHour(),
            'last_activity_at' => now()->subHour(),
        ]);
    }
}
