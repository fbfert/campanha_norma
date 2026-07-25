<x-layouts.app title="Novo modelo" breadcrumbs="Inicio / Mensagens / Modelos / Novo">
    <section class="card">
        <form method="post" action="{{ route('admin.message-templates.store') }}">
            @csrf
            @include('admin.message-templates._form')
            <div class="actions" style="margin-top:12px;"><button class="btn" type="submit">Salvar</button><a class="btn ghost" href="{{ route('admin.message-templates.index') }}">Cancelar</a></div>
        </form>
    </section>
</x-layouts.app>
