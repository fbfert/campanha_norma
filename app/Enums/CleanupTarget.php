<?php

namespace App\Enums;

/**
 * As famílias de participação que a Limpeza sabe remover.
 *
 * Cada caso e um agrupamento de tela, não uma tabela: quem opera pensa em "a
 * participação dele na campanha", não em `keyword_campaign_participations` mais
 * `keyword_campaign_coupons`. O mapa de caso para tabela mora no
 * `CleanupService`, que e o único lugar que precisa saber disso.
 *
 * O contato em si não aparece aqui de propósito. A Limpeza tira o que a pessoa
 * participou; apagar o cadastro continua sendo trabalho da tela de Contatos,
 * que já pergunta as coisas certas antes de fazer isso.
 */
enum CleanupTarget: string
{
    /**
     * Inscrição em campanha por palavra-chave, com os cupons que ela recebeu.
     *
     * Cupom vai junto porque cupom atribuído a inscrição removida não e cupom
     * entregue nem cupom disponível: seria uma linha órfã contando como prêmio
     * distribuído para ninguém.
     */
    case Campanhas = 'campanhas';

    /**
     * Conversas, mensagens, estado de fluxo e o que a IA interpretou dali.
     */
    case Conversas = 'conversas';

    /**
     * Presença em lote de envio, mais as mensagens de teste enviadas ao número.
     */
    case Envios = 'envios';

    /**
     * Etiquetas, histórico do contato e o vínculo com a linha de importação.
     */
    case Cadastro = 'cadastro';

    public function label(): string
    {
        return match ($this) {
            self::Campanhas => 'Campanhas por palavra-chave',
            self::Conversas => 'Conversas, pesquisa e IA',
            self::Envios => 'Envios e mensagens de teste',
            self::Cadastro => 'Cadastro: etiquetas, histórico e importação',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Campanhas => 'Inscrições em campanhas por palavra-chave e os cupons atribuídos a elas.',
            self::Conversas => 'Conversas, mensagens trocadas, estado do fluxo conversacional e insights gerados pela IA.',
            self::Envios => 'Presença em lotes e campanhas de envio, e mensagens de teste enviadas para o número.',
            self::Cadastro => 'Etiquetas aplicadas, histórico do contato e o vínculo com a linha da planilha importada.',
        };
    }
}
