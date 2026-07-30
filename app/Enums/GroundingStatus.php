<?php

namespace App\Enums;

/**
 * Veredito da validação de fundamentação.
 *
 * `NotRequired` cobre a resposta que não afirma nada factual — uma pergunta de
 * aprofundamento, um agradecimento. Ela não precisa de evidência porque não faz
 * afirmação sobre o mundo.
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
            self::NotRequired => 'Sem afirmação factual',
            self::Grounded => 'Fundamentada',
            self::NoEvidence => 'Afirmação factual sem evidência',
            self::InvalidCitation => 'Citação fora do conjunto recuperado',
            self::ObsoleteCitation => 'Citação de documento não recuperável',
            self::UnsupportedNumber => 'Número sem suporte nos trechos citados',
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
