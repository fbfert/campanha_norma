<x-layouts.guest>
    <h2>Entrar</h2>
    <form method="post" action="{{ route('login') }}">
        @csrf
        <p>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </p>
        <p>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </p>
        <p>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;">
                <input type="checkbox" name="remember" value="1" style="width:auto;min-height:auto;">
                Lembrar de mim
            </label>
        </p>
        <div class="actions" style="justify-content:space-between;">
            <a href="{{ route('password.request') }}">Esqueci minha senha</a>
            <button class="btn" type="submit">Entrar</button>
        </div>
    </form>
</x-layouts.guest>
