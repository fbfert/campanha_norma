<x-layouts.impressao
    title="Caderno de resposta"
    :periodo="$periodo"
    :fluxo="$fluxo"
    :amostra="$amostra"
    :nominal="true"
    :voltar="route('admin.pauta.index')"
>
    @forelse($dossies as $dossie)
        {{-- Um dossiê por página. A quebra é regra de impressão, e não margem
             inventada aqui: no navegador as páginas continuam rolando. --}}
        <section class="card folha-pessoa">
            <h2>
                {{ $dossie['name'] ?? 'Sem nome cadastrado' }}
                <span class="folha-telefone">{{ $dossie['phone'] ?: 'sem telefone cadastrado' }}</span>
            </h2>
            <p class="muted">
                {{ $dossie['city'] ?? 'cidade não cadastrada' }}
                @if($dossie['declared_locality'])
                    — declarou morar em {{ $dossie['declared_locality'] }}
                @endif
                — tema: {{ $dossie['topic'] ?? 'sem tema atribuído' }}
                — urgência: {{ $dossie['urgency']?->label() ?? 'não informada' }}
            </p>

            @if($dossie['low_confidence'])
                <p class="alert alert-error">
                    <strong>Confiança baixa ({{ number_format((float) $dossie['confidence'], 2, ',', '.') }}).</strong>
                    Confira a mensagem original antes de responder.
                </p>
            @endif

            <h3>O que ela escreveu</h3>
            <blockquote class="manual-quote">{{ $dossie['sentence'] ?: 'A mensagem de origem não tem texto.' }}</blockquote>

            <h3>O que ela levantou</h3>
            <table>
                <tbody>
                    <tr><th>Problema identificado</th><td>{{ $dossie['identified_problem'] ?: '—' }}</td></tr>
                    <tr><th>Ação sugerida</th><td>{{ $dossie['suggested_action'] ?: '—' }}</td></tr>
                    <tr><th>Resultado desejado</th><td>{{ $dossie['desired_result'] ?: '—' }}</td></tr>
                </tbody>
            </table>

            <h3>O que a campanha já defende</h3>
            @if($dossie['response_guidance'])
                <p>{{ $dossie['response_guidance'] }}</p>
            @else
                <p class="muted">Nenhuma orientação escrita para este tema.</p>
            @endif

            @if($dossie['official_excerpt'])
                <blockquote class="manual-quote">{{ $dossie['official_excerpt'] }}</blockquote>
            @endif

            <h3>Linha vermelha — o que não prometer</h3>
            @if($dossie['red_lines'])
                <p class="alert alert-error"><strong>{{ $dossie['red_lines'] }}</strong></p>
            @else
                <p class="alert alert-error">
                    <strong>Nenhuma linha vermelha escrita para este tema.</strong>
                    Não quer dizer que não haja: quer dizer que ninguém escreveu ainda.
                </p>
            @endif

            <p class="folha-marca">
                Gerado por {{ auth()->user()?->name }} em {{ now()->format('d/m/Y H:i') }} — documento nominal.
            </p>
        </section>
    @empty
        <section class="card">
            <p class="muted">Nenhuma pessoa nesta combinação de filtros. Isso é ausência de registro, não falha do relatório.</p>
        </section>
    @endforelse
</x-layouts.impressao>
