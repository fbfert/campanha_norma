<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationQuestionOrder;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowQuestionUsage;
use App\Models\ConversationFlowState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escolha da próxima pergunta ainda não usada na conversa.
 *
 * O fluxo decide entre sorteio ponderado e sequência definida.
 *
 * A exclusividade real e garantida pelo índice único
 * (conversation_id, conversation_flow_question_id); a transação e o lock
 * apenas evitam o trabalho duplicado antes de chegar no banco.
 */
class ConversationQuestionSelector
{
    /**
     * Seleciona e registra o uso de uma pergunta. Retorna null quando não ha
     * pergunta ativa disponível ou quando outro worker ganhou a corrida.
     */
    public function select(ConversationFlowState $state): ?ConversationFlowQuestionUsage
    {
        return DB::transaction(function () use ($state): ?ConversationFlowQuestionUsage {
            $usedIds = ConversationFlowQuestionUsage::query()
                ->where('conversation_id', $state->conversation_id)
                ->lockForUpdate()
                ->pluck('conversation_flow_question_id')
                ->all();

            $sequencial = $state->flow?->question_order === ConversationQuestionOrder::Sequencia;

            $candidates = ConversationFlowQuestion::query()
                ->where('conversation_flow_id', $state->conversation_flow_id)
                ->where('is_active', true)
                ->when($usedIds !== [], fn ($query) => $query->whereNotIn('id', $usedIds))
                ->when($sequencial, fn ($query) => $query->orderBy('display_order'))
                ->orderBy('id')
                ->get();

            // Em sequência, a próxima pergunta e a primeira ainda não usada:
            // o peso deixa de valer, porque a ordem foi decidida por quem
            // escreveu o questionário.
            $question = $sequencial ? $candidates->first() : $this->draw($candidates);

            if (! $question) {
                return null;
            }

            try {
                return ConversationFlowQuestionUsage::create([
                    'conversation_flow_state_id' => $state->id,
                    'conversation_id' => $state->conversation_id,
                    'conversation_flow_question_id' => $question->id,
                    'question_snapshot' => $question->text,
                    'selected_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Outro worker registrou a mesma pergunta primeiro.
                return null;
            }
        });
    }

    /**
     * Sorteio ponderado determinístico dado o gerador aleatório do PHP,
     * o que permite fixar o resultado em teste com mt_srand.
     *
     * @param  Collection<int, ConversationFlowQuestion>  $questions
     */
    public function draw($questions): ?ConversationFlowQuestion
    {
        $eligible = $questions->filter(fn (ConversationFlowQuestion $question): bool => $question->weight > 0);

        if ($eligible->isEmpty()) {
            // Sem peso positivo não ha sorteio ponderado possível.
            return $questions->first();
        }

        $total = (int) $eligible->sum('weight');
        $point = random_int(1, $total);
        $accumulated = 0;

        foreach ($eligible as $question) {
            $accumulated += (int) $question->weight;
            if ($point <= $accumulated) {
                return $question;
            }
        }

        return $eligible->last();
    }
}
