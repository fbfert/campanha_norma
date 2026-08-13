<?php

namespace App\Http\Controllers\Admin\InboundAttendance;

use App\Enums\ConversationMessageDirection;
use App\Enums\InboundAttendanceOutcome;
use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\InboundAttendanceAttempt;
use App\Models\InboundAttendanceProfile;
use App\Services\InboundAttendance\InboundAttendanceQueue;
use App\Services\InboundAttendance\InboundAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A fila de mensagens aguardando resposta.
 *
 * O contador do topo aponta para cá. A tela mostra duas listas: o que espera
 * ação e o que o sistema já atendeu hoje — a segunda não pede nada, existe para
 * quem entra de manhã ver o que foi dito em seu nome.
 */
class InboundAttendanceQueueController extends Controller
{
    public function index(Request $request, InboundAttendanceQueue $queue): View
    {
        abort_unless($request->user()->can('inbound_attendance.view'), 403);

        $pending = $queue->pending($request->user())
            ->with(['contact', 'assignee'])
            ->orderByDesc('last_incoming_message_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.inbound-attendance.index', [
            'pending' => $pending,
            'lastMessages' => $this->lastIncomingMessages($pending->getCollection()->pluck('id')->all()),
            'reasons' => $this->lastReasons($pending->getCollection()->pluck('id')->all()),
            'startedToday' => $queue->startedToday($request->user())
                ->with('contact')
                ->orderByDesc('last_message_at')
                ->limit(25)
                ->get(),
            // Descartadas por expressão de exclusão. Não pedem ação; existem
            // para uma regra larga demais não engolir gente em silêncio.
            'skippedToday' => $queue->skippedToday()->limit(25)->get(),
            'profiles' => InboundAttendanceProfile::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),

            // Nada some em silêncio. Uma expressão de exclusão larga demais
            // engoliria uma pessoa de verdade, e é aqui que isso aparece.
            'skippedToday' => $queue->skippedToday()->limit(25)->get(),
        ]);
    }

    /**
     * Inicia conversa automática nas conversas marcadas.
     *
     * O clique passa por menos travas que o automático — teto diário e
     * homologação existem para conter o que sai sozinho — mas não por menos
     * proteção: quem pediu para sair, quem está inativo e quem já está em um
     * fluxo continuam recusados, um por um, com o motivo à vista.
     */
    public function start(Request $request, InboundAttendanceService $attendance, InboundAttendanceQueue $queue): RedirectResponse
    {
        abort_unless($request->user()->can('inbound_attendance.start'), 403);

        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1'],
            'conversation_ids.*' => ['integer'],
            'inbound_attendance_profile_id' => ['nullable', 'integer', 'exists:inbound_attendance_profiles,id'],
        ], [], [
            'conversation_ids' => 'conversas',
            'inbound_attendance_profile_id' => 'perfil de atendimento',
        ]);

        $profile = filled($validated['inbound_attendance_profile_id'] ?? null)
            ? InboundAttendanceProfile::find($validated['inbound_attendance_profile_id'])
            : null;

        // Só o que a pessoa poderia ver. Sem isto, um id digitado na mão
        // iniciaria conversa fora do escopo de quem clicou.
        $conversations = $queue->pending($request->user())
            ->whereIn('id', $validated['conversation_ids'])
            ->get();

        $started = 0;
        $blocked = [];

        foreach ($conversations as $conversation) {
            $message = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', ConversationMessageDirection::Incoming)
                ->latest('id')
                ->first();

            if (! $message) {
                continue;
            }

            $message->setRelation('conversation', $conversation);
            $result = $attendance->handle($message, $request->user(), $profile);

            if ($result['outcome'] === InboundAttendanceOutcome::Started) {
                $started++;

                continue;
            }

            $blocked[] = '#'.$conversation->id.': '.(new InboundAttendanceAttempt(['reason' => $result['reason']]))->reasonLabel();
        }

        $queue->forgetCount();

        $message = $started === 1
            ? '1 conversa iniciada.'
            : $started.' conversas iniciadas.';

        // Recusa não é detalhe de log: quem clicou em vinte e viu duas
        // recusadas precisa saber quais foram e por quê, na mesma tela.
        return $blocked === []
            ? redirect()->route('admin.inbound-attendance.index')->with('success', $message)
            : redirect()->route('admin.inbound-attendance.index')
                ->with('success', $message)
                ->with('warning', 'Não iniciadas — '.implode('; ', $blocked));
    }

    /**
     * Última mensagem recebida de cada conversa da página.
     *
     * Uma consulta para a página inteira. Buscar dentro do laço da view faria
     * vinte e cinco consultas para mostrar vinte e cinco frases.
     *
     * @param  list<int>  $conversationIds
     * @return Collection<int, ConversationMessage>
     */
    private function lastIncomingMessages(array $conversationIds): Collection
    {
        if ($conversationIds === []) {
            return collect();
        }

        return ConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', ConversationMessageDirection::Incoming)
            ->whereIn('id', function ($query) use ($conversationIds): void {
                $query->selectRaw('max(id)')
                    ->from('conversation_messages')
                    ->whereIn('conversation_id', $conversationIds)
                    ->where('direction', ConversationMessageDirection::Incoming->value)
                    ->groupBy('conversation_id');
            })
            ->get()
            ->keyBy('conversation_id');
    }

    /**
     * Motivo da última recusa de cada conversa.
     *
     * É o que transforma "a conversa está parada" em "está parada porque o teto
     * de hoje acabou". A primeira frase não diz o que fazer; a segunda diz.
     *
     * @param  list<int>  $conversationIds
     * @return Collection<int, InboundAttendanceAttempt>
     */
    private function lastReasons(array $conversationIds): Collection
    {
        if ($conversationIds === []) {
            return collect();
        }

        return InboundAttendanceAttempt::query()
            ->with('profile')
            ->whereIn('conversation_id', $conversationIds)
            ->where('outcome', InboundAttendanceOutcome::Blocked)
            ->whereIn('id', function ($query) use ($conversationIds): void {
                $query->selectRaw('max(id)')
                    ->from('inbound_attendance_attempts')
                    ->whereIn('conversation_id', $conversationIds)
                    ->where('outcome', InboundAttendanceOutcome::Blocked->value)
                    ->groupBy('conversation_id');
            })
            ->get()
            ->keyBy('conversation_id');
    }
}
