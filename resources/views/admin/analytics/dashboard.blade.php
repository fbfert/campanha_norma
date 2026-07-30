<x-layouts.app title="Painel da pesquisa" breadcrumbs="Inicio / Relatorios / Painel da pesquisa">
    @include('admin.analytics.partials.filters')

    @php($t = $overview['totals'])
    <section class="card">
        <h2>Participação</h2>
        @if($t['approached'] === 0)
            @include('admin.analytics.partials.empty', ['message' => 'Nenhuma conversa foi iniciada pelo fluxo neste período.'])
        @else
            <table>
                <tbody>
                    <tr><th>Contatos abordados</th><td>{{ $t['approached'] }}</td></tr>
                    <tr><th>Permissões concedidas</th><td>{{ $t['permission_granted'] }}</td></tr>
                    <tr><th>Permissões negadas</th><td>{{ $t['permission_denied'] }}</td></tr>
                    <tr><th>Opt-outs</th><td>{{ $t['opted_out'] }}</td></tr>
                    <tr><th>Respostas recebidas</th><td>{{ $t['answers_received'] }}</td></tr>
                    <tr><th>Conversas concluidas</th><td>{{ $t['completed'] }}</td></tr>
                    <tr><th>Aguardando humano</th><td>{{ $t['waiting_human'] }}</td></tr>
                    <tr><th>Falhas</th><td>{{ $t['failed'] }}</td></tr>
                </tbody>
            </table>
        @endif
    </section>

    <section class="card">
        <h2>Taxas</h2>
        <p class="muted">Cada taxa mostra o par que a formou. Percentual sem denominador visível esconde o tamanho da amostra.</p>
        <table>
            <tbody>
                <tr><th>Taxa de permissão</th><td>@include('admin.analytics.partials.rate', ['rate' => $overview['rates']['permission']])</td>
                    <td class="muted">Concedidas sobre quem respondeu ao pedido. Quem não respondeu fica fora.</td></tr>
                <tr><th>Taxa de resposta</th><td>@include('admin.analytics.partials.rate', ['rate' => $overview['rates']['response']])</td>
                    <td class="muted">Respostas sobre permissões concedidas.</td></tr>
                <tr><th>Taxa de conclusão</th><td>@include('admin.analytics.partials.rate', ['rate' => $overview['rates']['completion']])</td>
                    <td class="muted">Concluidas sobre abordados.</td></tr>
                <tr><th>Taxa de opt-out</th><td>@include('admin.analytics.partials.rate', ['rate' => $overview['rates']['opt_out']])</td>
                    <td class="muted">Opt-outs sobre abordados.</td></tr>
                <tr><th>Taxa de handoff</th><td>@include('admin.analytics.partials.rate', ['rate' => $overview['rates']['handoff']])</td>
                    <td class="muted">Aguardando humano sobre abordados.</td></tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Ritmo</h2>
        <table>
            <tbody>
                <tr><th>Tempo médio até a primeira resposta</th>
                    <td>{{ $overview['first_reply_seconds']['average'] === null ? '—' : gmdate('H:i:s', (int) $overview['first_reply_seconds']['average']) }}</td>
                    <td class="muted">Média sobre {{ $overview['first_reply_seconds']['samples'] }} conversa(s) que responderam. Silêncio fica fora da média.</td></tr>
                <tr><th>Média de turnos automáticos</th>
                    <td>{{ $overview['average_turns'] === null ? '—' : number_format($overview['average_turns'], 2, ',', '.') }}</td>
                    <td class="muted">Mensagens automáticas sobre conversas que receberam ao menos uma.</td></tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Comparação com o período anterior</h2>
        <p class="muted">Dois números lado a lado são um fato. A explicação para a diferença não esta nestes dados.</p>
        <table>
            <thead><tr><th>Indicador</th><th>Período</th><th>Anterior</th><th>Diferença</th></tr></thead>
            <tbody>
                @foreach(['approached' => 'Abordados', 'permission_granted' => 'Permissões', 'answers_received' => 'Respostas', 'completed' => 'Concluidas', 'opted_out' => 'Opt-outs'] as $key => $label)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>{{ $comparison['current'][$key] }}</td>
                        <td>{{ $comparison['previous'][$key] }}</td>
                        <td>{{ $comparison['difference'][$key] > 0 ? '+' : '' }}{{ $comparison['difference'][$key] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
