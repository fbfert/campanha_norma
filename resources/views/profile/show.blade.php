<x-layouts.app title="Perfil" breadcrumbs="Inicio / Perfil">
    <div class="grid grid-2">
        <section class="card">
            <h2>Dados do perfil</h2>
            <form method="post" action="{{ route('profile.update') }}">
                @csrf
                @method('put')
                <p><label>Nome</label><input name="name" value="{{ old('name', $user->name) }}" required></p>
                <p><label>E-mail</label><input value="{{ $user->email }}" disabled></p>
                <p><label>Perfil</label><input value="{{ $user->roles->pluck('name')->join(', ') }}" disabled></p>
                <p><label>Último acesso</label><input value="{{ $user->last_login_at?->format($dateTimeFormat) ?? 'Sem registro' }}" disabled></p>
                <button class="btn" type="submit">Salvar perfil</button>
            </form>
        </section>
        <section class="card">
            <h2>Alterar senha</h2>
            <form method="post" action="{{ route('profile.password') }}">
                @csrf
                @method('put')
                <p><label>Senha atual</label><input name="current_password" type="password" required></p>
                <p><label>Nova senha</label><input name="password" type="password" required></p>
                <p><label>Confirmar nova senha</label><input name="password_confirmation" type="password" required></p>
                <button class="btn" type="submit">Alterar senha</button>
            </form>
        </section>
    </div>
</x-layouts.app>
