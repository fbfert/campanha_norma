@php
    // Os estados que aceitam cada ação, num lugar só: a lista dentro do `@if`
    // de cada botão divergiria da regra da ação na primeira alteração.
    use App\Enums\MessageRecipientProcessingStatus as Status;

    $cancelaveis = [
        Status::Pending, Status::Queued, Status::RetryWait,
        Status::WaitingSchedule, Status::WaitingMinuteLimit, Status::WaitingMinimumInterval,
        Status::WaitingHourLimit, Status::WaitingDayLimit,
    ];

    $reprocessaveis = [
        Status::FailedTemporary, Status::RetryWait,
        Status::WaitingSchedule, Status::WaitingMinuteLimit, Status::WaitingMinimumInterval,
        Status::WaitingHourLimit, Status::WaitingDayLimit,
    ];
@endphp

<x-layouts.app title="Acompanhamento do lote" breadcrumbs="Mensagens / Processamento">
    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>{{ $batch->name }}</h2>
                <p class="muted">Status: {{ $batch->status->label() }}</p>
            </div>
            <div class="actions">
                @can('message_processing.start')
                    @if($batch->status === \App\Enums\MessageBatchStatus::Ready)
                        <form method="post" action="{{ route('admin.message-batches.start', $batch) }}">@csrf<button class="btn" type="submit">Iniciar</button></form>
                    @endif
                @endcan
                @can('message_processing.pause')
                    @if(in_array($batch->status, [\App\Enums\MessageBatchStatus::Queued, \App\Enums\MessageBatchStatus::Processing], true))
                        <form method="post" action="{{ route('admin.message-batches.pause', $batch) }}">@csrf<button class="btn secondary" type="submit">Pausar</button></form>
                    @endif
                @endcan
                @can('message_processing.resume')
                    @if($batch->status === \App\Enums\MessageBatchStatus::Paused)
                        <form method="post" action="{{ route('admin.message-batches.resume', $batch) }}">@csrf<button class="btn" type="submit">Continuar</button></form>
                    @endif
                @endcan
                {{-- Parar cancela todo destinatário pendente, e era irreversível.
                     Retomar desfaz aqueles cancelamentos — só aqueles. --}}
                @can('message_processing.start')
                    @if($batch->status === \App\Enums\MessageBatchStatus::Stopped && $retomaveis > 0)
                        <form method="post" action="{{ route('admin.message-batches.resume-stopped', $batch) }}"
                            onsubmit="return confirm('Retomar o envio para {{ $retomaveis }} destinatário(s) deste lote?')">
                            @csrf
                            <button class="btn" type="submit" title="Devolve à fila os destinatários que a parada cancelou. Quem foi cancelado individualmente continua fora.">
                                <x-icon name="play" size="16" />Retomar envios ({{ $retomaveis }})
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        @can('message_processing.stop')
            @if(in_array($batch->status, [\App\Enums\MessageBatchStatus::Queued, \App\Enums\MessageBatchStatus::Processing, \App\Enums\MessageBatchStatus::Paused], true))
                <form method="post" action="{{ route('admin.message-batches.stop', $batch) }}" class="inline-form">
                    @csrf
                    <input name="reason" required placeholder="Motivo da parada">
                    <button class="btn danger" type="submit">Parar</button>
                </form>
            @endif
        @endcan

        <div class="stats-grid">
            <div class="stat"><span>Total apto</span><strong>{{ $batch->eligible_total }}</strong></div>
            <div class="stat"><span>Pendentes</span><strong>{{ $batch->total_pending }}</strong></div>
            <div class="stat"><span>Em processamento</span><strong>{{ $batch->total_processing }}</strong></div>
            <div class="stat"><span>Enviados</span><strong>{{ $batch->total_sent }}</strong></div>
            <div class="stat"><span>Falhas</span><strong>{{ $batch->total_failed }}</strong></div>
            <div class="stat"><span>Cancelados</span><strong>{{ $batch->total_cancelled }}</strong></div>
        </div>

        <p class="muted">
            Janela atual: {{ $window['allowed'] ? 'aberta' : 'fechada' }}.
            Próximo envio possível: {{ $window['next_at']?->format($dateTimeFormat) ?? $limits['next_at']?->format($dateTimeFormat) ?? '-' }}.
            Limites: minuto {{ $limits['counters']['minute'] ?? 0 }}/{{ $settings->max_per_minute }},
            hora {{ $limits['counters']['hour'] ?? 0 }}/{{ $settings->max_per_hour }},
            dia {{ $limits['counters']['day'] ?? 0 }}/{{ $settings->max_per_day }}.
        </p>
    </div>

    <div class="panel">
        <div class="panel-header"><h2>Destinatários</h2></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Posição</th>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>Status</th>
                        <th>Tentativas</th>
                        <th>Próxima tentativa</th>
                        <th>Erro</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recipients as $recipient)
                        <tr>
                            <td>{{ $recipient->random_position ?? '-' }}</td>
                            <td>{{ $recipient->contact_name_snapshot }}</td>
                            <td>{{ $recipient->contact_phone_snapshot }}</td>
                            <td>{{ $recipient->processing_status?->label() }}</td>
                            <td>{{ $recipient->attempts }}</td>
                            <td>{{ $recipient->retry_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td>{{ $recipient->error_code ? $recipient->error_code . ' - ' . $recipient->error_message : '-' }}</td>
                            <td class="actions">
                                @can('message_processing.view_attempts')
                                    <a class="btn ghost" href="{{ route('admin.message-batches.recipients.attempts', [$batch, $recipient]) }}">Tentativas</a>
                                @endcan
                                @can('message_processing.cancel_recipient')
                                    @if(in_array($recipient->processing_status, $cancelaveis, true))
                                        <form method="post" action="{{ route('admin.message-batches.recipients.cancel', [$batch, $recipient]) }}">@csrf<button class="btn secondary" type="submit">Cancelar</button></form>
                                    @endif

                                    {{-- Cancelar era irreversível: quem clicasse por engano teria de
                                         refazer o lote para alcançar uma pessoa. --}}
                                    @if($recipient->processing_status === \App\Enums\MessageRecipientProcessingStatus::Cancelled)
                                        <form method="post" action="{{ route('admin.message-batches.recipients.uncancel', [$batch, $recipient]) }}">
                                            @csrf
                                            <button class="btn secondary" type="submit" title="Devolve o destinatário à fila de espera. Ele passa de novo por todas as conferências.">
                                                <x-icon name="refresh" size="16" />Desfazer cancelamento
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('message_processing.retry')
                                    {{-- Reprocessar reavalia; não fura a janela nem os limites. Se a
                                         regra ainda valer, o destinatário volta para a espera. --}}
                                    @if(in_array($recipient->processing_status, $reprocessaveis, true))
                                        <form method="post" action="{{ route('admin.message-batches.recipients.retry', [$batch, $recipient]) }}">
                                            @csrf
                                            <button class="btn" type="submit" title="Refaz as conferências agora: elegibilidade, janela de horário e limites de ritmo.">
                                                <x-icon name="refresh" size="16" />Reprocessar
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $recipients->links() }}
    </div>

    <div class="panel">
        <div class="panel-header"><h2>Eventos recentes</h2></div>
        <ul class="stack-list">
            @forelse($events as $event)
                <li>
                    {{ $event->created_at->format($dateTimeFormat) }} - {{ $event->event_type }} - {{ $event->description }}
                    @if($event->recipient)
                        {{-- Snapshot, e não o cadastro atual: o evento conta o que
                             valia na hora, e o contato pode ter mudado desde então. --}}
                        <span class="muted">{{ $event->recipient->contact_name_snapshot }} &middot; {{ $event->recipient->contact_phone_snapshot }}</span>
                    @else
                        <span class="muted">Lote inteiro</span>
                    @endif
                </li>
            @empty
                <li>Nenhum evento registrado.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
