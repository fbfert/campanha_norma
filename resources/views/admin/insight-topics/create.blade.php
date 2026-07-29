<x-layouts.app title="Novo tema" breadcrumbs="Inicio / Pesquisa conversacional / Temas / Novo">
    <section class="card">
        <form method="post" action="{{ route('admin.insight-topics.store') }}">
            @include('admin.insight-topics._form')
            <div class="actions" style="margin-top:16px;">
                <button class="btn" type="submit">Criar tema</button>
                <a class="btn ghost" href="{{ route('admin.insight-topics.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
