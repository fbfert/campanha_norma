<x-layouts.app title="Editar tema" breadcrumbs="Inicio / Pesquisa conversacional / Temas / Editar">
    <section class="card">
        <form method="post" action="{{ route('admin.insight-topics.update', $topic) }}">
            @method('put')
            @include('admin.insight-topics._form')
            <div class="actions" style="margin-top:16px;">
                <button class="btn" type="submit">Salvar</button>
                <a class="btn ghost" href="{{ route('admin.insight-topics.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
