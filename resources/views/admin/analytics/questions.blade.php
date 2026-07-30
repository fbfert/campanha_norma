<x-layouts.app title="Qualidade das perguntas" breadcrumbs="Inicio / Relatorios / Qualidade das perguntas">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <p class="muted">
            O objetivo aqui e clareza e capacidade de coletar opinião. Não existe medida de efeito persuasivo, apoio
            declarado ou intenção de voto — o que estas colunas levam a fazer e reescrever a pergunta, nunca escolher a pessoa.
        </p>
    </section>

    <section class="card">
        <h2>Permissão por fluxo</h2>
        <p class="muted">A mensagem de apresentação e a única coisa que a pessoa leu antes de decidir. Taxa baixa aqui e problema do texto de apresentação, não das perguntas.</p>
        @if($permissionByFlow === [])
            @include('admin.analytics.partials.empty')
        @else
            <table>
                <thead><tr><th>Fluxo</th><th>Concedidas</th><th>Negadas</th><th>Opt-outs</th><th>Taxa</th></tr></thead>
                <tbody>@foreach($permissionByFlow as $row)
                    <tr><td>{{ $row['name'] }}</td><td>{{ $row['granted'] }}</td><td>{{ $row['denied'] }}</td><td>{{ $row['opted_out'] }}</td>
                        <td>@include('admin.analytics.partials.rate', ['rate' => $row['permission_rate']])</td></tr>
                @endforeach</tbody>
            </table>
        @endif
    </section>

    <section class="card">
        <h2>Por pergunta</h2>
        @if($byQuestion === [])
            @include('admin.analytics.partials.empty', ['message' => 'Nenhuma pergunta foi utilizada no período.'])
        @else
            <table>
                <thead><tr><th>Pergunta</th><th>Vezes</th><th>Taxa de resposta</th><th>Taxa de conclusão</th><th>Handoff</th><th>Tamanho médio</th></tr></thead>
                <tbody>@foreach($byQuestion as $row)
                    <tr>
                        <td>{{ $row['title'] }} @unless($row['is_active'])<span class="muted">(inativa)</span>@endunless</td>
                        <td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td>
                        <td>@include('admin.analytics.partials.rate', ['rate' => $row['response_rate']])</td>
                        <td>@include('admin.analytics.partials.rate', ['rate' => $row['completion_rate']])</td>
                        <td>@include('admin.analytics.partials.rate', ['rate' => $row['handoff_rate']])</td>
                        <td>{{ $row['average_answer_length'] ?? '—' }}</td>
                    </tr>
                @endforeach</tbody>
            </table>
            @include('admin.analytics.partials.suppression')
        @endif
    </section>
</x-layouts.app>
