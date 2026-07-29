<?php

namespace App\Enums;

/**
 * Motivos de encaminhamento para revisao humana.
 *
 * Todos sao decididos por regra deterministica do sistema, nunca pelo modelo.
 */
enum InsightReviewReason: string
{
    case LowConfidence = 'low_confidence';
    case InvalidOutput = 'invalid_output';
    case ProviderFailure = 'provider_failure';

    // A ordem das causas detectaveis por texto e a ordem de gravidade usada na
    // deteccao: a primeira que casar e a que vale.
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
            self::LowConfidence => 'Confianca abaixo do limite',
            self::InvalidOutput => 'Saida invalida do modelo',
            self::ProviderFailure => 'Falha do provedor de IA',
            self::SensitiveReport => 'Relato sensivel ou denuncia',
            self::Threat => 'Ameaca',
            self::PersonalRequest => 'Pedido pessoal',
            self::NamedAccusation => 'Acusacao nominal',
            self::LegalSensitive => 'Conteudo juridico sensivel',
            self::PromiseRequest => 'Pedido de promessa ou beneficio',
            self::IndividualUrgency => 'Urgencia individual',
            self::Risk => 'Situacao de risco',
            self::HumanRequested => 'Pedido de atendimento humano',
            self::Complaint => 'Reclamacao',
            self::InsultOrAbuse => 'Ofensa ou abuso',
        };
    }

    /**
     * Chave da lista de expressoes configuravel correspondente, quando o motivo
     * e detectado por texto. Motivos operacionais nao tem lista.
     */
    public function settingKey(): ?string
    {
        return match ($this) {
            self::LowConfidence, self::InvalidOutput, self::ProviderFailure => null,
            default => 'ai.expressions.'.$this->value,
        };
    }

    /**
     * Motivos detectaveis por expressao no texto original do contato.
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
