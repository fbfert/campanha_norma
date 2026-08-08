<?php

namespace App\Contracts;

use App\Data\WhatsApp\SendResult;

/**
 * Provedor que exige template aprovado para abrir conversa.
 *
 * Na API oficial da Meta, mensagem livre só sai dentro da janela aberta pela
 * própria pessoa. Fora dela — que é o caso de toda abordagem de campanha — vale
 * apenas template submetido e aprovado previamente.
 *
 * Isso muda regra de negócio, não só código: o texto do convite deixa de ser
 * editável na tela e passa a depender de aprovação da Meta, e os placeholders
 * viram variáveis numeradas, na ordem em que aparecem no template.
 *
 * O WhatsApp Web não implementa este contrato porque nele não existe essa
 * distinção: toda mensagem é livre.
 */
interface SendsTemplates
{
    /**
     * @param  string  $template  Nome do template aprovado.
     * @param  array<int, string>  $parameters  Variáveis do corpo, em ordem.
     * @param  string  $language  Código de idioma cadastrado no template.
     */
    public function sendTemplate(
        string $phone,
        string $template,
        array $parameters,
        string $requestId,
        string $language = 'pt_BR',
    ): SendResult;
}
