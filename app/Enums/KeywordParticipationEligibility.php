<?php

namespace App\Enums;

/**
 * Se a pessoa é aluna do portal, para a campanha que exige isso.
 *
 * A entrada não verifica nada — qualquer pessoa se inscreve. A marcação vem
 * depois, pela importação da lista do portal, e o que não casar espera
 * conferência humana. O congelamento da lista exige que não sobre nenhuma
 * participação em `nao_verificada`, porque uma lista congelada com inelegível
 * dentro obriga a resortear, e sorteio refeito é indistinguível, de fora, de
 * sorteio refeito porque o ganhador não agradou.
 */
enum KeywordParticipationEligibility: string
{
    case NaoVerificada = 'nao_verificada';
    case AlunoConfirmado = 'aluno_confirmado';
    case NaoAluno = 'nao_aluno';

    public function label(): string
    {
        return match ($this) {
            self::NaoVerificada => 'Não verificada',
            self::AlunoConfirmado => 'Aluno confirmado',
            self::NaoAluno => 'Não é aluno',
        };
    }
}
