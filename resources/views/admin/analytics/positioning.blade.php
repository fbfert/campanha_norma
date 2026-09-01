<x-layouts.app title="Posicionamento" breadcrumbs="Inicio / Relatorios / Posicionamento">
    @include('admin.analytics.partials.filters')
    @include('admin.analytics.partials.print-cover', ['printTitle' => 'Posicionamento'])

    <div class="card actions" x-data>
        <button class="btn" type="button" x-on:click="window.print()">
            <x-icon name="download" size="16" /> Imprimir ou salvar em PDF
        </button>
    </div>

    <section class="card">
        <h2>O que esta tela responde</h2>
        <p class="muted">
            Sobre o que a campanha ainda não tem posição escrita, ordenado pelo que mais apareceu. Cada linha é
            um tema que as pessoas citaram no período; a coluna de documentos aprovados diz se existe material
            oficial respondendo a ele. <strong>Zero é o achado.</strong>
        </p>
        <p class="muted">
            Conta apenas documento <strong>aprovado</strong>, em base <strong>ativa</strong>. Indexar não aprova:
            a separação existe porque alguém precisa ter decidido que aquilo pode ser dito a uma pessoa. E
            documento aprovado numa base desligada não responde a ninguém.
        </p>
    </section>

    <section class="card">
        <h2>Temas citados e a posição escrita</h2>

        @if($gaps === [])
            @include('admin.analytics.partials.empty', ['message' => 'Nenhum tema citado no período selecionado.'])
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tema</th>
                            <th>Menções</th>
                            <th>Urgência predominante</th>
                            <th>Documentos aprovados</th>
                            <th>Orientação escrita</th>
                            <th>Linha vermelha escrita</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($gaps as $linha)
                            <tr @class(['row-alert' => $linha['is_gap']])>
                                <td>{{ $linha['name'] }}</td>
                                <td>{{ $linha['mentions'] }}</td>
                                <td>{{ $linha['urgency'] ? \App\Enums\InsightUrgency::from($linha['urgency'])->label() : '—' }}</td>
                                <td>{{ $linha['approved_documents'] }}</td>
                                <td>{{ $linha['has_guidance'] ? 'sim' : 'não' }}</td>
                                <td>{{ $linha['has_red_lines'] ? 'sim' : 'não' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="muted">
                As duas últimas colunas são o trabalho humano que nenhum passo automatiza: a orientação e a
                linha vermelha de cada tema, escritas uma vez, são o que transforma o dossiê da pauta de
                "resumo do que o eleitor disse" em roteiro que protege quem responde.
            </p>
        @endif
    </section>
</x-layouts.app>
