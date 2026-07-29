<x-layouts.app title="Editar fluxo" breadcrumbs="Inicio / Pesquisa conversacional / Fluxos / Editar">
    <section class="card">
        <form method="post" action="{{ route('admin.conversation-flows.update', $flow) }}">
            @csrf @method('put')
            @include('admin.conversation-flows._form')
            <div class="actions" style="margin-top:12px;"><button class="btn" type="submit">Salvar</button><a class="btn ghost" href="{{ route('admin.conversation-flows.show', $flow) }}">Cancelar</a></div>
        </form>
    </section>
</x-layouts.app>
