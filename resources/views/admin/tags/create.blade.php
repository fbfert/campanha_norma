<x-layouts.app title="Nova etiqueta" breadcrumbs="Inicio / Contatos / Etiquetas / Nova">
    <section class="card">
        <form method="post" action="{{ route('admin.tags.store') }}">
            @csrf
            @include('admin.tags._form')
            <button class="btn" type="submit">Salvar etiqueta</button>
            <a class="btn ghost" href="{{ route('admin.tags.index') }}">Cancelar</a>
        </form>
    </section>
</x-layouts.app>
