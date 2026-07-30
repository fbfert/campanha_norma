<?php

namespace App\Enums;

enum AiRunPurpose: string
{
    case Classify = 'classify';
    case ExtractInsight = 'extract_insight';
    case GenerateReply = 'generate_reply';

    public function label(): string
    {
        return match ($this) {
            self::Classify => 'Classificacao',
            self::ExtractInsight => 'Extracao de insight',
            self::GenerateReply => 'Geracao de resposta',
        };
    }
}
