<x-layouts.app title="Editar lote" breadcrumbs="Inicio / Mensagens / Lotes / Editar">
    <form method="post" action="{{ route('admin.message-batches.update', $batch) }}">
        @csrf @method('put')
        @include('admin.message-batches._form')
        <div class="actions" style="margin-top:16px;"><button class="btn" type="submit">Salvar e validar novamente</button><a class="btn ghost" href="{{ route('admin.message-batches.show', $batch) }}">Cancelar</a></div>
    </form>
</x-layouts.app>
