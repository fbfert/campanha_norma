<x-layouts.app title="Relatório de conversas" breadcrumbs="Relatorios / Conversas">
    <section class="card">
        <h2>Indicadores da caixa de entrada</h2>
        <div class="stats-grid">
            @foreach($totals as $label => $value)
                <div class="stat"><span>{{ str_replace('_', ' ', $label) }}</span><strong>{{ $value }}</strong></div>
            @endforeach
        </div>
        <p class="muted">Estes indicadores representam atendimento manual e mensagens recebidas. Não representam conversão, interesse ou sentimento.</p>
    </section>
</x-layouts.app>
