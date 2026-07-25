<x-layouts.app title="Relatorio de mensagens" breadcrumbs="Relatorios / Mensagens">
    <section class="card"><div class="stats-grid">@foreach($totals as $label => $value)<div class="stat"><span>{{ str_replace('_', ' ', $label) }}</span><strong>{{ $value === null ? '—' : $value }}</strong></div>@endforeach</div></section>
</x-layouts.app>
