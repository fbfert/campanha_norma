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
            self::Outro => 'Outro',
        };
    }
}
