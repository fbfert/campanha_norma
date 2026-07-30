<x-layouts.app title="Relatório de contatos" breadcrumbs="Relatorios / Contatos">
    <section class="card"><div class="stats-grid">@foreach($totals as $label => $value)<div class="stat"><span>{{ str_replace('_', ' ', $label) }}</span><strong>{{ $value }}</strong></div>@endforeach</div><p class="muted">Estes dados não representam engajamento ou resposta, pois respostas ainda não foram implementadas.</p></section>
</x-layouts.app>
