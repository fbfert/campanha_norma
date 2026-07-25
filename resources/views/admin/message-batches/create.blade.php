@php($isCampaign = $campaignMode ?? false)
<x-layouts.app :title="$isCampaign ? 'Nova campanha' : 'Novo lote'" :breadcrumbs="$isCampaign ? 'Inicio / Mensagens / Campanha' : 'Inicio / Mensagens / Lotes / Novo'">
    <form method="post" action="{{ route('admin.message-batches.store') }}">
        @csrf
        @include('admin.message-batches._form', ['batch' => new \App\Models\MessageBatch])
        <div class="actions" style="margin-top:16px;"><button class="btn" type="submit">{{ $isCampaign ? 'Criar campanha e sortear mensagens' : 'Criar rascunho e validar' }}</button><a class="btn ghost" href="{{ route('admin.message-batches.index') }}">Cancelar</a></div>
    </form>
</x-layouts.app>
