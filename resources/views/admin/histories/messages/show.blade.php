<x-layouts.app title="Detalhe do envio" breadcrumbs="Historicos / Mensagens / Detalhe">
    <section class="card">
        <h2>{{ $recipient->contact_name_snapshot }}</h2>
        <p><strong>Lote:</strong> {{ $recipient->batch?->name }} | <strong>Status:</strong> {{ $recipient->processing_status?->label() }}</p>
        <p><strong>Request ID:</strong> {{ Str::mask($recipient->request_id ?? '', '*', 8, -8) }} | <strong>ID externo:</strong> {{ $recipient->external_message_id ?? '-' }}</p>
        <p><strong>Erro:</strong> {{ $recipient->error_code ?? '-' }} | <strong>Classificação:</strong> {{ $classification->label() }}</p>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Dados utilizados no envio</h2>
        <p>{{ $recipient->contact_name_snapshot }} - {{ $recipient->contact_phone_snapshot }} - {{ $recipient->contact_city_snapshot }}/{{ $recipient->contact_state_snapshot }}</p>
        @if($recipient->contact && ($recipient->contact->name !== $recipient->contact_name_snapshot || $recipient->contact->phone !== $recipient->contact_phone_snapshot || $recipient->contact->city !== $recipient->contact_city_snapshot))
            <div class="alert warning">Os dados atuais do contato são diferentes dos dados utilizados neste envio.</div>
        @endif
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Mensagem</h2>
        @can('histories.view_message_content')<pre style="white-space:pre-wrap;">{{ $recipient->rendered_message }}</pre>@else<p class="muted">Conteúdo protegido por permissão.</p>@endcan
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Tentativas</h2>
        <div class="table-wrap"><table><thead><tr><th>N</th><th>Status</th><th>Início</th><th>Fim</th><th>Erro</th></tr></thead><tbody>@foreach($recipient->attempts()->latest('started_at')->get() as $attempt)<tr><td>{{ $attempt->attempt_number }}</td><td>{{ $attempt->status->value }}</td><td>{{ $attempt->started_at?->format($dateTimeFormat) }}</td><td>{{ $attempt->finished_at?->format($dateTimeFormat) }}</td><td>{{ $attempt->error_code ?? '-' }}</td></tr>@endforeach</tbody></table></div>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Eventos</h2>
        <ul class="stack-list">@forelse($recipient->processingEvents as $event)<li>{{ $event->created_at?->format($dateTimeFormat) }} - {{ $event->event_type }} - {{ $event->description }}</li>@empty<li>Nenhum evento.</li>@endforelse</ul>
    </section>
</x-layouts.app>
