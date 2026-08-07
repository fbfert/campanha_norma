<?php

namespace Tests\Feature;

use App\Enums\MessageRecipientProcessingStatus;
use Tests\TestCase;

/**
 * Todo status de espera precisa aparecer em todas as listas.
 *
 * O despachante, as ações de parar, retomar, cancelar e reprocessar, e a tela
 * de acompanhamento enumeram os status de espera à mão. Fora da consulta do
 * despachante, o destinatário nunca mais é escolhido: fica parado para sempre,
 * sem erro visível e sem nada na tela que explique.
 *
 * Isso já estava escrito em `docs/message-processing.md` como aviso, e o aviso
 * não bastou. Ao acrescentar `waiting_reciprocity`, o status entrou no enum e em
 * nenhuma das seis listas — 106 destinatários ficaram parados até alguém notar.
 *
 * Este teste é a versão executável do aviso: status de espera novo quebra a
 * suíte até entrar em todo lugar que precisa dele.
 */
class EsperaNovaEntraEmTodasAsListasTest extends TestCase
{
    /**
     * Arquivos que enumeram status de espera, e precisam citar todos eles.
     *
     * @var array<int, string>
     */
    private const LISTAS = [
        'app/Services/MessageProcessing/BatchDispatcherService.php',
        'app/Actions/MessageBatches/StopMessageBatchAction.php',
        'app/Actions/MessageBatches/RetryMessageRecipientAction.php',
        'app/Actions/MessageBatches/CancelMessageRecipientAction.php',
        'app/Actions/MessageBatches/ResumeStoppedMessageBatchAction.php',
        'app/Services/Conversations/ReplyInterruptionService.php',
        'resources/views/admin/message-processing/show.blade.php',
    ];

    public function test_todo_status_de_espera_aparece_em_todas_as_listas(): void
    {
        $ausencias = [];

        foreach ($this->waitingStatuses() as $status) {
            foreach (self::LISTAS as $arquivo) {
                $conteudo = (string) file_get_contents(base_path($arquivo));

                if (! str_contains($conteudo, $this->caseName($status))) {
                    $ausencias[] = $status->value.' não aparece em '.$arquivo;
                }
            }
        }

        $this->assertSame([], $ausencias, implode("\n", $ausencias));
    }

    /**
     * Um status de espera se reconhece pelo nome, e não por uma segunda lista
     * que também teria de ser mantida à mão.
     *
     * @return array<int, MessageRecipientProcessingStatus>
     */
    private function waitingStatuses(): array
    {
        return array_values(array_filter(
            MessageRecipientProcessingStatus::cases(),
            fn (MessageRecipientProcessingStatus $status): bool => str_starts_with($status->value, 'waiting_'),
        ));
    }

    /** `waiting_minute_limit` vira `WaitingMinuteLimit`, que é como o código cita. */
    private function caseName(MessageRecipientProcessingStatus $status): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $status->value)));
    }
}
