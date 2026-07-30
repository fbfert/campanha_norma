<?php

namespace App\Enums;

/**
 * Motivos de encaminhamento para revisão humana.
 *
 * Todos são decididos por regra determinística do sistema, nunca pelo modelo.
 */
enum InsightReviewReason: string
{
    case LowConfidence = 'low_confidence';
    case InvalidOutput = 'invalid_output';
    case ProviderFailure = 'provider_failure';

    // A ordem das causas detectáveis por texto e a ordem de gravidade usada na
    // detecção: a primeira que casar e a que vale.
    case Risk = 'risk';
    case Threat = 'threat';
    case SensitiveReport = 'sensitive_report';
    case NamedAccusation = 'named_accusation';
    case LegalSensitive = 'legal_sensitive';
    case PromiseRequest = 'promise_request';
    case PersonalRequest = 'personal_request';
    case IndividualUrgency = 'individual_urgency';
    case HumanRequested = 'human_requested';
    case InsultOrAbuse = 'insult_or_abuse';
    case Complaint = 'complaint';

    public function label(): string
    {
        return match ($this) {
            self::LowConfidence => 'Confiança abaixo do limite',
            self::InvalidOutput => 'Saída invalida do modelo',
            self::ProviderFailure => 'Falha do provedor de IA',
            self::SensitiveReport => 'Relato sensível ou denuncia',
            self::Threat => 'Ameaca',
            self::PersonalRequest => 'Pedido pessoal',
            self::NamedAccusation => 'Acusação nominal',
            self::LegalSensitive => 'Conteúdo juridico sensível',
            self::PromiseRequest => 'Pedido de promessa ou benefício',
            self::IndividualUrgency => 'Urgência individual',
            self::Risk => 'Situação de risco',
            self::HumanRequested => 'Pedido de atendimento humano',
            self::Complaint => 'Reclamação',
            self::InsultOrAbuse => 'Ofensa ou abuso',
        };
    }

    /**
     * Chave da lista de expressões configurável correspondente, quando o motivo
     * e detectado por texto. Motivos operacionais não tem lista.
     */
    public function settingKey(): ?string
    {
        return match ($this) {
            self::LowConfidence, self::InvalidOutput, self::ProviderFailure => null,
            default => 'ai.expressions.'.$this->value,
        };
    }

    /**
     * Motivos detectáveis por expressão no texto original do contato.
     *
     * @return array<int, self>
     */
    public static function textDetectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->settingKey() !== null
        ));
    }
}
