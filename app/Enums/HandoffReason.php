<?php

namespace App\Enums;

/**
 * Motivos de encaminhamento para atendimento humano.
 *
 * Todos sao decididos por regra do sistema. O campo equivalente devolvido pelo
 * modelo e tratado como sinal, nunca como autorizacao.
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
            self::ExplicitRequest => 'Pedido explicito de atendimento humano',
            self::FactualQuestion => 'Pergunta factual sem base aprovada',
            self::ReportOrAccusation => 'Denuncia ou acusacao',
            self::Threat => 'Ameaca',
            self::IndividualHelpRequest => 'Pedido de ajuda individual',
            self::LegalMatter => 'Assunto juridico',
            self::PromiseOrCommitment => 'Promessa ou compromisso',
            self::LowConfidence => 'Confianca abaixo do limite',
            self::HostileContent => 'Conteudo hostil',
            self::UnsupportedMedia => 'Midia nao suportada',
            self::ContextConflict => 'Conflito de contexto',
            self::TurnLimitReached => 'Limite de aprofundamentos atingido',
            self::RepeatedProviderFailure => 'Falha repetida do provedor',
            self::InvalidGeneratedText => 'Texto gerado reprovado na validacao',
            self::UngroundedAnswer => 'Resposta reprovada na validacao de fundamentacao',
            self::InsufficientEvidence => 'Pergunta factual sem evidencia aprovada suficiente',
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
     * Traducao dos motivos de revisao da subetapa anterior, para que a decisao
     * ja tomada pela interpretacao nao seja reavaliada por outro criterio.
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
     * Traducao das categorias da subetapa anterior que nunca devem receber
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
