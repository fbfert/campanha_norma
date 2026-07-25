<x-layouts.app title="Editar modelo" breadcrumbs="Inicio / Mensagens / Modelos / Editar">
    <section class="card">
        <form method="post" action="{{ route('admin.message-templates.update', $template) }}">
            @csrf @method('put')
            @include('admin.message-templates._form')
            <div class="actions" style="margin-top:12px;"><button class="btn" type="submit">Salvar</button><a class="btn ghost" href="{{ route('admin.message-templates.show', $template) }}">Cancelar</a></div>
        </form>
    </section>
</x-layouts.app>
