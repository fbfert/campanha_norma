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
            self::Classify => 'Classificação',
            self::ExtractInsight => 'Extração de insight',
            self::GenerateReply => 'Geração de resposta',
        };
    }
}
