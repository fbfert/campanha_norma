<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSyncRun;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\Conversations\ConversationSyncService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quem escreveu a palavra-chave durante uma queda também se inscreve.
 *
 * A sincronização já recuperava duas coisas de quem escreveu com o sistema
 * fora do ar: o atendimento de entrada e o pedido de texto para mídia
 * ilegível. A inscrição em campanha ficou de fora — o gatilho mora no job do
 * caminho ao vivo, e a sincronização nunca o chamava.
 *
 * O buraco não avisava. A mensagem entrava, a conversa aparecia na fila, e a
 * promoção simplesmente não disparava: nenhum erro, nenhuma linha de log,
 * nenhuma inscrição. Foi assim que o Renan escreveu "batata" numa campanha
 * vigente, às 14h49 de 19/08/2026, e não entrou na lista.
 */
class SincronizacaoAvaliaPalavraChaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Queue::fake();
        Http::fake();
    }

    public function test_a_palavra_chave_recuperada_pela_sincronizacao_vira_inscricao(): void
    {
        $campanha = $this->campanhaVigente();
        [$conversa, $contato] = $this->conversa();

        $this->sincronizar($conversa, $contato, 'batata');

        $this->assertSame(1, KeywordCampaignParticipation::query()
            ->where('keyword_campaign_id', $campanha->id)
            ->where('contact_id', $contato->id)
            ->count());
    }

    /**
     * Mensagem antiga não vira inscrição por sincronização.
     *
     * A varredura alcança trinta dias de histórico. Inscrever — e confirmar —
     * quem escreveu a palavra há três semanas seria falar do passado, e é o
     * mesmo recorte que a mídia ilegível e o atendimento de entrada já usam.
     * Para recuperar histórico existe `campanhas:reprocessar`, que inscreve
     * sem responder a ninguém.
     */
    public function test_mensagem_antiga_nao_inscreve_pela_sincronizacao(): void
    {
        $campanha = $this->campanhaVigente();
        [$conversa, $contato] = $this->conversa();

        $this->sincronizar($conversa, $contato, 'batata', now()->subDays(20));

        $this->assertSame(0, KeywordCampaignParticipation::query()
            ->where('keyword_campaign_id', $campanha->id)
            ->count());
    }

    public function test_mensagem_sem_a_palavra_nao_inscreve_ninguem(): void
    {
        $this->campanhaVigente();
        [$conversa, $contato] = $this->conversa();

        $this->sincronizar($conversa, $contato, 'bom dia');

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    /**
     * Rodar a sincronização duas vezes não inscreve duas vezes.
     *
     * A segunda passagem nem chega ao gatilho: a mensagem já existe e é
     * ignorada antes disso. A chave única da campanha é a segunda defesa.
     */
    public function test_sincronizar_duas_vezes_nao_duplica_a_inscricao(): void
    {
        $campanha = $this->campanhaVigente();
        [$conversa, $contato] = $this->conversa();

        $this->sincronizar($conversa, $contato, 'batata');
        $this->sincronizar($conversa, $contato, 'batata');

        $this->assertSame(1, KeywordCampaignParticipation::query()
            ->where('keyword_campaign_id', $campanha->id)
            ->count());
    }

    private function campanhaVigente(): KeywordCampaign
    {
        return KeywordCampaign::factory()->create([
            'status' => 'ativa',
            'keywords' => ['batata'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'confirmation_text' => 'Inscrição confirmada! Boa sorte.',
        ]);
    }

    /**
     * @return array{0: Conversation, 1: Contact}
     */
    private function conversa(): array
    {
        $contato = Contact::factory()->create(['phone_normalized' => '5549999990001']);

        $conversa = Conversation::factory()->create([
            'contact_id' => $contato->id,
            'external_chat_id' => '5549999990001@c.us',
            'provider' => 'web',
        ]);

        return [$conversa, $contato];
    }

    private function sincronizar(Conversation $conversa, Contact $contato, string $corpo, ?Carbon $quando = null): void
    {
        $run = ConversationSyncRun::create(['status' => 'running', 'started_at' => now()]);
        $quando ??= now();

        $servico = app(ConversationSyncService::class);

        $metodo = new \ReflectionMethod(ConversationSyncService::class, 'syncMessage');
        $metodo->setAccessible(true);
        $metodo->invoke($servico, $run, $conversa, [
            'external_message_id' => 'wamid.'.md5($corpo.$quando->timestamp),
            'external_chat_id' => $conversa->external_chat_id,
            'direction' => 'incoming',
            'type' => 'text',
            'body' => $corpo,
            'sent_at' => $quando->toIso8601String(),
        ], [
            'external_chat_id' => $conversa->external_chat_id,
            'phone' => '5549999990001',
            'name' => 'Renan Lobo',
        ], $contato);

        /*
         | O tratamento pós-commit é chamado à mão, e isso é limitação da
         | suíte, não do código.
         |
         | A sincronização grava a mensagem dentro de uma transação e só depois
         | trata, por `DB::afterCommit`. O `RefreshDatabase` mantém uma
         | transação aberta do começo ao fim do teste, então esse commit nunca
         | acontece e a chamada ficaria pendente para sempre. Só o que decide
         | fica exercitado aqui; o `if` que liga uma coisa na outra é uma linha,
         | logo acima do método, e se lê de uma vez.
         */
        $mensagem = ConversationMessage::query()->where('conversation_id', $conversa->id)->latest('id')->first();

        if ($mensagem === null) {
            return;
        }

        $guarda = new \ReflectionMethod(ConversationSyncService::class, 'mereceTratamentoAoVivo');
        $guarda->setAccessible(true);

        if (! $guarda->invoke($servico, $mensagem, $quando)) {
            return;
        }

        $tratar = new \ReflectionMethod(ConversationSyncService::class, 'tratarMensagemRecuperada');
        $tratar->setAccessible(true);
        $tratar->invoke($servico, $mensagem);
    }
}
