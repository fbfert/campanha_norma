<x-layouts.app title="Eventos WhatsApp" breadcrumbs="Inicio / WhatsApp / Eventos">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="event_type">Evento</label><input id="event_type" name="event_type" value="{{ request('event_type') }}"></div>
            <div><label for="status">Status</label><input id="status" name="status" value="{{ request('status') }}"></div>
            <div><label for="error_code">Codigo de erro</label><input id="error_code" name="error_code" value="{{ request('error_code') }}"></div>
            <div><label for="user_id">Usuario</label><select id="user_id" name="user_id"><option value="">Todos</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></div>
            <div><label for="date_from">Data inicial</label><input id="date_from" name="date_from" type="date" value="{{ request('date_from') }}"></div>
            <div><label for="date_to">Data final</label><input id="date_to" name="date_to" type="date" value="{{ request('date_to') }}"></div>
            <div class="actions"><button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button><a class="btn ghost" href="{{ route('admin.whatsapp.events') }}">Limpar</a></div>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Evento</th><th>Status</th><th>Descricao</th><th>Usuario</th><th>Erro</th><th>Detalhes</th></tr></thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td>{{ $event->created_at?->format($dateTimeFormat) }}</td>
                            <td>{{ $event->event_type }}</td>
                            <td>{{ $event->status?->label() ?? '-' }}</td>
                            <td>{{ $event->description }}</td>
                            <td>{{ $event->user?->name ?? 'Sistema' }}</td>
                            <td>{{ $event->error_code ?? '-' }}</td>
                            <td><pre style="white-space:pre-wrap;">{{ json_encode($event->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Nenhum evento encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $events->links() }}
    </section>
</x-layouts.app>
