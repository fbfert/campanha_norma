@php($selectedRoles = collect(old('roles', isset($user) ? $user->roles->pluck('id')->all() : []))->map(fn ($id) => (int) $id)->all())
<p>
    <label for="name">Nome</label>
    <input id="name" name="name" value="{{ old('name', $user->name ?? '') }}" required>
</p>
<p>
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" value="{{ old('email', $user->email ?? '') }}" required>
</p>
@isset($creating)
    <p>
        <label for="password">Senha temporária</label>
        <input id="password" name="password" type="password" required>
    </p>
    <p>
        <label for="password_confirmation">Confirmar senha temporária</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required>
    </p>
@endisset
<p>
    <label for="status">Status</label>
    <select id="status" name="status" required>
        @foreach ($statuses as $status)
            <option value="{{ $status->value }}" @selected(old('status', isset($user) ? $user->status->value : 'active') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
</p>
<fieldset class="card" style="margin:0 0 16px;padding:12px;">
    <legend>Perfis</legend>
    @foreach ($roles as $role)
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;">
            <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, $selectedRoles, true)) style="width:auto;min-height:auto;">
            {{ $role->name }}
        </label>
    @endforeach
</fieldset>
