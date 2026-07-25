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
            Proximo envio possivel: {{ $window['next_at']?->format($dateTimeFormat) ?? $limits['next_at']?->format($dateTimeFormat) ?? '-' }}.
            Limites: minuto {{ $limits['counters']['minute'] ?? 0 }}/{{ $settings->max_per_minute }},
            hora {{ $limits['counters']['hour'] ?? 0 }}/{{ $settings->max_per_hour }},
            dia {{ $limits['counters']['day'] ?? 0 }}/{{ $settings->max_per_day }}.
        </p>
    </div>

    <div class="panel">
        <div class="panel-header"><h2>Destinatarios</h2></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Posicao</th>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>Status</th>
                        <th>Tentativas</th>
                        <th>Proxima tentativa</th>
                        <th>Erro</th>
                        <th>Acoes</th>
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
                                    @if(in_array($recipient->processing_status, [\App\Enums\MessageRecipientProcessingStatus::Pending, \App\Enums\MessageRecipientProcessingStatus::Queued, \App\Enums\MessageRecipientProcessingStatus::RetryWait], true))
                                        <form method="post" action="{{ route('admin.message-batches.recipients.cancel', [$batch, $recipient]) }}">@csrf<button class="btn secondary" type="submit">Cancelar</button></form>
                                    @endif
                                @endcan
                                @can('message_processing.retry')
                                    @if($recipient->processing_status === \App\Enums\MessageRecipientProcessingStatus::FailedTemporary)
                                        <form method="post" action="{{ route('admin.message-batches.recipients.retry', [$batch, $recipient]) }}">@csrf<button class="btn" type="submit">Tentar novamente</button></form>
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
                <li>{{ $event->created_at->format($dateTimeFormat) }} - {{ $event->event_type }} - {{ $event->description }}</li>
            @empty
                <li>Nenhum evento registrado.</li>
            @endforelse
        </ul>
    </div>
</x-layouts.app>
