<?php

namespace App\Enums;

/**
 * Tipos de conteudo admissiveis na base oficial.
 *
 * A lista e fechada de proposito. Rumor, material nao aprovado, dado pessoal
 * privado, estrategia eleitoral confidencial, conversa de outro contato,
 * inferencia de voto, informacao de adversario usada para ataque e promessa nao
 * formalizada nao tem tipo aqui — e a ausencia do tipo e a barreira estrutural.
 */
enum KnowledgeDocumentType: string
{
    case Biography = 'biography';
    case PublicHistory = 'public_history';
    case InstitutionalCompetence = 'institutional_competence';
    case ApprovedProposal = 'approved_proposal';
    case OfficialPosition = 'official_position';
    case PublicAgenda = 'public_agenda';
    case Faq = 'faq';
    case ContactChannel = 'contact_channel';

    public function label(): string
    {
        return match ($this) {
            self::Biography => 'Biografia aprovada',
            self::PublicHistory => 'Historico publico',
            self::InstitutionalCompetence => 'Competencias institucionais',
            self::ApprovedProposal => 'Proposta aprovada',
            self::OfficialPosition => 'Posicao oficialmente publicada',
            self::PublicAgenda => 'Agenda publica autorizada',
            self::Faq => 'Perguntas frequentes',
            self::ContactChannel => 'Canais de contato',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
