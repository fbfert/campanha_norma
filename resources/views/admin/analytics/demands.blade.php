<x-layouts.app title="Demandas" breadcrumbs="Inicio / Relatorios / Demandas">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <h2>Fila de revisao</h2>
        <p class="muted">Itens abaixo de {{ $reviewQueue['threshold_used'] }}% de confianca nao entram nos totais como resultado assentado enquanto ninguem conferiu.</p>
        <table><tbody>
            <tr><th>Baixa confianca sem revisao</th><td>{{ $reviewQueue['low_confidence'] }}</td></tr>
            <tr><th>Marcados para revisao</th><td>{{ $reviewQueue['flagged'] }}</td></tr>
        </tbody></table>
    </section>

    @unless($canSeeContent)
        <section class="card">
            <h2>Problemas, acoes e resultados</h2>
            <p class="muted">
                Estas tres listas agrupam texto escrito pelas proprias pessoas. Agrupar uma frase e contar quantas vezes
                ela aparece nao a transforma em numero: o rotulo continua sendo a frase de alguem. Por isso exigem a
                permissao de ver conteudo. Urgencia e fila de revisao continuam visiveis abaixo.
            </p>
        </section>
    @endunless

    @foreach([['Problemas identificados', $problems], ['Acoes sugeridas', $actions], ['Resultados desejados', $results]] as [$title, $rows])
        @if($canSeeContent)
        <section class="card">
            <h2>{{ $title }}</h2>
            @if($rows === [])
                @include('admin.analytics.partials.empty')
            @else
                <table>
                    <thead><tr><th>Descricao</th><th>Quantidade</th></tr></thead>
                    <tbody>@foreach($rows as $row)
                        <tr><td>{{ $row['label'] }}</td><td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td></tr>
                    @endforeach</tbody>
                </table>
            @endif
        </section>
        @endif
    @endforeach

    <section class="card">
        <h2>Urgencia</h2>
        @if($byUrgency === [])
            @include('admin.analytics.partials.empty')
        @else
            <table><tbody>@foreach($byUrgency as $row)
                <tr><th>{{ $row['urgency'] }}</th><td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td></tr>
            @endforeach</tbody></table>
        @endif
    </section>

    <section class="card">
        <h2>Exemplos</h2>
        @unless($canSeeContent)
            <p class="muted">Exemplos sao texto escrito por pessoas e exigem a permissao de ver conteudo. Os agregados acima continuam disponiveis.</p>
        @else
            @if($examples === [])
                @include('admin.analytics.partials.empty', ['message' => 'Nenhum exemplo revisado no periodo.'])
            @else
                <p class="muted">Sem nome, telefone ou identificador. Um exemplo da concretude ao numero; nao fica mais concreto com o nome de quem o gerou.</p>
                <ul>@foreach($examples as $example)
                    <li>
                        <strong>{{ $example['problem'] }}</strong>
                        @if($example['action']) — sugestao: {{ $example['action'] }} @endif
                        <span class="muted">({{ $example['urgency'] ?? 'sem urgencia' }}{{ $example['region'] ? ', '.$example['region'] : '' }})</span>
                    </li>
                @endforeach</ul>
            @endif
        @endunless
    </section>

    @can('analytics.export_detailed')
        <section class="card">
            <h2>Exportacao detalhada</h2>
            <p class="muted">Leva o texto das respostas. Nome removido, telefone mascarado e contato substituido por pseudonimo proprio desta exportacao — duas exportacoes nao podem ser cruzadas para reidentificar alguem.</p>
            <form method="post" action="{{ route('admin.analytics.export') }}">
                @csrf
                <input type="hidden" name="type" value="demands">
                <input type="hidden" name="scope" value="detailed">
                <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                <input type="hidden" name="flow" value="{{ $flowId }}">
                <div>
                    <label for="purpose">Finalidade</label>
                    <textarea id="purpose" name="purpose" rows="2" minlength="10" maxlength="500" required
                        placeholder="Para que esta exportacao sera usada"></textarea>
                    <p class="muted">Fica registrada com seu nome e a data. E registro de responsabilidade, nao verificacao automatica.</p>
                </div>
                <label for="format_detailed">Formato</label>
                <select id="format_detailed" name="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
                <button class="btn" type="submit">Exportar detalhado</button>
            </form>
        </section>
    @endcan
</x-layouts.app>
