<x-layouts.guest>
    <h2>Alterar senha temporaria</h2>
    <p class="muted">Defina uma nova senha antes de acessar o sistema.</p>
    <form method="post" action="{{ route('password.force.update') }}">
        @csrf
        @method('put')
        <p>
            <label for="current_password">Senha atual</label>
            <input id="current_password" name="current_password" type="password" required>
        </p>
        <p>
            <label for="password">Nova senha</label>
            <input id="password" name="password" type="password" required>
        </p>
        <p>
            <label for="password_confirmation">Confirmar nova senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required>
        </p>
        <button class="btn" type="submit">Salvar nova senha</button>
    </form>
    <form method="post" action="{{ route('logout') }}" style="margin-top:12px;">
        @csrf
        <button class="btn secondary" type="submit">Sair</button>
    </form>
</x-layouts.guest>
