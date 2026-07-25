<x-layouts.app title="Caixa de entrada" breadcrumbs="Atendimento / Caixa de entrada">
    <section class="card">
        <form method="get" class="filters-grid">
            <label>Busca <input name="q" value="{{ request('q') }}" placeholder="Nome, telefone, cidade ou mensagem"></label>
            <label>Status
                <select name="status">
                    <option value="">Todos</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label>Responsavel
                <select name="assigned">
                    <option value="">Todos</option>
                    <option value="me" @selected(request('assigned') === 'me')>Atribuidas a mim</option>
                    <option value="none" @selected(request('assigned') === 'none')>Sem responsavel</option>
                </select>
            </label>
            <label><input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))> Somente nao lidas</label>
            <button class="btn" type="submit">Filtrar</button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Contato</th><th>Telefone</th><th>Ultima mensagem</th><th>Status</th><th>Prioridade</th><th>Responsavel</th><th>Nao lidas</th><th>Acoes</th></tr></thead>
                <tbody>
                    @forelse($conversations as $conversation)
                        @php($last = $conversation->messages->first())
                        <tr>
                            <td>{{ $conversation->contact?->name ?? 'Contato nao identificado' }}</td>
                            <td>{{ $conversation->contact?->phone_normalized ? Str::mask($conversation->contact->phone_normalized, '*', 4, -4) : '-' }}</td>
                            <td>@can('inbox.view_message_content'){{ Str::limit($last?->body, 70) }}@else Conteudo protegido @endcan</td>
                            <td>{{ $conversation->status->label() }}</td>
                            <td>{{ $conversation->priority->label() }}</td>
                            <td>{{ $conversation->assignee?->name ?? 'Sem responsavel' }}</td>
                            <td>{{ $conversation->unread_count }}</td>
                            <td><a class="btn small" href="{{ route('admin.inbox.show', $conversation) }}">Abrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhuma conversa encontrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $conversations->links() }}
    </section>
</x-layouts.app>
