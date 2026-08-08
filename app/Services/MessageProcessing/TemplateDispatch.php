<?php

namespace App\Services\MessageProcessing;

use App\Contracts\SendsTemplates;
use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\SendResult;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Services\Placeholders\PlaceholderCatalogService;

/**
 * Decide entre texto livre e template aprovado, e monta as variáveis.
 *
 * No WhatsApp Web toda mensagem é livre: o lote manda o texto já renderizado e
 * pronto. Na API oficial, a abordagem de campanha acontece fora da janela
 * aberta pela pessoa, e ali só sai template previamente aprovado pela Meta.
 *
 * A diferença não é de formato, é de autoria: o texto deixa de ser escrito na
 * tela e passa a depender de aprovação, e o que viaja não é a frase pronta —
 * são as variáveis, em ordem, para a Meta encaixar no template dela.
 *
 * A ordem vem de `placeholders_snapshot`, congelado quando o lote foi
 * preparado. Recalcular a partir do texto atual do modelo arriscaria mandar
 * cidade onde o template espera nome, e ninguém perceberia até alguém ler a
 * conversa.
 */
class TemplateDispatch
{
    public function __construct(private readonly PlaceholderCatalogService $placeholders) {}

    /**
     * Envia a abordagem do lote pelo caminho que o provedor exige.
     */
    public function send(WhatsAppProvider $provider, MessageBatchRecipient $recipient, string $phone): SendResult
    {
        $batch = $recipient->batch;

        if (! $provider instanceof SendsTemplates) {
            return $provider->sendMessage($phone, (string) $recipient->rendered_message, (string) $recipient->request_id);
        }

        $template = $this->templateName($batch);

        if ($template === '') {
            /*
             | Sem template não há como abrir conversa nesta API, e mandar o
             | texto livre seria recusado pela Meta com erro genérico. Recusar
             | aqui diz o que falta e onde configurar.
             */
            throw new WhatsAppServiceException(
                'TEMPLATE_NOT_CONFIGURED',
                'Este provedor exige template aprovado para abrir conversa, e nenhum está configurado para o lote.',
            );
        }

        return $provider->sendTemplate(
            $phone,
            $template,
            $this->parameters($recipient),
            (string) $recipient->request_id,
            (string) config('whatsapp.meta.invite_language', 'pt_BR'),
        );
    }

    /**
     * Variáveis do template, na ordem em que o lote as congelou.
     *
     * @return array<int, string>
     */
    public function parameters(MessageBatchRecipient $recipient): array
    {
        $contato = $recipient->contact;
        $ordem = (array) ($recipient->batch?->placeholders_snapshot ?? []);

        return array_map(function (string $nome) use ($recipient, $contato): string {
            /*
             | O instantâneo do destinatário vem primeiro, e o cadastro atual é
             | só o reforço.
             |
             | O lote foi preparado com um estado do contato, e é esse estado
             | que a pessoa vai ver. Buscar o valor de hoje faria a mensagem
             | dizer "São Cristóvão do Sul" para quem foi selecionada como de
             | Ponte Alta — foi exatamente essa correção que fizemos hoje numa
             | conversa.
             */
            $doInstantaneo = match ($nome) {
                PlaceholderCatalogService::NAME => $recipient->contact_name_snapshot,
                PlaceholderCatalogService::FIRST_NAME => $recipient->contact_first_name_snapshot,
                PlaceholderCatalogService::CITY => $recipient->contact_city_snapshot,
                PlaceholderCatalogService::STATE => $recipient->contact_state_snapshot,
                PlaceholderCatalogService::COUNTRY => $recipient->contact_country_snapshot,
                PlaceholderCatalogService::PHONE => $recipient->contact_phone_snapshot,
                default => null,
            };

            $valor = $doInstantaneo ?: ($contato ? $this->placeholders->value($contato, $nome) : null);

            return trim((string) $valor);
        }, array_values($ordem));
    }

    /**
     * Template do lote, ou o padrão da configuração.
     *
     * Por lote porque campanhas diferentes abrem conversa de formas diferentes,
     * e cada texto é um template separado aprovado pela Meta — inclusive os
     * textos que hoje são sorteados entre si.
     */
    private function templateName(?MessageBatch $batch): string
    {
        return trim((string) ($batch?->meta_template_name ?: config('whatsapp.meta.invite_template', '')));
    }
}
