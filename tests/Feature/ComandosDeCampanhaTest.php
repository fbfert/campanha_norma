<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use App\Models\KeywordCampaignParticipation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os comandos de operação da campanha.
 *
 * O de reprocessamento é a rede de segurança da etapa inteira: como a
 * participação é derivável da mensagem gravada, job morto e fila limpa não
 * perdem inscrição.
 */
class ComandosDeCampanhaTest extends TestCase
{
    use RefreshDatabase;

    private function mensagemRecebida(string $telefone, string $texto, ?string $recebidaEm = null): ConversationMessage
    {
        $conversa = Conversation::firstOrCreate(
            ['provider' => 'web', 'external_chat_id' => "{$telefone}@c.us"],
            Conversation::factory()->make()->only([
                'contact_id', 'connection_id', 'status', 'priority',
                'last_message_direction', 'last_message_at', 'last_incoming_message_at',
                'unread_count', 'is_archived',
            ]),
        );

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'sender_phone_snapshot' => $telefone,
            'sender_name_snapshot' => 'Maria da Silva',
            'direction' => ConversationMessageDirection::Incoming,
            'message_type' => 'text',
            'body' => $texto,
            'received_at' => $recebidaEm ? now()->parse($recebidaEm) : now(),
        ]);
    }

    public function test_reprocessar_cria_a_participacao_que_o_job_perdeu(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->mensagemRecebida('5549999990001', 'quero o sorteio');

        $this->assertSame(0, KeywordCampaignParticipation::count());

        $this->artisan('campanhas:reprocessar')->assertSuccessful();

        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    public function test_reprocessar_e_idempotente(): void
    {
        KeywordCampaign::factory()->create();
        $this->mensagemRecebida('5549999990001', 'quero o sorteio');

        $this->artisan('campanhas:reprocessar')->assertSuccessful();
        $this->artisan('campanhas:reprocessar')->assertSuccessful();

        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    /**
     * Execução seca não grava. É como se confere um período grande antes de
     * mexer em produção.
     */
    public function test_dry_run_nao_grava_nada(): void
    {
        KeywordCampaign::factory()->create();
        $this->mensagemRecebida('5549999990001', 'quero o sorteio');

        // As factories já criaram contatos: o que se mede é se o comando criou
        // mais algum, e não quantos existem.
        $contatosAntes = Contact::count();

        $this->artisan('campanhas:reprocessar --dry-run')
            ->expectsOutputToContain('1 inscrição seria criada')
            ->assertSuccessful();

        $this->assertSame(0, KeywordCampaignParticipation::count());
        $this->assertSame($contatosAntes, Contact::count());
    }

    /**
     * Varrer o histórico inteiro inscreveria quem escreveu a palavra meses
     * antes da campanha existir — e essa pessoa não se inscreveu em nada.
     */
    public function test_mensagem_anterior_a_vigencia_nao_e_reprocessada(): void
    {
        KeywordCampaign::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->mensagemRecebida('5549999990001', 'quero o sorteio', now()->subMonth()->toDateTimeString());

        $this->artisan('campanhas:reprocessar')->assertSuccessful();

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_reprocessar_aceita_recorte_de_periodo(): void
    {
        KeywordCampaign::factory()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDay(),
        ]);

        $this->mensagemRecebida('5549999990001', 'quero o sorteio', now()->subDays(20)->toDateTimeString());

        $this->artisan('campanhas:reprocessar --from='.now()->subDays(5)->toDateString())->assertSuccessful();
        $this->assertSame(0, KeywordCampaignParticipation::count());

        $this->artisan('campanhas:reprocessar --from='.now()->subDays(25)->toDateString())->assertSuccessful();
        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    public function test_reprocessar_limita_a_uma_campanha(): void
    {
        $primeira = KeywordCampaign::factory()->create(['name' => 'Primeira']);
        KeywordCampaign::factory()->create(['name' => 'Segunda']);
        $this->mensagemRecebida('5549999990001', 'quero o sorteio');

        $this->artisan('campanhas:reprocessar --campanha='.$primeira->id)->assertSuccessful();

        $this->assertSame(1, KeywordCampaignParticipation::count());
        $this->assertSame($primeira->id, KeywordCampaignParticipation::firstOrFail()->keyword_campaign_id);
    }

    /**
     * Áudio continua fora, também no reprocessamento: o comando usa o mesmo
     * casamento do gatilho.
     */
    public function test_reprocessar_nao_inscreve_por_audio(): void
    {
        KeywordCampaign::factory()->create();

        $mensagem = $this->mensagemRecebida('5549999990001', 'placeholder');
        $mensagem->update(['body' => null, 'message_type' => 'ptt']);

        $this->artisan('campanhas:reprocessar')->assertSuccessful();

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_diagnosticar_sem_campanha_avisa_que_nada_dispara(): void
    {
        $this->artisan('campanhas:diagnosticar')
            ->expectsOutputToContain('Nenhuma campanha cadastrada')
            ->assertSuccessful();
    }

    public function test_diagnosticar_mostra_o_estado_da_campanha(): void
    {
        $campanha = KeywordCampaign::factory()->create(['name' => 'Sorteio de cursos']);
        KeywordCampaignParticipation::factory()->count(2)->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignCoupon::factory()->count(3)->create(['keyword_campaign_id' => $campanha->id]);

        $this->artisan('campanhas:diagnosticar')
            ->expectsOutputToContain('Sorteio de cursos')
            ->expectsOutputToContain('inscritos: 2')
            ->expectsOutputToContain('a conferir: 2')
            ->expectsOutputToContain('cupons: 3 disponíveis')
            ->assertSuccessful();
    }

    /**
     * Vigente e sem ninguém é o sintoma de divulgação que não saiu, ou de
     * palavra que ninguém escreve do jeito que foi cadastrada.
     */
    public function test_diagnosticar_avisa_campanha_vigente_e_vazia(): void
    {
        KeywordCampaign::factory()->create();

        $this->artisan('campanhas:diagnosticar')
            ->expectsOutputToContain('vigente e sem nenhuma inscrição')
            ->assertSuccessful();
    }

    public function test_quase_casamentos_encontra_o_plural_e_nao_inscreve_ninguem(): void
    {
        KeywordCampaign::factory()->create();
        $this->mensagemRecebida('5549999990001', 'vi os sorteios de vocês');

        $this->artisan('campanhas:quase-casamentos')
            ->expectsOutputToContain('a uma letra de distância')
            ->assertSuccessful();

        $this->assertSame(0, KeywordCampaignParticipation::count());
    }

    public function test_quase_casamentos_ignora_quem_casou_de_verdade(): void
    {
        KeywordCampaign::factory()->create();
        $this->mensagemRecebida('5549999990001', 'quero o sorteio');

        $this->artisan('campanhas:quase-casamentos')
            ->expectsOutputToContain('nenhum quase-casamento')
            ->assertSuccessful();
    }

    /**
     * Nenhum dos três comandos entra no agendador.
     *
     * Existem no projeto dois comandos criados e nunca agendados, que portanto
     * nunca rodaram. Este teste não impede agendar: impede agendar por
     * distração, sem a justificativa que `routes/console.php` exige.
     */
    public function test_nenhum_comando_da_etapa_entra_no_agendador_sem_justificativa(): void
    {
        $agendados = collect(app(Schedule::class)->events())
            ->map(fn ($evento): string => (string) $evento->command);

        foreach (['campanhas:reprocessar', 'campanhas:diagnosticar', 'campanhas:quase-casamentos'] as $comando) {
            $this->assertFalse(
                $agendados->contains(fn (string $linha): bool => str_contains($linha, $comando)),
                "O comando {$comando} foi agendado sem justificativa escrita em routes/console.php.",
            );
        }
    }
}
