<?php

namespace App\Enums;

enum ContactSource: string
{
    case Manual = 'manual';
    case Importacao = 'importacao';
    case Formulario = 'formulario';
    case Evento = 'evento';
    case Indicacao = 'indicacao';
    case ListaExistente = 'lista_existente';

    /**
     * A pessoa escreveu para nós antes de existir no cadastro.
     *
     * Separada de `manual` e de `importacao` porque a diferença importa em
     * relatório e em consentimento: este contato não foi comprado nem
     * digitado, ele procurou a gente.
     */
    case Recebido = 'recebido';

    /**
     * A pessoa escreveu uma palavra-chave de campanha.
     *
     * Separada de `recebido` porque o consentimento é outro. Quem manda "bom
     * dia" não consentiu com nada, e o atendimento de entrada grava
     * `not_informed`. Quem escreve uma palavra que só existe no material da
     * campanha fez um ato inequívoco e específico — e específico é a palavra
     * importante: consentiu em participar da campanha, não em receber disparo.
     *
     * É essa distinção que a barreira de finalidade em `ContactSelectionService`
     * aplica, deixando estes contatos fora da seleção padrão de lote.
     */
    case Gatilho = 'gatilho';

    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Importacao => 'Importação',
            self::Formulario => 'Formulário',
            self::Evento => 'Evento',
            self::Indicacao => 'Indicação',
            self::ListaExistente => 'Lista existente',
            self::Recebido => 'Mensagem recebida',
            self::Gatilho => 'Palavra-chave de campanha',
            self::Outro => 'Outro',
        };
    }
}
