<x-layouts.app title="Nova campanha" breadcrumbs="Inicio / Campanhas por palavra-chave / Nova">
    <form method="post" action="{{ route('admin.keyword-campaigns.store') }}">
        @csrf
        @include('admin.keyword-campaigns._form')
        <div class="actions" style="margin-top:16px;">
            <button class="btn" type="submit">Criar campanha</button>
            <a class="btn ghost" href="{{ route('admin.keyword-campaigns.index') }}">Cancelar</a>
        </div>
    </form>
</x-layouts.app>
