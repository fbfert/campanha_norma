<?php

namespace App\Enums;

/**
 * Motivos de encaminhamento para atendimento humano.
 *
 * Todos são decididos por regra do sistema. O campo equivalente devolvido pelo
 * modelo e tratado como sinal, nunca como autorização.
 */
enum HandoffReason: string
{
    case ExplicitRequest = 'explicit_request';
    case FactualQuestion = 'factual_question';
    case ReportOrAccusation = 'report_or_accusation';
    case Threat = 'threat';
    case IndividualHelpRequest = 'individual_help_request';
    case LegalMatter = 'legal_matter';
    case PromiseOrCommitment = 'promise_or_commitment';
    case LowConfidence = 'low_confidence';
    case HostileContent = 'hostile_content';
    case UnsupportedMedia = 'unsupported_media';
    case ContextConflict = 'context_conflict';
    case TurnLimitReached = 'turn_limit_reached';
    case RepeatedProviderFailure = 'repeated_provider_failure';
    case InvalidGeneratedText = 'invalid_generated_text';
    case UngroundedAnswer = 'ungrounded_answer';
    case InsufficientEvidence = 'insufficient_evidence';

    public function label(): string
    {
        return match ($this) {
            self::ExplicitRequest => 'Pedido explícito de atendimento humano',
            self::FactualQuestion => 'Pergunta factual sem base aprovada',
            self::ReportOrAccusation => 'Denuncia ou acusação',
            self::Threat => 'Ameaca',
            self::IndividualHelpRequest => 'Pedido de ajuda individual',
            self::LegalMatter => 'Assunto juridico',
            self::PromiseOrCommitment => 'Promessa ou compromisso',
            self::LowConfidence => 'Confiança abaixo do limite',
            self::HostileContent => 'Conteúdo hostil',
            self::UnsupportedMedia => 'Midia não suportada',
            self::ContextConflict => 'Conflito de contexto',
            self::TurnLimitReached => 'Limite de aprofundamentos atingido',
            self::RepeatedProviderFailure => 'Falha repetida do provedor',
            self::InvalidGeneratedText => 'Texto gerado reprovado na validação',
            self::UngroundedAnswer => 'Resposta reprovada na validação de fundamentação',
            self::InsufficientEvidence => 'Pergunta factual sem evidência aprovada suficiente',
        };
    }

    /**
     * Motivos que justificam elevar a prioridade da conversa.
     */
    public function raisesPriority(): bool
    {
        return in_array($this, [
            self::Threat,
            self::ReportOrAccusation,
            self::IndividualHelpRequest,
            self::LegalMatter,
        ], true);
    }

    /**
     * Tradução dos motivos de revisão da subetapa anterior, para que a decisão
     * já tomada pela interpretação não seja reavaliada por outro critério.
     */
    public static function fromReviewReason(?string $reviewReason): ?self
    {
        return match ($reviewReason) {
            InsightReviewReason::Risk->value, InsightReviewReason::Threat->value => self::Threat,
            InsightReviewReason::SensitiveReport->value, InsightReviewReason::NamedAccusation->value => self::ReportOrAccusation,
            InsightReviewReason::LegalSensitive->value => self::LegalMatter,
            InsightReviewReason::PromiseRequest->value => self::PromiseOrCommitment,
            InsightReviewReason::PersonalRequest->value, InsightReviewReason::IndividualUrgency->value => self::IndividualHelpRequest,
            InsightReviewReason::HumanRequested->value => self::ExplicitRequest,
            InsightReviewReason::InsultOrAbuse->value, InsightReviewReason::Complaint->value => self::HostileContent,
            InsightReviewReason::LowConfidence->value => self::LowConfidence,
            InsightReviewReason::InvalidOutput->value, InsightReviewReason::ProviderFailure->value => self::RepeatedProviderFailure,
            default => null,
        };
    }

    /**
     * Tradução das categorias da subetapa anterior que nunca devem receber
     * resposta gerada.
     */
    public static function fromClassification(MessageClassification $classification): ?self
    {
        return match ($classification) {
            MessageClassification::HumanRequested => self::ExplicitRequest,
            MessageClassification::SensitiveReport => self::ReportOrAccusation,
            MessageClassification::InsultOrAbuse => self::HostileContent,
            MessageClassification::Complaint => self::HostileContent,
            MessageClassification::AsksAboutNorma => self::FactualQuestion,
            MessageClassification::MediaOrUnsupported => self::UnsupportedMedia,
            default => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
