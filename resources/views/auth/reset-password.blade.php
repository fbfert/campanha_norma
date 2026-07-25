<x-layouts.guest>
    <h2>Redefinir senha</h2>
    <form method="post" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <p>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required>
        </p>
        <p>
            <label for="password">Nova senha</label>
            <input id="password" name="password" type="password" required>
        </p>
        <p>
            <label for="password_confirmation">Confirmar nova senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required>
        </p>
        <button class="btn" type="submit">Redefinir senha</button>
    </form>
</x-layouts.guest>
