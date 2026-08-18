<?php

namespace App\Enums;

enum KeywordCampaignStatus: string
{
    case Rascunho = 'rascunho';
    case Ativa = 'ativa';
    case Encerrada = 'encerrada';

    /**
     * Lista fechada para o sorteio.
     *
     * Separada de `encerrada` porque as duas param de aceitar inscrição por
     * razões diferentes: encerrada é o fim da vigência, e congelada é a
     * decisão de que a lista que vai ao sorteio é esta. Uma campanha encerrada
     * ainda pode receber conferência e correção; uma congelada, não.
     */
    case Congelada = 'congelada';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Ativa => 'Ativa',
            self::Encerrada => 'Encerrada',
            self::Congelada => 'Congelada',
        };
    }
}
