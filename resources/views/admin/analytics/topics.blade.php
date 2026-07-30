<x-layouts.app title="Temas" breadcrumbs="Inicio / Relatorios / Temas">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <h2>Temas mais mencionados</h2>
        @if($mostMentioned === [])
            @include('admin.analytics.partials.empty')
        @else
            <table>
                <thead><tr><th>Tema</th><th>Menções</th><th>Confiança média</th><th>Revisados por humano</th></tr></thead>
                <tbody>
                @foreach($mostMentioned as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td>
                        <td>{{ $row['average_confidence'] === null ? '—' : number_format($row['average_confidence'], 2, ',', '.') }}</td>
                        <td>{{ $row['reviewed'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @include('admin.analytics.partials.suppression')
        @endif
        <p class="muted">Insights sem tema atribuído no período: <strong>{{ $unclassified }}</strong>. Contados a parte para não esconder falha de classificação dentro de um tema legítimo.</p>
    </section>

    <section class="card">
        <h2>Temas emergentes</h2>
        <p class="muted">Aparecem neste período e não apareciam no anterior.</p>
        @if($emerging === [])
            @include('admin.analytics.partials.empty', ['message' => 'Nenhum tema novo no período.'])
        @else
            <ul>@foreach($emerging as $row)<li>{{ $row['name'] }} — {{ $row['total'] }} menção(oes)</li>@endforeach</ul>
        @endif
    </section>

    <section class="card">
        <h2>Qualidade da extração</h2>
        <table><tbody>
            <tr><th>Total de insights</th><td>{{ $quality['total'] }}</td></tr>
            <tr><th>Revisados por humano</th><td>{{ $quality['reviewed'] }}</td></tr>
            <tr><th>Abaixo da confiança mínima ({{ $quality['threshold'] }})</th><td>{{ $quality['low_confidence'] }}</td></tr>
            <tr><th>Marcados para revisão</th><td>{{ $quality['needs_review'] }}</td></tr>
            <tr><th>Confiança média</th><td>{{ $quality['average_confidence'] === null ? '—' : number_format($quality['average_confidence'], 3, ',', '.') }}</td></tr>
        </tbody></table>
    </section>

    @can('analytics.export_aggregates')
        <section class="card">
            <h2>Exportar</h2>
            <form method="post" action="{{ route('admin.analytics.export') }}">
                @csrf
                <input type="hidden" name="type" value="topics">
                <input type="hidden" name="scope" value="aggregate">
                <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                <input type="hidden" name="flow" value="{{ $flowId }}">
                <label for="format">Formato</label>
                <select id="format" name="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
                <button class="btn" type="submit">Exportar agregado</button>
                <p class="muted">A exportação agregada leva contagem e rótulo. Não leva nome, telefone nem texto de mensagem.</p>
            </form>
        </section>
    @endcan
</x-layouts.app>
