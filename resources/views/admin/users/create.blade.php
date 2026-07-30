<x-layouts.app title="Cadastrar usuário" breadcrumbs="Inicio / Usuarios / Cadastrar">
    <section class="card">
        <form method="post" action="{{ route('admin.users.store') }}">
            @csrf
            @include('admin.users._form', ['creating' => true])
            <div class="actions">
                <button class="btn" type="submit">Cadastrar</button>
                <a class="btn ghost" href="{{ route('admin.users.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
