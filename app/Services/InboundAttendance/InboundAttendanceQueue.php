<?php

namespace App\Services\InboundAttendance;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Enums\InboundAttendanceOutcome;
use App\Models\Conversation;
use App\Models\InboundAttendanceAttempt;
use App\Models\User;
use App\Services\SystemSettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * A fila: o que espera resposta e o que já foi atendido hoje.
 *
 * O contador podia ser simplesmente "conversa cuja última mensagem é da
 * pessoa", e seria enganoso: a maior parte dessas conversas está a segundos de
 * receber resposta automática, e um número que sobe e desce sozinho ensina a
 * ignorar o número. Aqui entra o que a automação **não** resolveu — porque uma
 * trava recusou, ou porque o tempo de carência passou e nada aconteceu.
 *
 * Abaixo delas ficam as atendidas hoje. Elas não pedem ação nenhuma; existem
 * para que quem entra de manhã veja o que o sistema andou dizendo em seu nome.
 */
class InboundAttendanceQueue
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Conversas esperando resposta.
     *
     * Duas condições, e as duas precisam valer: a última palavra é da pessoa —
     * saída que falhou não conta como resposta, foi essa confusão que fez a
     * rede de segurança parar de tentar em conversas que ela devia atender — e
     * a automação já teve sua chance.
     */
    public function pending(?User $user = null): Builder
    {
        $grace = max(0, (int) $this->settings->get('inbound_attendance.pending_grace_minutes', 5));

        return Conversation::query()
            ->whereNotNull('last_incoming_message_at')
            ->whereNotIn('status', [ConversationStatus::Blocked, ConversationStatus::Archived])
            ->where('is_archived', false)
            ->whereRaw($this->lastWordIsTheirsSql(), [
                ConversationMessageDirection::Outgoing->value,
                ConversationMessageStatus::Failed->value,
                ConversationMessageStatus::Cancelled->value,
                ConversationMessageDirection::Incoming->value,
            ])
            ->where(function (Builder $query) use ($grace): void {
                $query
                    ->where('last_incoming_message_at', '<=', now()->subMinutes($grace))
                    ->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('inbound_attendance_attempts')
                            ->whereColumn('inbound_attendance_attempts.conversation_id', 'conversations.id')
                            ->where('inbound_attendance_attempts.outcome', InboundAttendanceOutcome::Blocked->value)
                            ->whereColumn('inbound_attendance_attempts.created_at', '>=', 'conversations.last_incoming_message_at');
                    });
            })
            /*
             | Mensagem de robô sai da fila.
             |
             | Uma fila que mistura gente esperando com aviso de operadora
             | ensina a ignorar a fila, que é o oposto do que ela existe para
             | fazer. Elas não somem: ficam listadas à parte na mesma tela,
             | porque expressão de exclusão larga demais engoliria uma pessoa
             | de verdade, e isso precisa ser visível.
             */
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('inbound_attendance_attempts')
                    ->whereColumn('inbound_attendance_attempts.conversation_id', 'conversations.id')
                    ->where('inbound_attendance_attempts.outcome', InboundAttendanceOutcome::Skipped->value)
                    ->where('inbound_attendance_attempts.reason', 'mensagem_ignorada')
                    ->whereColumn('inbound_attendance_attempts.created_at', '>=', 'conversations.last_incoming_message_at');
            })
            ->when($user, fn (Builder $query, User $user) => $this->scopeToUser($query, $user));
    }

    /**
     * Conversas que o atendimento abriu hoje. Confirmação, não tarefa.
     */
    public function startedToday(?User $user = null): Builder
    {
        return Conversation::query()
            ->whereIn('id', InboundAttendanceAttempt::query()
                ->select('conversation_id')
                ->where('outcome', InboundAttendanceOutcome::Started)
                ->where('created_at', '>=', now()->startOfDay()))
            ->when($user, fn (Builder $query, User $user) => $this->scopeToUser($query, $user));
    }

    /**
     * O que a exclusão descartou hoje.
     *
     * Existe para a expressão larga demais não engolir gente em silêncio. Uma
     * pessoa que escreveu "quero recarregar meu crédito" cairia numa regra
     * pensada para robô de operadora, e sem esta lista ninguém saberia.
     *
     * @return \Illuminate\Database\Eloquent\Builder<InboundAttendanceAttempt>
     */
    public function skippedToday(): \Illuminate\Database\Eloquent\Builder
    {
        return InboundAttendanceAttempt::query()
            ->with(['conversation.contact', 'message'])
            ->where('outcome', InboundAttendanceOutcome::Skipped)
            ->where('reason', 'mensagem_ignorada')
            ->where('created_at', '>=', now()->startOfDay())
            ->latest('id');
    }

    /**
     * O número do aviso.
     *
     * Guardado por trinta segundos, do mesmo jeito que o contador de não lidas:
     * ele aparece em toda tela, e uma contagem por requisição sairia caro sem
     * ninguém ganhar nada — trinta segundos de atraso num número que muda de
     * minuto em minuto não muda decisão nenhuma.
     */
    public function pendingCount(User $user): int
    {
        return Cache::remember(
            "inbound-attendance:pending-count:user:{$user->id}",
            30,
            fn (): int => $this->pending($user)->count(),
        );
    }

    public function forgetCount(?User $user = null): void
    {
        if ($user) {
            Cache::forget("inbound-attendance:pending-count:user:{$user->id}");

            return;
        }

        // Iniciar uma conversa muda o contador de todo mundo, e não só o de
        // quem clicou. Sem isto o número ficaria errado por até trinta segundos
        // justamente na tela de quem acabou de agir.
        User::query()->pluck('id')->each(
            fn (int $id) => Cache::forget("inbound-attendance:pending-count:user:{$id}")
        );
    }

    /**
     * A última mensagem que vale é recebida.
     *
     * Vale por SQL, e não filtrando uma coleção em PHP, porque isto roda em
     * toda requisição pelo contador do topo: carregar duzentas conversas para
     * descartar cento e noventa seria pagar caro por um número.
     */
    private function lastWordIsTheirsSql(): string
    {
        // Apelidos em inglês porque são identificadores de SQL, e identificador
        // não leva acento neste repositório: `saida` sem acento seria erro de
        // ortografia, e com acento seria um apelido que nem todo banco aceita.
        return 'not exists ('
            .'select 1 from conversation_messages as outgoing '
            .'where outgoing.conversation_id = conversations.id '
            .'and outgoing.direction = ? '
            .'and outgoing.status not in (?, ?) '
            .'and outgoing.id > ('
            .'select max(incoming.id) from conversation_messages as incoming '
            .'where incoming.conversation_id = conversations.id and incoming.direction = ?'
            .')'
            .')';
    }

    /**
     * Quem não vê tudo vê o que é seu e o que não é de ninguém.
     */
    private function scopeToUser(Builder $query, User $user): Builder
    {
        if ($user->can('inbox.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_user_id', $user->id)->orWhereNull('assigned_user_id');
        });
    }
}
