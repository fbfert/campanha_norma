<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Analytics\ResponseAgendaService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Já respondi", detectado sem depender da disciplina de ninguém.
 *
 * Se a candidata gravar o áudio na mesma conta pareada, ele chega pela
 * sincronização como saída com mídia e a fila se marca sozinha. O que estes
 * testes protegem são as bordas: uma saída de texto não marca, uma saída
 * anterior ao insight não marca, e uma saída fora da janela não marca — porque
 * marcar errado esconde uma pessoa que ninguém respondeu.
 */
class RespostaJaEnviadaTest extends TestCase
{
    use RefreshDatabase;

    private ResponseAgendaService $pauta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $this->seed(SystemSettingSeeder::class);

        $this->pauta = app(ResponseAgendaService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_saida_com_midia_depois_do_insight_marca_como_respondida(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: true, quando: now()->addHour());

        $linha = $this->linha();

        $this->assertTrue($linha['answered']);
        $this->assertSame('sincronizacao', $linha['answered_source']); // ortografia:ignorar - chave de dado, que é identificador
    }

    /**
     * A marcação manual é a afirmação de uma pessoa, e vence a detecção. Ela
     * existe para quem responde de outro número, onde nada é detectável.
     */
    public function test_a_marcacao_manual_tem_precedencia(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: true, quando: now()->addHour());

        $insight->update(['answered_at' => now()->addHours(2), 'answered_by' => User::factory()->create()->id]);

        $this->assertSame('manual', $this->linha()['answered_source']);
    }

    /**
     * Saída de texto não é a resposta que esta fila espera. A candidata
     * responde por áudio, e um "ok" digitado no meio da conversa marcaria como
     * respondida uma pessoa que continua esperando.
     */
    public function test_saida_de_texto_sem_midia_nao_marca(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: false, quando: now()->addHour());

        $this->assertFalse($this->linha()['answered']);
    }

    public function test_saida_anterior_ao_insight_nao_marca(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: true, quando: now()->subDay());

        $this->assertFalse($this->linha()['answered']);
    }

    /**
     * Fora da janela é outra conversa. Um áudio mandado quarenta dias depois
     * responde a outra coisa, e tratá-lo como resposta apagaria a pendência.
     */
    public function test_saida_fora_da_janela_nao_marca(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: true, quando: now()->addDays(45));

        $this->assertFalse($this->linha()['answered']);
    }

    public function test_a_janela_vem_da_configuracao(): void
    {
        $insight = $this->insight();
        $this->saida($insight, comMidia: true, quando: now()->addDays(45));

        SystemSetting::where('key', 'pauta.answered_lookback_days')->update(['value' => '60']);
        app(SystemSettingService::class)->forget('pauta.answered_lookback_days');

        $this->assertTrue($this->linha()['answered']);
    }

    /**
     * Saída com mídia de outra conversa não marca esta. Sem o recorte por
     * conversa, um áudio qualquer marcaria a fila inteira.
     */
    public function test_saida_de_outra_conversa_nao_marca(): void
    {
        $insight = $this->insight();
        $outro = $this->insight();
        $this->saida($outro, comMidia: true, quando: now()->addHour());

        $linhas = collect($this->pauta->queue($this->de(), $this->ate()))->keyBy('insight_id');

        $this->assertFalse($linhas[$insight->id]['answered']);
        $this->assertTrue($linhas[$outro->id]['answered']);
    }

    /** @return array<string, mixed> */
    private function linha(): array
    {
        return $this->pauta->queue($this->de(), $this->ate())[0];
    }

    private function de(): Carbon
    {
        return Carbon::parse('2026-08-01')->startOfDay();
    }

    private function ate(): Carbon
    {
        return Carbon::parse('2026-08-31')->endOfDay();
    }

    private function insight(): ConversationInsight
    {
        $contato = Contact::factory()->create();
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'body' => 'O posto de saúde abre tarde demais.',
        ]);

        return ConversationInsight::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'source_message_id' => $mensagem->id,
        ]);
    }

    private function saida(ConversationInsight $insight, bool $comMidia, Carbon $quando): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $insight->conversation_id,
            'contact_id' => $insight->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'has_media' => $comMidia,
            'message_type' => $comMidia ? 'audio' : 'text',
            'sent_at' => $quando,
        ]);
    }
}
