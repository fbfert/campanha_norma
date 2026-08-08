<?php

namespace Tests\Feature;

use App\Contracts\PairsBySession;
use App\Contracts\ReadsConversationHistory;
use App\Contracts\SendsTemplates;
use App\Contracts\WhatsAppProvider;
use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Services\WhatsApp\MetaCloudProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Provedor da API oficial da Meta.
 *
 * Os corpos aqui são os formatos que a Cloud API usa de verdade. Nenhuma
 * chamada sai da máquina: `Http::fake` responde no lugar dela, e
 * `preventStrayRequests` no TestCase garante que nada escape.
 *
 * A versão da API e os nomes de campo precisam ser conferidos na documentação
 * vigente antes de valer em produção — a Meta versiona e depreca, e um teste
 * que passa contra um formato antigo não prova nada sobre o atual.
 */
class ProvedorDaMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('whatsapp.meta.base_url', 'https://graph.facebook.com');
        Config::set('whatsapp.meta.api_version', 'v21.0');
        Config::set('whatsapp.meta.phone_number_id', '123456789');
        Config::set('whatsapp.meta.token', 'token-de-teste');
    }

    /**
     * Ela não pareia sessão nem lê histórico, e isso precisa ser verdade no
     * tipo — não uma promessa em comentário.
     */
    public function test_declara_so_o_que_sabe_fazer(): void
    {
        $provider = app(MetaCloudProvider::class);

        $this->assertInstanceOf(WhatsAppProvider::class, $provider);
        $this->assertInstanceOf(SendsTemplates::class, $provider);
        $this->assertNotInstanceOf(PairsBySession::class, $provider);
        $this->assertNotInstanceOf(ReadsConversationHistory::class, $provider);
    }

    public function test_envia_texto_livre_no_formato_da_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '5549991613378', 'wa_id' => '5549991613378']],
            'messages' => [['id' => 'wamid.HBgNNTU0OTk5MTYxMzM3OBUCABEYEjcxQTM3']],
        ], 200)]);

        $resultado = app(MetaCloudProvider::class)->sendMessage('55 (49) 99161-3378', 'Oi!', 'req-1');

        $this->assertSame('sent', $resultado->status);
        $this->assertSame('wamid.HBgNNTU0OTk5MTYxMzM3OBUCABEYEjcxQTM3', $resultado->externalMessageId);

        Http::assertSent(function (Request $request): bool {
            $corpo = $request->data();

            return str_contains($request->url(), '/v21.0/123456789/messages')
                && $corpo['type'] === 'text'
                && $corpo['text']['body'] === 'Oi!'
                // Máscara do cadastro é recusada com erro genérico, caro de
                // diagnosticar depois.
                && $corpo['to'] === '5549991613378';
        });
    }

    public function test_envia_template_com_variaveis_em_ordem(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.template']],
        ], 200)]);

        app(MetaCloudProvider::class)->sendTemplate('5549991613378', 'convite_pesquisa', ['Fabielle', 'Ponte Alta'], 'req-2');

        Http::assertSent(function (Request $request): bool {
            $t = $request->data()['template'];

            return $request->data()['type'] === 'template'
                && $t['name'] === 'convite_pesquisa'
                && $t['language']['code'] === 'pt_BR'
                && $t['components'][0]['parameters'][0]['text'] === 'Fabielle'
                && $t['components'][0]['parameters'][1]['text'] === 'Ponte Alta';
        });
    }

    /**
     * Template sem variável não manda `components` vazio: a Meta recusa o
     * componente sem parâmetro.
     */
    public function test_template_sem_variavel_nao_manda_componente_vazio(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        app(MetaCloudProvider::class)->sendTemplate('5549991613378', 'aviso', [], 'req-3');

        Http::assertSent(fn (Request $r): bool => ! array_key_exists('components', $r->data()['template']));
    }

    /**
     * O código da Meta é o que diz o que fazer: 190 é token vencido, 132001 é
     * template inexistente. Guardar só "falhou" obrigaria a abrir log em cada
     * caso.
     */
    public function test_o_erro_preserva_o_codigo_da_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Template name does not exist in the translation',
                'type' => 'OAuthException',
                'code' => 132001,
                'fbtrace_id' => 'AbCdEf123',
            ],
        ], 400)]);

        try {
            app(MetaCloudProvider::class)->sendTemplate('5549991613378', 'inexistente', [], 'req-4');
            $this->fail('Deveria ter lançado.');
        } catch (WhatsAppServiceException $excecao) {
            $this->assertSame('META_132001', $excecao->errorCode);
            $this->assertStringContainsString('Template name does not exist', $excecao->getMessage());
            $this->assertSame('AbCdEf123', $excecao->context['fbtrace_id']);
        }
    }

    /**
     * Número sem código de país não falha na Meta: ela entrega para outra
     * pessoa, em outro país, e ninguém descobre. Recusar custa uma mensagem
     * não enviada; não recusar custa uma mensagem enviada a um desconhecido.
     */
    public function test_telefone_sem_codigo_de_pais_e_recusado(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        try {
            app(MetaCloudProvider::class)->sendMessage('(49) 99161-3378', 'Oi', 'req-6');
            $this->fail('Deveria ter recusado.');
        } catch (WhatsAppServiceException $excecao) {
            $this->assertSame('PHONE_WITHOUT_COUNTRY_CODE', $excecao->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_sem_credencial_o_status_avisa_em_vez_de_falhar(): void
    {
        Config::set('whatsapp.meta.token', null);

        $status = app(MetaCloudProvider::class)->getStatus();

        $this->assertSame(WhatsAppConnectionStatus::NotInitialized, $status->status);
        $this->assertSame('META_NOT_CONFIGURED', $status->errorCode);
    }

    /**
     * Enviar sem credencial precisa falhar alto: devolver "enviado" sem ter
     * enviado seria a pior forma de errar.
     */
    public function test_sem_credencial_o_envio_e_recusado(): void
    {
        Config::set('whatsapp.meta.token', null);

        $this->expectException(WhatsAppServiceException::class);

        app(MetaCloudProvider::class)->sendMessage('5549991613378', 'Oi', 'req-5');
    }

    public function test_o_status_traz_o_numero_e_o_nome_verificado(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'display_phone_number' => '+55 49 9188-8242',
            'verified_name' => 'Prof Norma',
            'quality_rating' => 'GREEN',
        ], 200)]);

        $status = app(MetaCloudProvider::class)->getStatus();

        $this->assertSame(WhatsAppConnectionStatus::Connected, $status->status);
        $this->assertSame('Prof Norma', $status->displayName);
        $this->assertSame('GREEN', $status->metadata['quality_rating']);
    }
}
