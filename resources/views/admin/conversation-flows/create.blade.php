<x-layouts.app title="Novo fluxo" breadcrumbs="Inicio / Pesquisa conversacional / Fluxos / Novo">
    <section class="card">
        <form method="post" action="{{ route('admin.conversation-flows.store') }}">
            @csrf
            @include('admin.conversation-flows._form')
            <div class="actions" style="margin-top:12px;"><button class="btn" type="submit">Salvar</button><a class="btn ghost" href="{{ route('admin.conversation-flows.index') }}">Cancelar</a></div>
        </form>
    </section>
</x-layouts.app>
