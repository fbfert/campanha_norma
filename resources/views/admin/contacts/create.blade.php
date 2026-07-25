<x-layouts.app title="Novo contato" breadcrumbs="Inicio / Contatos / Novo">
    <section class="card">
        <form method="post" action="{{ route('admin.contacts.store') }}">
            @csrf
            @include('admin.contacts._form')
            <button class="btn" type="submit">Cadastrar contato</button>
            <a class="btn ghost" href="{{ route('admin.contacts.index') }}">Cancelar</a>
        </form>
    </section>
</x-layouts.app>
