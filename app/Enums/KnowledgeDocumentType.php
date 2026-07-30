<?php

namespace App\Enums;

/**
 * Tipos de conteúdo admissíveis na base oficial.
 *
 * A lista e fechada de propósito. Rumor, material não aprovado, dado pessoal
 * privado, estratégia eleitoral confidencial, conversa de outro contato,
 * inferência de voto, informação de adversário usada para ataque e promessa não
 * formalizada não tem tipo aqui — e a ausência do tipo e a barreira estrutural.
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
            self::PublicHistory => 'Histórico público',
            self::InstitutionalCompetence => 'Competências institucionais',
            self::ApprovedProposal => 'Proposta aprovada',
            self::OfficialPosition => 'Posição oficialmente publicada',
            self::PublicAgenda => 'Agenda pública autorizada',
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
