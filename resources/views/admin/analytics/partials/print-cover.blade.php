{{-- Capa que só aparece impressa. Ver `.so-impressa` em resources/css/app.css. --}}
<section class="folha-capa so-impressa">
    <h1>{{ $printTitle }}</h1>
    <dl>
        <dt>Período</dt><dd>{{ $from->format('d/m/Y') }} a {{ $to->format('d/m/Y') }}</dd>
        <dt>Fluxo</dt><dd>{{ $flowId === null ? 'todos os fluxos' : ($flows->firstWhere('id', $flowId)?->name ?? $flowId) }}</dd>
        <dt>Respostas na amostra</dt><dd>{{ $amostra }}</dd>
        <dt>Gerado em</dt><dd>{{ now()->format('d/m/Y H:i') }}</dd>
        <dt>Gerado por</dt><dd>{{ auth()->user()?->name }}</dd>
    </dl>
    <p class="folha-aviso">
        Este material é escuta de demanda. <strong>Não é pesquisa eleitoral registrada</strong>
        e não pergunta intenção de voto.
    </p>
</section>
