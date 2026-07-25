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
            self::Importacao => 'Importacao',
            self::Formulario => 'Formulario',
            self::Evento => 'Evento',
            self::Indicacao => 'Indicacao',
            self::ListaExistente => 'Lista existente',
            self::Outro => 'Outro',
        };
    }
}
