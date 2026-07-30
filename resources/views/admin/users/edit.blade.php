<x-layouts.app title="Editar usuário" breadcrumbs="Inicio / Usuarios / Editar">
    <section class="card">
        <form method="post" action="{{ route('admin.users.update', $user) }}">
            @csrf
            @method('put')
            @include('admin.users._form')
            <div class="actions">
                <button class="btn" type="submit">Salvar</button>
                <a class="btn ghost" href="{{ route('admin.users.show', $user) }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
