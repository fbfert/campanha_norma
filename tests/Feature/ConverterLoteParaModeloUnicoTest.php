<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Converte um lote preparado do sorteio para um texto só.
 *
 * O comando existe para os lotes que ficaram prontos antes de o sorteio sair da
 * criação — os lotes 14 e 15, que somavam 247 pessoas e nenhum envio. O motivo
 * da mudança está em `LoteUsaUmModeloSoTest`.
 *
 * A trava que mais importa aqui é a recusa: lote em que alguém já recebeu não
 * pode ser reescrito, porque a mensagem gravada é o registro do que a pessoa
 * leu de verdade.
 */
class ConverterLoteParaModeloUnicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_lote_passa_a_ter_um_texto_e_uma_variavel(): void
    {
        $modelo = MessageTemplate::factory()->create([
            'name' => 'Convite - Pergunta Única',
            'body' => 'Oi {primeiro_nome}, sou o prof Felipe.',
            'status' => 'active',
        ]);

        $lote = $this->loteSorteado();

        $this->artisan('message-batches:single-template', [
            'batch' => [$lote->id],
            '--template' => $modelo->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $lote->refresh();

        $this->assertFalse($lote->is_campaign);
        $this->assertNull($lote->campaign_templates_snapshot);
        $this->assertSame('Oi {primeiro_nome}, sou o prof Felipe.', $lote->message_body_snapshot);

        /*
         | Uma variável, e não a união das variáveis dos modelos sorteados.
         |
         | Era esta união que quebrava o envio por template: o lote dizia
         | [primeiro_nome, cidade] e mandava duas variáveis para um template que
         | esperava uma.
         */
        $this->assertSame(['primeiro_nome'], $lote->placeholders_snapshot);

        foreach ($lote->recipients as $destinatario) {
            $this->assertSame($modelo->id, $destinatario->message_template_id);
            $this->assertStringContainsString('sou o prof Felipe.', (string) $destinatario->rendered_message);
        }
    }

    /** Sem --aplicar o comando só mostra o que faria. */
    public function test_sem_aplicar_nada_e_gravado(): void
    {
        $modelo = MessageTemplate::factory()->create(['body' => 'Oi {primeiro_nome}.', 'status' => 'active']);
        $lote = $this->loteSorteado();

        $this->artisan('message-batches:single-template', [
            'batch' => [$lote->id],
            '--template' => $modelo->id,
        ])->assertSuccessful();

        $this->assertTrue($lote->fresh()->is_campaign);
    }

    /**
     * Lote que já enviou é recusado inteiro.
     *
     * Reescrever a mensagem de quem já recebeu apagaria o registro do que foi
     * enviado de fato, e a conversa passaria a mostrar um texto que a pessoa
     * nunca viu.
     */
    public function test_lote_que_ja_enviou_e_recusado(): void
    {
        $modelo = MessageTemplate::factory()->create(['body' => 'Oi {primeiro_nome}.', 'status' => 'active']);
        $lote = $this->loteSorteado();

        $lote->recipients()->first()->forceFill(['sent_at' => now()])->save();

        $this->artisan('message-batches:single-template', [
            'batch' => [$lote->id],
            '--template' => $modelo->id,
            '--aplicar' => true,
        ])->assertFailed();

        $lote->refresh();

        $this->assertTrue($lote->is_campaign);
        $this->assertNotNull($lote->campaign_templates_snapshot);
    }

    private function loteSorteado(): MessageBatch
    {
        $lote = MessageBatch::factory()->create([
            'is_campaign' => true,
            'message_body_snapshot' => 'CAMPANHA: modelos sorteados por destinatário - A, B',
            'placeholders_snapshot' => ['primeiro_nome', 'cidade'],
            'campaign_templates_snapshot' => [
                ['id' => 91, 'name' => 'A', 'version' => 1, 'body' => 'Oi {primeiro_nome}', 'placeholders' => ['primeiro_nome']],
                ['id' => 92, 'name' => 'B', 'version' => 1, 'body' => 'Oi {primeiro_nome} de {cidade}', 'placeholders' => ['primeiro_nome', 'cidade']],
            ],
        ]);

        foreach (Contact::factory()->count(3)->create(['city' => 'PONTE ALTA']) as $contato) {
            MessageBatchRecipient::factory()->create([
                'message_batch_id' => $lote->id,
                'contact_id' => $contato->id,
                'sent_at' => null,
                'external_message_id' => null,
            ]);
        }

        return $lote;
    }
}
