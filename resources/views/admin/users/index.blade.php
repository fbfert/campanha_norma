<x-layouts.app title="Usuários" breadcrumbs="Inicio / Usuarios">
    <div class="actions" style="justify-content:space-between;margin-bottom:16px;">
        <form method="get" class="card" style="flex:1;">
            <div class="grid grid-3">
                <p><label>Nome</label><input name="name" value="{{ $filters['name'] ?? '' }}"></p>
                <p><label>E-mail</label><input name="email" value="{{ $filters['email'] ?? '' }}"></p>
                <p><label>Perfil</label><select name="role"><option value="">Todos</option>@foreach($roles as $role)<option value="{{ $role->slug }}" @selected(($filters['role'] ?? '') === $role->slug)>{{ $role->name }}</option>@endforeach</select></p>
                <p><label>Status</label><select name="status"><option value="">Todos</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></p>
            </div>
            <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
            <a class="btn ghost" href="{{ route('admin.users.index') }}">Limpar</a>
        </form>
        @can('manage-users')
            <a class="btn" href="{{ route('admin.users.create') }}">Cadastrar usuário</a>
        @endcan
    </div>
    <section class="card table-wrap">
        @if ($users->isEmpty())
            <p class="muted">Nenhum usuário encontrado.</p>
        @else
            <table>
                <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Último acesso</th><th>Cadastro</th><th>Ações</th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->roles->pluck('name')->join(', ') }}</td>
                        <td>{{ $user->status->label() }}</td>
                        <td>{{ $user->last_login_at?->format($dateTimeFormat) ?? '-' }}</td>
                        <td>{{ $user->created_at->format($dateFormat) }}</td>
                        <td class="actions">
                            <a class="btn ghost" href="{{ route('admin.users.show', $user) }}">Ver</a>
                            @can('manage-users')
                                <a class="btn ghost" href="{{ route('admin.users.edit', $user) }}">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $users->links() }}
        @endif
    </section>
</x-layouts.app>
