<x-layouts.guest>
    <h2>Recuperar senha</h2>
    <p class="muted">Informe seu e-mail para receber as instrucoes de redefinicao.</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <p>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        </p>
        <div class="actions">
            <a class="btn ghost" href="{{ route('login') }}">Voltar</a>
            <button class="btn" type="submit">Enviar instrucoes</button>
        </div>
    </form>
</x-layouts.guest>
