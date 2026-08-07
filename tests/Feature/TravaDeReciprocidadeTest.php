<?php

namespace Tests\Feature;

use App\Enums\MessageRecipientProcessingStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\SendingSetting;
use App\Services\MessageProcessing\ReciprocityGuard;
use Database\Seeders\SendingSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trava de reciprocidade: não abordar mais gente do que está respondendo.
 *
 * Os limites que existiam eram todos de ritmo — por minuto, por hora, por dia.
 * Nenhum olhava para o outro lado. Era possível abordar mil pessoas em ritmo
 * impecável sem que uma única respondesse, e nada no sistema notava: os
 * contadores mostravam sucesso, porque entregar a mensagem é sucesso.
 *
 * Esta trava mede a conversa, não a entrega. O que a destrava não é o relógio:
 * é alguém do outro lado responder.
 */
class TravaDeReciprocidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SendingSettingSeeder::class);
    }

    public function test_abaixo_do_teto_o_envio_continua(): void
    {
        $this->abordados(semResposta: 9);

        $veredito = app(ReciprocityGuard::class)->check($this->config(teto: 10));

        $this->assertTrue($veredito['allowed']);
        $this->assertSame(9, $veredito['waiting']);
    }

    public function test_no_teto_o_envio_para(): void
    {
        $this->abordados(semResposta: 10);

        $veredito = app(ReciprocityGuard::class)->check($this->config(teto: 10));

        $this->assertFalse($veredito['allowed']);
        $this->assertStringContainsString('10 pessoas abordadas ainda não responderam', (string) $veredito['reason']);
    }

    /**
     * O que destrava é alguém responder. Uma resposta tira aquela pessoa da
     * conta, e o envio volta sem ninguém precisar liberar nada.
     */
    public function test_uma_resposta_destrava_o_envio(): void
    {
        $contatos = $this->abordados(semResposta: 10);
        $guarda = app(ReciprocityGuard::class);

        $this->assertFalse($guarda->check($this->config(teto: 10))['allowed']);

        $contatos->first()->forceFill(['has_replied' => true])->save();

        $this->assertTrue($guarda->check($this->config(teto: 10))['allowed']);
    }

    /**
     * A conta é de pessoas, não de mensagens.
     *
     * A mesma pessoa aparece em campanhas diferentes — o banco impede repetir
     * dentro de um lote, mas nada impede entre lotes. Quem foi abordado doze
     * vezes e nunca respondeu conta uma: mandar mais para a mesma pessoa não
     * aumenta o alcance, e contar por mensagem trancaria o envio sem mais
     * ninguém ter sido procurado.
     */
    public function test_a_conta_e_de_pessoas_e_nao_de_mensagens(): void
    {
        $contato = Contact::factory()->create(['has_replied' => false]);

        foreach (range(1, 12) as $ignorado) {
            MessageBatchRecipient::factory()->create([
                'message_batch_id' => MessageBatch::factory()->create()->id,
                'contact_id' => $contato->id,
                'processing_status' => MessageRecipientProcessingStatus::Sent,
            ]);
        }

        $veredito = app(ReciprocityGuard::class)->check($this->config(teto: 10));

        $this->assertTrue($veredito['allowed']);
        $this->assertSame(1, $veredito['waiting']);
    }

    /**
     * Zero é valor legítimo e desliga a trava: é o comportamento de quem já
     * enviava antes de ela existir.
     */
    public function test_teto_zero_desliga_a_trava(): void
    {
        $this->abordados(semResposta: 50);

        $this->assertTrue(app(ReciprocityGuard::class)->check($this->config(teto: 0))['allowed']);
    }

    /**
     * Quem ainda não foi abordado não conta. A trava mede silêncio depois de
     * uma mensagem nossa, não a base inteira de contatos.
     */
    public function test_contato_nunca_abordado_nao_entra_na_conta(): void
    {
        Contact::factory()->count(30)->create(['has_replied' => false]);

        $this->assertSame(0, app(ReciprocityGuard::class)->silentContacts());
    }


    /**
     * A trava precisa segurar no processamento real, e não só devolver `false`.
     *
     * O destinatário fica numa situação própria de espera, e o lote não é
     * pausado: assim, quando alguém responder, a próxima tentativa passa
     * sozinha, sem ninguém precisar reiniciar nada.
     */
    public function test_destinatario_fica_aguardando_e_o_lote_continua_ativo(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->abordados(semResposta: 10);
        $this->config(teto: 10);

        \App\Models\SendingSetting::query()->first()->update([
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'minimum_interval_seconds' => 0,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '127.0.0.1:3100/api/status' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
        ]);

        $contato = Contact::factory()->create(['has_replied' => false]);
        $lote = MessageBatch::factory()->create([
            'status' => \App\Enums\MessageBatchStatus::Processing,
            'prepared_at' => now(),
            'processing_version' => 1,
        ]);
        $destinatario = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $lote->id,
            'contact_id' => $contato->id,
            'eligibility_status' => 'eligible',
            'processing_status' => MessageRecipientProcessingStatus::Queued,
            'contact_phone_snapshot' => $contato->phone,
            'rendered_message' => 'Oi Contato.',
            'processing_version' => 1,
        ]);

        app(\App\Services\MessageProcessing\RecipientProcessingService::class)
            ->process($destinatario, 1);

        $this->assertSame(
            MessageRecipientProcessingStatus::WaitingReciprocity,
            $destinatario->fresh()->processing_status,
        );

        $this->assertSame(
            \App\Enums\MessageBatchStatus::Processing,
            $lote->fresh()->status,
            'Pausar o lote exigiria reinício à mão; a espera destrava sozinha.',
        );
    }

    /** @return \Illuminate\Support\Collection<int, Contact> */
    private function abordados(int $semResposta)
    {
        $lote = MessageBatch::factory()->create();

        return Contact::factory()->count($semResposta)->create(['has_replied' => false])
            ->each(function (Contact $contato) use ($lote): void {
                MessageBatchRecipient::factory()->create([
                    'message_batch_id' => $lote->id,
                    'contact_id' => $contato->id,
                    'processing_status' => MessageRecipientProcessingStatus::Sent,
                ]);
            });
    }

    private function config(int $teto): SendingSetting
    {
        $settings = SendingSetting::query()->firstOrFail();
        $settings->forceFill(['unanswered_lock_threshold' => $teto])->save();

        return $settings->fresh();
    }
}
