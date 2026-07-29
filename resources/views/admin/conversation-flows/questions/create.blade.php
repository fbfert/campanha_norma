<x-layouts.app title="Nova pergunta" breadcrumbs="Inicio / Pesquisa conversacional / Fluxos / Nova pergunta">
    <section class="card">
        <form method="post" action="{{ route('admin.conversation-flows.questions.store', $flow) }}">
            @csrf
            @include('admin.conversation-flows.questions._form')
            <div class="actions" style="margin-top:12px;"><button class="btn" type="submit">Salvar</button><a class="btn ghost" href="{{ route('admin.conversation-flows.show', $flow) }}">Cancelar</a></div>
        </form>
    </section>
</x-layouts.app>
