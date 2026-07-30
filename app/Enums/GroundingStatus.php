<?php

namespace App\Enums;

/**
 * Veredito da validacao de fundamentacao.
 *
 * `NotRequired` cobre a resposta que nao afirma nada factual — uma pergunta de
 * aprofundamento, um agradecimento. Ela nao precisa de evidencia porque nao faz
 * afirmacao sobre o mundo.
 */
enum GroundingStatus: string
{
    case NotRequired = 'not_required';
    case Grounded = 'grounded';
    case NoEvidence = 'no_evidence';
    case InvalidCitation = 'invalid_citation';
    case ObsoleteCitation = 'obsolete_citation';
    case UnsupportedNumber = 'unsupported_number';
    case UnsupportedDate = 'unsupported_date';
    case UnsupportedCommitment = 'unsupported_commitment';
    case GroundedWithoutCitation = 'grounded_without_citation';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Sem afirmacao factual',
            self::Grounded => 'Fundamentada',
            self::NoEvidence => 'Afirmacao factual sem evidencia',
            self::InvalidCitation => 'Citacao fora do conjunto recuperado',
            self::ObsoleteCitation => 'Citacao de documento nao recuperavel',
            self::UnsupportedNumber => 'Numero sem suporte nos trechos citados',
            self::UnsupportedDate => 'Data sem suporte nos trechos citados',
            self::UnsupportedCommitment => 'Compromisso sem suporte nos trechos citados',
            self::GroundedWithoutCitation => 'Declarada fundamentada sem citar nada',
        };
    }

    /**
     * Vereditos que permitem envio.
     */
    public function allowsSending(): bool
    {
        return $this === self::NotRequired || $this === self::Grounded;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
