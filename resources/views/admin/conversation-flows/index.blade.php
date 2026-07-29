<x-layouts.app title="Fluxos" breadcrumbs="Inicio / Pesquisa conversacional / Fluxos">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="q">Nome</label><input id="q" name="q" value="{{ request('q') }}"></div>
            <div><label for="status">Status</label><select id="status" name="status"><option value="">Todos</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
            <div class="actions"><button class="btn" type="submit">Filtrar</button><a class="btn ghost" href="{{ route('admin.conversation-flows.index') }}">Limpar</a>@can('conversation_automation.manage_flows')<a class="btn" href="{{ route('admin.conversation-flows.create') }}">Novo fluxo</a>@endcan</div>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Status</th><th>Perguntas</th><th>Conversas</th><th>Atualizado em</th><th>Acoes</th></tr></thead>
                <tbody>
                    @forelse($flows as $flow)
                        <tr>
                            <td>{{ $flow->name }}<br><span class="muted">{{ $flow->description }}</span></td>
                            <td>{{ $flow->status->label() }}</td>
                            <td>{{ $flow->questions_count }}</td>
                            <td>{{ $flow->states_count }}</td>
                            <td>{{ $flow->updated_at?->format($dateTimeFormat) }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.conversation-flows.show', $flow) }}">Ver</a>@can('conversation_automation.manage_flows')<a class="btn ghost" href="{{ route('admin.conversation-flows.edit', $flow) }}">Editar</a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Nenhum fluxo encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $flows->links() }}
    </section>
</x-layouts.app>
