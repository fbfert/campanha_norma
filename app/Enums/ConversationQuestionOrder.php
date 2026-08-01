<?php

namespace App\Enums;

/**
 * Como a conversa escolhe a próxima pergunta da pesquisa.
 *
 * O sorteio ponderado distribui perguntas diferentes entre respondentes, o que
 * serve para cobrir muitos temas com poucas perguntas por pessoa. A sequência
 * faz todo mundo responder as mesmas perguntas na mesma ordem, o que serve
 * quando a pesquisa e um questionário e a ordem carrega sentido.
 */
enum ConversationQuestionOrder: string
{
    case Sorteio = 'sorteio';
    case Sequencia = 'sequencia';

    public function label(): string
    {
        return match ($this) {
            self::Sorteio => 'Sorteio ponderado',
            self::Sequencia => 'Sequência definida',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sorteio => 'Cada conversa recebe perguntas sorteadas pelo peso. Respondentes diferentes recebem perguntas diferentes.',
            self::Sequencia => 'Todas as conversas seguem a ordem cadastrada nas perguntas. O peso e ignorado.',
        };
    }
}
