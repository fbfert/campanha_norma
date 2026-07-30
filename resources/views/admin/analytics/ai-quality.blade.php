<x-layouts.app title="Qualidade da IA" breadcrumbs="Inicio / Relatorios / Qualidade da IA">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <h2>Desfecho das sugestoes</h2>
        @if($suggestions['total'] === 0)
            @include('admin.analytics.partials.empty', ['message' => 'Nenhuma sugestao foi gerada no periodo.'])
        @else
            <table><tbody>
                <tr><th>Geradas</th><td>{{ $suggestions['total'] }}</td></tr>
                <tr><th>Pendentes</th><td>{{ $suggestions['pending'] }}</td></tr>
                <tr><th>Aprovadas sem edicao</th><td>{{ $suggestions['approved_without_edit'] }}</td></tr>
                <tr><th>Aprovadas com edicao</th><td>{{ $suggestions['approved_with_edit'] }}</td></tr>
                <tr><th>Recusadas</th><td>{{ $suggestions['rejected'] }}</td></tr>
                <tr><th>Bloqueadas</th><td>{{ $suggestions['blocked'] }}</td></tr>
                <tr><th>Expiradas</th><td>{{ $suggestions['expired'] }}</td></tr>
                <tr><th>Falhas</th><td>{{ $suggestions['failed'] }}</td></tr>
            </tbody></table>
            <table><tbody>
                <tr><th>Aprovacao sem edicao</th><td>@include('admin.analytics.partials.rate', ['rate' => $suggestions['rates']['approved_without_edit']])</td>
                    <td class="muted">Edicao constante e o sinal mais barato de que o prompt esta errado.</td></tr>
                <tr><th>Recusa</th><td>@include('admin.analytics.partials.rate', ['rate' => $suggestions['rates']['rejection']])</td>
                    <td class="muted">Recusadas sobre as que alguem decidiu.</td></tr>
                <tr><th>Handoff</th><td>@include('admin.analytics.partials.rate', ['rate' => $suggestions['rates']['handoff']])</td>
                    <td class="muted">Encaminhadas a humano sobre o total gerado.</td></tr>
            </tbody></table>
        @endif
    </section>

    <section class="card">
        <h2>Correcao humana de classificacao</h2>
        <table><tbody>
            <tr><th>Insights revisados</th><td>{{ $corrections['reviewed'] }}</td></tr>
            <tr><th>Corrigidos</th><td>{{ $corrections['corrected'] }}</td></tr>
            <tr><th>Taxa de correcao</th><td>@include('admin.analytics.partials.rate', ['rate' => $corrections['rate']])</td></tr>
        </tbody></table>
        <p class="muted">Quem ninguem olhou fica fora dos dois lados: nao foi corrigido nem confirmado.</p>
    </section>

    @foreach(['handoff' => 'Motivos de encaminhamento', 'blocked' => 'Motivos de bloqueio', 'grounding' => 'Vereditos de fundamentacao', 'feedback' => 'Retorno da equipe'] as $key => $title)
        @if($reasons[$key] !== [])
            <section class="card">
                <h2>{{ $title }}</h2>
                <table><tbody>@foreach($reasons[$key] as $row)
                    <tr><th>{{ $row['label'] }}</th><td>{{ $row['total'] }}</td></tr>
                @endforeach</tbody></table>
            </section>
        @endif
    @endforeach

    <section class="card">
        <h2>Execucoes por provedor, modelo e versao</h2>
        @unless($canSeeCosts)
            <p class="muted">Colunas de custo omitidas: exigem a permissao de ver custos. A qualidade continua legivel sem elas.</p>
        @endunless
        @if($runs === [])
            @include('admin.analytics.partials.empty')
        @else
            <table>
                <thead><tr><th>Finalidade</th><th>Provedor</th><th>Modelo</th><th>Prompt</th><th>Execucoes</th><th>Falhas</th><th>Latencia media</th>
                    @if($canSeeCosts)<th>Tokens</th><th>Custo</th>@endif</tr></thead>
                <tbody>@foreach($runs as $row)
                    <tr>
                        <td>{{ $row['purpose'] }}</td><td>{{ $row['provider'] }}</td><td>{{ $row['model'] }}</td><td>{{ $row['prompt_version'] }}</td>
                        <td>{{ $row['total'] }}</td>
                        <td>{{ $row['failures'] }} (@include('admin.analytics.partials.rate', ['rate' => $row['failure_rate']]))</td>
                        <td>{{ $row['average_latency'] === null ? '—' : $row['average_latency'].' ms' }}</td>
                        @if($canSeeCosts)<td>{{ $row['tokens'] ?? '—' }}</td><td>{{ $row['cost'] === null ? '—' : number_format((float) $row['cost'], 4, ',', '.') }}</td>@endif
                    </tr>
                @endforeach</tbody>
            </table>
            <p class="muted">Comparar versoes lado a lado e o proposito desta tabela. Nenhuma versao e promovida automaticamente: mudar o que responde a cidadao e decisao humana.</p>
        @endif
    </section>
</x-layouts.app>
