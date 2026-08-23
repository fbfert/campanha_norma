<?php

namespace Tests\Feature;

use App\Enums\MonitoringHealthStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os dois alarmes que faltavam.
 *
 * Em 19/08/2026 três problemas aconteceram no mesmo dia e nenhum avisou. O
 * código foi para o ar exigindo uma migração que não tinha rodado, e o sistema
 * respondeu 500 por três horas enquanto o monitoramento dizia que estava tudo
 * bem. Uma pessoa escreveu a palavra-chave de uma campanha vigente e ficou de
 * fora da lista, sem erro e sem log.
 *
 * Os dois eram calculáveis o tempo todo. O que faltava era alguém calcular.
 */
class MonitoramentoAvisaOQueEraSilenciosoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_banco_no_ponto_do_codigo_fica_saudavel(): void
    {
        $this->assertSame(
            MonitoringHealthStatus::Healthy,
            app(MonitoringService::class)->migrations()['status'],
        );
    }

    /**
     * O caso que derrubou o sistema: migração pendente é crítico, não aviso.
     *
     * Apagar a linha do histórico faz a migração voltar a contar como não
     * aplicada, que é exatamente o estado de quem subiu código sem migrar.
     */
    public function test_migracao_pendente_e_critica(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $resultado = app(MonitoringService::class)->migrations();

        $this->assertSame(MonitoringHealthStatus::Critical, $resultado['status']);
        $this->assertStringContainsString('artisan migrate', $resultado['message']);
        $this->assertCount(1, $resultado['details']['pendentes']);
    }

    public function test_sem_campanha_vigente_nao_ha_o_que_conferir(): void
    {
        $this->assertSame(
            MonitoringHealthStatus::Healthy,
            app(MonitoringService::class)->campaignEnrollments()['status'],
        );
    }

    /**
     * O caso do Renan: escreveu a palavra, entrou no sistema, ficou fora da
     * lista. Calculável desde sempre; ninguém calculava.
     */
    public function test_quem_escreveu_a_palavra_e_nao_esta_na_lista_vira_aviso(): void
    {
        $this->mensagemComPalavra();

        $resultado = app(MonitoringService::class)->campaignEnrollments();

        $this->assertSame(MonitoringHealthStatus::Warning, $resultado['status']);
        $this->assertStringContainsString('campanhas:reprocessar', $resultado['message']);
        $this->assertSame(['promoção' => 1], $resultado['details']['por_campanha']);
    }

    public function test_quem_ja_esta_inscrito_nao_vira_aviso(): void
    {
        [$campanha, $mensagem] = $this->mensagemComPalavra();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $mensagem->contact_id,
            'conversation_message_id' => $mensagem->id,
        ]);

        $this->assertSame(
            MonitoringHealthStatus::Healthy,
            app(MonitoringService::class)->campaignEnrollments()['status'],
        );
    }

    /**
     * @return array{0: KeywordCampaign, 1: ConversationMessage}
     */
    private function mensagemComPalavra(): array
    {
        $campanha = KeywordCampaign::factory()->create([
            'name' => 'promoção',
            'status' => 'ativa',
            'keywords' => ['batata'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $contato = Contact::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);

        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => 'batata',
            'received_at' => now(),
        ]);

        return [$campanha, $mensagem];
    }
}
