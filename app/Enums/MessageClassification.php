<?php

namespace App\Enums;

/**
 * Categorias ampliadas da subetapa 9B.
 *
 * As tres primeiras coincidem com o classificador deterministico da 9A e podem
 * ser produzidas sem qualquer chamada de IA.
 */
enum MessageClassification: string
{
    case PermissionYes = 'permission_yes';
    case PermissionNo = 'permission_no';
    case OptOut = 'opt_out';
    case QuestionAnswer = 'question_answer';
    case AsksForClarification = 'asks_for_clarification';
    case AsksAboutNorma = 'asks_about_norma';
    case OffTopic = 'off_topic';
    case HumanRequested = 'human_requested';
    case Complaint = 'complaint';
    case SensitiveReport = 'sensitive_report';
    case InsultOrAbuse = 'insult_or_abuse';
    case MediaOrUnsupported = 'media_or_unsupported';
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::PermissionYes => 'Permissao concedida',
            self::PermissionNo => 'Permissao negada',
            self::OptOut => 'Pedido de nao contatar',
            self::QuestionAnswer => 'Resposta a pergunta',
            self::AsksForClarification => 'Pede esclarecimento',
            self::AsksAboutNorma => 'Pergunta sobre a Professora Norma',
            self::OffTopic => 'Fora do assunto',
            self::HumanRequested => 'Pede atendimento humano',
            self::Complaint => 'Reclamacao',
            self::SensitiveReport => 'Relato sensivel',
            self::InsultOrAbuse => 'Ofensa ou abuso',
            self::MediaOrUnsupported => 'Midia ou tipo nao suportado',
            self::Ambiguous => 'Ambigua',
        };
    }

    /**
     * Categorias que sempre exigem atendimento humano, independentemente da
     * confianca reportada pelo modelo.
     */
    public function alwaysRequiresHuman(): bool
    {
        return in_array($this, [
            self::HumanRequested,
            self::Complaint,
            self::SensitiveReport,
            self::InsultOrAbuse,
        ], true);
    }

    /**
     * Somente respostas a pergunta produzem extracao estruturada.
     */
    public function allowsExtraction(): bool
    {
        return $this === self::QuestionAnswer;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
