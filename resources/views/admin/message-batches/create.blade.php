<x-layouts.app title="Novo lote" breadcrumbs="Inicio / Mensagens / Lotes / Novo">
    <form method="post" action="{{ route('admin.message-batches.store') }}">
        @csrf
        @include('admin.message-batches._form', ['batch' => new \App\Models\MessageBatch])
        <div class="actions" style="margin-top:16px;"><button class="btn" type="submit">Criar rascunho e validar</button><a class="btn ghost" href="{{ route('admin.message-batches.index') }}">Cancelar</a></div>
    </form>
</x-layouts.app>
