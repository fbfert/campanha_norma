<x-layouts.app title="Auditoria" breadcrumbs="Inicio / Auditoria">
    <form method="get" class="card" style="margin-bottom:16px;">
        <div class="grid grid-3">
            <p><label>Usuario</label><select name="user_id"><option value="">Todos</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string)($filters['user_id'] ?? '') === (string)$user->id)>{{ $user->name }}</option>@endforeach</select></p>
            <p><label>Acao</label><input name="action" value="{{ $filters['action'] ?? '' }}"></p>
            <p><label>Entidade</label><input name="entity_type" value="{{ $filters['entity_type'] ?? '' }}"></p>
            <p><label>Data inicial</label><input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></p>
            <p><label>Data final</label><input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></p>
            <p><label>IP</label><input name="ip_address" value="{{ $filters['ip_address'] ?? '' }}"></p>
        </div>
        <button class="btn" type="submit">Filtrar</button>
        <a class="btn ghost" href="{{ route('admin.audit-logs.index') }}">Limpar</a>
    </form>
    <section class="card table-wrap">
        @if ($auditLogs->isEmpty())
            <p class="muted">Nenhum registro de auditoria encontrado.</p>
        @else
            <table>
                <thead><tr><th>Data</th><th>Usuario</th><th>Acao</th><th>Entidade</th><th>Descricao</th><th>IP</th><th></th></tr></thead>
                <tbody>
                @foreach($auditLogs as $log)
                    <tr>
                        <td>{{ $log->created_at->format($dateTimeFormat) }}</td>
                        <td>{{ $log->user?->name ?? 'Sistema' }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ class_basename($log->entity_type ?? '-') }} #{{ $log->entity_id }}</td>
                        <td>{{ $log->description }}</td>
                        <td>{{ $log->ip_address }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.audit-logs.show', $log) }}">Detalhes</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $auditLogs->links() }}
        @endif
    </section>
</x-layouts.app>
