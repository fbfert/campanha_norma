<?php

namespace Tests\Feature;

use App\Contracts\SendsTemplates;
use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\SendResult;
use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Services\MessageProcessing\TemplateDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A abordagem de lote sai por template quando o provedor exige.
 *
 * No WhatsApp Web toda mensagem é livre, e o lote manda o texto já renderizado.
 * Na API oficial a abordagem acontece fora da janela aberta pela pessoa, e ali
 * só sai template previamente aprovado: o que viaja não é a frase pronta, são
 * as variáveis em ordem.
 */
class EnvioDeLotePorTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_provedor_de_texto_livre_manda_a_frase_pronta(): void
    {
        $destinatario = $this->destinatario();
        $provedor = $this->provedorLivre();

        app(TemplateDispatch::class)->send($provedor, $destinatario, '5549991613378');

        $this->assertSame('Fabielle aqui é o Prof Felipe.', $provedor->textoEnviado);
        $this->assertNull($provedor->templateEnviado);
    }

    public function test_provedor_de_template_manda_as_variaveis_em_ordem(): void
    {
        Config::set('whatsapp.meta.invite_template', 'convite_pesquisa');

        $destinatario = $this->destinatario();
        $provedor = $this->provedorDeTemplate();

        app(TemplateDispatch::class)->send($provedor, $destinatario, '5549991613378');

        $this->assertSame('convite_pesquisa', $provedor->templateEnviado);
        $this->assertSame(['Fabielle', 'PONTE ALTA'], $provedor->variaveis);
    }

    /**
     * O template do lote vence o padrão da configuração: campanhas diferentes
     * abrem conversa de formas diferentes, e cada texto é um template separado.
     */
    public function test_o_template_do_lote_vence_o_padrao(): void
    {
        Config::set('whatsapp.meta.invite_template', 'padrao');

        $destinatario = $this->destinatario(['meta_template_name' => 'convite_ponte_alta']);
        $provedor = $this->provedorDeTemplate();

        app(TemplateDispatch::class)->send($provedor, $destinatario, '5549991613378');

        $this->assertSame('convite_ponte_alta', $provedor->templateEnviado);
    }

    /**
     * Sem template não há como abrir conversa nesta API, e mandar o texto livre
     * seria recusado pela Meta com erro genérico. Recusar aqui diz o que falta.
     */
    public function test_sem_template_configurado_o_envio_e_recusado(): void
    {
        Config::set('whatsapp.meta.invite_template', '');

        $this->expectException(WhatsAppServiceException::class);

        app(TemplateDispatch::class)->send($this->provedorDeTemplate(), $this->destinatario(), '5549991613378');
    }

    /**
     * O instantâneo do lote vence o cadastro atual.
     *
     * O lote foi preparado com um estado do contato, e é esse estado que a
     * pessoa vai ver. Buscar o valor de hoje faria a mensagem dizer a cidade
     * nova para quem foi selecionada pela antiga — correção que fizemos numa
     * conversa real esta semana.
     */
    public function test_a_variavel_vem_do_instantaneo_e_nao_do_cadastro_de_hoje(): void
    {
        Config::set('whatsapp.meta.invite_template', 'convite');

        $destinatario = $this->destinatario();
        $destinatario->contact->forceFill(['city' => 'SÃO CRISTÓVÃO DO SUL'])->save();

        app(TemplateDispatch::class)->send($provedor = $this->provedorDeTemplate(), $destinatario->fresh(), '5549991613378');

        $this->assertSame(['Fabielle', 'PONTE ALTA'], $provedor->variaveis);
    }

    private function destinatario(array $loteExtra = []): MessageBatchRecipient
    {
        $contato = Contact::factory()->create([
            'first_name' => 'Fabielle',
            'city' => 'PONTE ALTA',
            'phone_normalized' => '5549991613378',
        ]);

        $lote = MessageBatch::factory()->create(array_merge([
            'placeholders_snapshot' => ['primeiro_nome', 'cidade'],
        ], $loteExtra));

        return MessageBatchRecipient::factory()->create([
            'message_batch_id' => $lote->id,
            'contact_id' => $contato->id,
            'contact_first_name_snapshot' => 'Fabielle',
            'contact_city_snapshot' => 'PONTE ALTA',
            'rendered_message' => 'Fabielle aqui é o Prof Felipe.',
            'request_id' => 'req-1',
        ]);
    }

    private function provedorLivre(): object
    {
        return new class implements WhatsAppProvider
        {
            public ?string $textoEnviado = null;

            public ?string $templateEnviado = null;

            public function getStatus(): ConnectionStatus
            {
                return new ConnectionStatus(WhatsAppConnectionStatus::Connected);
            }

            public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
            {
                return $this->sendMessage($phone, $message, $requestId);
            }

            public function sendMessage(string $phone, string $message, string $requestId): SendResult
            {
                $this->textoEnviado = $message;

                return new SendResult(requestId: $requestId, status: 'sent');
            }
        };
    }

    private function provedorDeTemplate(): object
    {
        return new class implements SendsTemplates, WhatsAppProvider
        {
            public ?string $textoEnviado = null;

            public ?string $templateEnviado = null;

            /** @var array<int, string> */
            public array $variaveis = [];

            public function getStatus(): ConnectionStatus
            {
                return new ConnectionStatus(WhatsAppConnectionStatus::Connected);
            }

            public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
            {
                return $this->sendMessage($phone, $message, $requestId);
            }

            public function sendMessage(string $phone, string $message, string $requestId): SendResult
            {
                $this->textoEnviado = $message;

                return new SendResult(requestId: $requestId, status: 'sent');
            }

            public function sendTemplate(string $phone, string $template, array $parameters, string $requestId, string $language = 'pt_BR'): SendResult
            {
                $this->templateEnviado = $template;
                $this->variaveis = $parameters;

                return new SendResult(requestId: $requestId, status: 'sent');
            }
        };
    }
}
