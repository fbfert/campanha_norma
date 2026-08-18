<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O nome de quem escreveu chega junto com a mensagem.
 *
 * A tabela guarda `sender_name_snapshot` desde a Etapa 7, e o caminho da Meta
 * sempre o preencheu. O do WhatsApp Web mandava `sender_name: null` cravado,
 * então toda mensagem que entrava pelo provedor que a operação usa de verdade
 * chegava anônima — e o atendimento de entrada, que lê esse campo para saudar
 * a pessoa pelo nome, nunca tinha nome nenhum para ler.
 *
 * O preenchimento é do serviço Node. O que estes testes garantem é o outro
 * lado: que o nome enviado é gravado, e que a ausência dele continua sendo um
 * caso normal e não um erro.
 */
class NomeDoRemetenteChegaNaMensagemTest extends TestCase
{
    use RefreshDatabase;

    public function test_nome_recebido_e_gravado_na_mensagem(): void
    {
        ProcessIncomingMessageJob::dispatchSync($this->payload(['sender_name' => 'Maria da Silva']));

        $this->assertSame(
            'Maria da Silva',
            ConversationMessage::firstOrFail()->sender_name_snapshot,
        );
    }

    /**
     * Quem não tem nome de perfil nem está na agenda continua sendo atendido.
     * Bloquear aqui trocaria um cadastro incompleto por uma mensagem perdida.
     */
    public function test_mensagem_sem_nome_continua_sendo_processada(): void
    {
        ProcessIncomingMessageJob::dispatchSync($this->payload(['sender_name' => null]));

        $mensagem = ConversationMessage::firstOrFail();

        $this->assertNull($mensagem->sender_name_snapshot);
        $this->assertSame('Quero participar do sorteio', $mensagem->body);
    }

    /**
     * Payload antigo, de uma versão do serviço Node que ainda não manda o
     * campo. A ausência da chave não pode ser diferente de nome vazio.
     */
    public function test_payload_sem_a_chave_do_nome_nao_quebra(): void
    {
        $payload = $this->payload();
        unset($payload['sender_name']);

        ProcessIncomingMessageJob::dispatchSync($payload);

        $this->assertNull(ConversationMessage::firstOrFail()->sender_name_snapshot);
    }

    /**
     * Nome em branco é ausência de nome.
     *
     * String vazia gravada vira uma linha com nome em branco na tela, que
     * ninguém distingue de um nome que não carregou. `null` diz a verdade.
     */
    public function test_nome_so_com_espaco_e_gravado_como_ausencia(): void
    {
        ProcessIncomingMessageJob::dispatchSync($this->payload(['sender_name' => '   ']));

        $this->assertNull(ConversationMessage::firstOrFail()->sender_name_snapshot);
    }

    /**
     * O serviço Node já apara em 120 para casar com esta validação. Se algum
     * dia parar de aparar, a mensagem inteira é recusada na porta — e este
     * teste é o que registra esse acoplamento.
     */
    public function test_nome_maior_que_o_limite_e_recusado_na_validacao(): void
    {
        ProcessIncomingMessageJob::dispatchSync($this->payload(['sender_name' => str_repeat('a', 121)]));

        $this->assertSame(0, ConversationMessage::count());
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function payload(array $sobrescritas = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'provider' => 'web',
            'connection_id' => 'principal',
            'external_message_id' => 'wamid.nome.1',
            'sender_phone' => '5549999990001',
            'sender_name' => null,
            'recipient_phone' => '5549999990002',
            'message_type' => 'text',
            'text' => 'Quero participar do sorteio',
            'sent_at' => now()->toIso8601String(),
            'received_at' => now()->toIso8601String(),
            'is_from_me' => false,
            'is_group' => false,
            'has_media' => false,
            'quoted_external_message_id' => null,
            'metadata' => [],
        ], $sobrescritas);
    }
}
