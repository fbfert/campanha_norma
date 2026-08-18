<?php

namespace App\Enums;

enum KeywordParticipationStatus: string
{
    case Valida = 'valida';

    /**
     * Inscrição sem nome, e válida assim mesmo.
     *
     * O nome vem do perfil do WhatsApp, e nem todo mundo tem um. Bloquear por
     * isso transformaria um problema de cadastro em exclusão de participante.
     * A situação existe para a tela poder listar quem precisa de um nome antes
     * do anúncio, não para tirar ninguém do sorteio.
     */
    case SemNome = 'sem_nome';

    /**
     * O telefone casou com mais de um contato ativo.
     *
     * Escolher um dos dois no automático significaria inscrever uma pessoa e
     * deixar outra de fora sem que ninguém soubesse. Fica para um humano, e não
     * conta como válida enquanto isso.
     */
    case EmRevisao = 'em_revisao';

    case Invalidada = 'invalidada';

    public function label(): string
    {
        return match ($this) {
            self::Valida => 'Válida',
            self::SemNome => 'Sem nome',
            self::EmRevisao => 'Em revisão',
            self::Invalidada => 'Invalidada',
        };
    }

    /**
     * Entra na contagem de inscritos e na lista congelada.
     */
    public function contaComoValida(): bool
    {
        return $this === self::Valida || $this === self::SemNome;
    }
}
