<?php

namespace App\Enums;

enum AiRunPurpose: string
{
    case Classify = 'classify';
    case ExtractInsight = 'extract_insight';
    case GenerateReply = 'generate_reply';

    // Transcrição de áudio recebido. Entra aqui para que o custo apareça no
    // mesmo lugar dos demais: quem olha o painel quer saber quanto a IA gastou,
    // não quanto cada endpoint gastou.
    case TranscribeAudio = 'transcribe_audio';

    // Descrição de imagem recebida. Mesma razão da transcrição: é a máquina
    // lendo uma mídia que a pessoa mandou, e o custo aparece junto do resto.
    case DescribeImage = 'describe_image';

    public function label(): string
    {
        return match ($this) {
            self::Classify => 'Classificação',
            self::ExtractInsight => 'Extração de insight',
            self::GenerateReply => 'Geração de resposta',
            self::TranscribeAudio => 'Transcrição de áudio',
            self::DescribeImage => 'Descrição de imagem',
        };
    }
}
