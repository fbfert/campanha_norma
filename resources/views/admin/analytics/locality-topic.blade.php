<x-layouts.app title="Cidade e tema" breadcrumbs="Inicio / Relatorios / Cidade e tema">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <h2>O que esta tela responde</h2>
        <p class="muted">
            Onde as pessoas estão, cruzado com o que elas falaram. É o recorte que o painel de temas e o de
            geografia não dão isolados: saber que saúde foi o assunto mais citado não diz em que cidade.
        </p>
        <p class="muted">
            Cruzar dois eixos divide os mesmos registros por muito mais células, e por isso a supressão derruba
            aqui muito mais do que numa tabela simples. Célula abaixo de {{ $minimumCell }} registros aparece
            como suprimida, e ela continua na linha: tirá-la faria a soma das colunas não bater com o total, e
            quem lesse concluiria que falta registro. <strong>Isso é a regra funcionando, não falta de dado.</strong>
        </p>
    </section>

    @foreach([
        ['titulo' => 'Por localidade declarada', 'dados' => $byLocality, 'coluna' => 'Localidade',
         'nota' => 'A localidade é a que a própria pessoa declarou na resposta. Nada é deduzido de DDD, de nome ou de qualquer outro sinal: o DDD diz onde a linha foi habilitada, não onde alguém mora.'],
        ['titulo' => 'Por região', 'dados' => $byRegion, 'coluna' => 'Região',
         'nota' => 'Mesmo cruzamento, agrupado pela região declarada.'],
    ] as $secao)
        <section class="card">
            <h2>{{ $secao['titulo'] }}</h2>
            <p class="muted">{{ $secao['nota'] }}</p>

            @if($secao['dados']['rows'] === [])
                @include('admin.analytics.partials.empty')
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ $secao['coluna'] }}</th>
                                @foreach($secao['dados']['topics'] as $tema)
                                    <th>{{ $tema }}</th>
                                @endforeach
                                <th>Total da linha</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($secao['dados']['rows'] as $linha)
                                <tr>
                                    <td>{{ $linha['locality'] }}</td>
                                    @foreach($secao['dados']['topics'] as $tema)
                                        <td>{{ $linha['cells'][$tema]['suppressed'] ? 'suprimido' : $linha['cells'][$tema]['total'] }}</td>
                                    @endforeach
                                    <td>{{ $linha['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Total da coluna</th>
                                @foreach($secao['dados']['topics'] as $tema)
                                    <th>{{ $secao['dados']['column_totals'][$tema] ?? 0 }}</th>
                                @endforeach
                                <th>{{ $secao['dados']['total'] }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @include('admin.analytics.partials.suppression')
            @endif

            {{-- Contados à parte, nunca distribuídos nem somados a "outros": quem não
                 disse onde mora não mora em nenhuma linha desta tabela, e empurrá-lo
                 para uma linha genérica inventaria uma localidade que ninguém declarou. --}}
            <p class="muted">
                Sem localidade declarada no período: <strong>{{ $secao['dados']['without_locality'] }}</strong>.
                Contados a parte, e nunca somados a nenhuma linha.
            </p>
        </section>
    @endforeach
</x-layouts.app>
