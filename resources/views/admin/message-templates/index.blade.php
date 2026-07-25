<x-layouts.app title="Modelos" breadcrumbs="Inicio / Mensagens / Modelos">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="q">Nome</label><input id="q" name="q" value="{{ request('q') }}"></div>
            <div><label for="status">Status</label><select id="status" name="status"><option value="">Todos</option><option value="active" @selected(request('status') === 'active')>Ativo</option><option value="inactive" @selected(request('status') === 'inactive')>Inativo</option></select></div>
            <div class="actions"><button class="btn" type="submit">Filtrar</button><a class="btn ghost" href="{{ route('admin.message-templates.index') }}">Limpar</a>@can('message_templates.create')<a class="btn" href="{{ route('admin.message-templates.create') }}">Novo modelo</a>@endcan</div>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Status</th><th>Versao</th><th>Placeholders</th><th>Criador</th><th>Atualizado em</th><th>Acoes</th></tr></thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td>{{ $template->name }}<br><span class="muted">{{ $template->description }}</span></td>
                            <td>{{ $template->status->label() }}</td>
                            <td>{{ $template->version }}</td>
                            <td>{{ implode(', ', $parser->parse($template->body)['valid']) ?: 'Nenhum' }}</td>
                            <td>{{ $template->creator?->name ?? '-' }}</td>
                            <td>{{ $template->updated_at?->format($dateTimeFormat) }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.message-templates.show', $template) }}">Ver</a>@can('message_templates.update')<a class="btn ghost" href="{{ route('admin.message-templates.edit', $template) }}">Editar</a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Nenhum modelo encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $templates->links() }}
    </section>
</x-layouts.app>
