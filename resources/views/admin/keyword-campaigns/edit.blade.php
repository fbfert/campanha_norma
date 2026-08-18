<x-layouts.app title="Editar campanha" breadcrumbs="Inicio / Campanhas por palavra-chave / Editar">
    <form method="post" action="{{ route('admin.keyword-campaigns.update', $campaign) }}">
        @csrf
        @method('put')
        @include('admin.keyword-campaigns._form')
        <div class="actions" style="margin-top:16px;">
            <button class="btn" type="submit">Salvar campanha</button>
            <a class="btn ghost" href="{{ route('admin.keyword-campaigns.index') }}">Cancelar</a>
        </div>
    </form>
</x-layouts.app>
