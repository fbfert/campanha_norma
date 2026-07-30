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
            self::Outro => 'Outro',
        };
    }
}
