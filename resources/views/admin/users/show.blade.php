<x-layouts.app title="Usuário" breadcrumbs="Inicio / Usuarios / Detalhes">
    @if (session('temporary_password'))
        <div class="alert success"><strong>Senha temporária:</strong> <code>{{ session('temporary_password') }}</code></div>
    @endif
    <section class="card">
        <h2>{{ $user->name }}</h2>
        <p><strong>E-mail:</strong> {{ $user->email }}</p>
        <p><strong>Perfil:</strong> {{ $user->roles->pluck('name')->join(', ') }}</p>
        <p><strong>Status:</strong> {{ $user->status->label() }}</p>
        <p><strong>Deve alterar senha:</strong> {{ $user->must_change_password ? 'Sim' : 'Não' }}</p>
        <p><strong>Último acesso:</strong> {{ $user->last_login_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
        <div class="actions">
            <a class="btn ghost" href="{{ route('admin.users.index') }}">Voltar</a>
            @can('manage-users')
                <a class="btn" href="{{ route('admin.users.edit', $user) }}">Editar</a>
                <form method="post" action="{{ route('admin.users.status', $user) }}">@csrf @method('patch')<input type="hidden" name="status" value="active"><button class="btn ghost" type="submit">Ativar</button></form>
                <form method="post" action="{{ route('admin.users.status', $user) }}" onsubmit="return confirm('Inativar este usuario?')">@csrf @method('patch')<input type="hidden" name="status" value="inactive"><button class="btn secondary" type="submit">Inativar</button></form>
                <form method="post" action="{{ route('admin.users.status', $user) }}" onsubmit="return confirm('Bloquear este usuario?')">@csrf @method('patch')<input type="hidden" name="status" value="blocked"><button class="btn danger" type="submit">Bloquear</button></form>
                <form method="post" action="{{ route('admin.users.reset-password', $user) }}" onsubmit="return confirm('Gerar senha temporaria para este usuario?')">@csrf @method('patch')<button class="btn secondary" type="submit">Redefinir senha</button></form>
                <form method="post" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Excluir logicamente este usuario?')">@csrf @method('delete')<button class="btn danger" type="submit">Excluir</button></form>
            @endcan
        </div>
    </section>
</x-layouts.app>
