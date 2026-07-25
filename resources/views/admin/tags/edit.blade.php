<x-layouts.app title="Editar etiqueta" breadcrumbs="Inicio / Contatos / Etiquetas / Editar">
    <section class="card">
        <form method="post" action="{{ route('admin.tags.update', $tag) }}">
            @csrf
            @method('put')
            @include('admin.tags._form')
            <button class="btn" type="submit">Salvar etiqueta</button>
            <a class="btn ghost" href="{{ route('admin.tags.index') }}">Cancelar</a>
        </form>
    </section>
</x-layouts.app>
