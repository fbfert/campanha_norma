<x-layouts.app title="Demandas" breadcrumbs="Inicio / Relatorios / Demandas">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <h2>Fila de revisão</h2>
        <p class="muted">Itens abaixo de {{ $reviewQueue['threshold_used'] }}% de confiança não entram nos totais como resultado assentado enquanto ninguém conferiu.</p>
        <table><tbody>
            <tr><th>Baixa confiança sem revisão</th><td>{{ $reviewQueue['low_confidence'] }}</td></tr>
            <tr><th>Marcados para revisão</th><td>{{ $reviewQueue['flagged'] }}</td></tr>
        </tbody></table>
    </section>

    @unless($canSeeContent)
        <section class="card">
            <h2>Problemas, ações e resultados</h2>
            <p class="muted">
                Estas três listas agrupam texto escrito pelas próprias pessoas. Agrupar uma frase e contar quantas vezes
                ela aparece não a transforma em número: o rótulo continua sendo a frase de alguém. Por isso exigem a
                permissão de ver conteúdo. Urgência e fila de revisão continuam visíveis abaixo.
            </p>
        </section>
    @endunless

    @foreach([['Problemas identificados', $problems], ['Ações sugeridas', $actions], ['Resultados desejados', $results]] as [$title, $rows])
        @if($canSeeContent)
        <section class="card">
            <h2>{{ $title }}</h2>
            @if($rows === [])
                @include('admin.analytics.partials.empty')
            @else
                <table>
                    <thead><tr><th>Descrição</th><th>Quantidade</th></tr></thead>
                    <tbody>@foreach($rows as $row)
                        <tr><td>{{ $row['label'] }}</td><td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td></tr>
                    @endforeach</tbody>
                </table>
            @endif
        </section>
        @endif
    @endforeach

    <section class="card">
        <h2>Urgência</h2>
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
            <p class="muted">Exemplos são texto escrito por pessoas e exigem a permissão de ver conteúdo. Os agregados acima continuam disponíveis.</p>
        @else
            @if($examples === [])
                @include('admin.analytics.partials.empty', ['message' => 'Nenhum exemplo revisado no período.'])
            @else
                <p class="muted">Sem nome, telefone ou identificador. Um exemplo da concretude ao número; não fica mais concreto com o nome de quem o gerou.</p>
                <ul>@foreach($examples as $example)
                    <li>
                        <strong>{{ $example['problem'] }}</strong>
                        @if($example['action']) — sugestão: {{ $example['action'] }} @endif
                        <span class="muted">({{ $example['urgency'] ?? 'sem urgência' }}{{ $example['region'] ? ', '.$example['region'] : '' }})</span>
                    </li>
                @endforeach</ul>
            @endif
        @endunless
    </section>

    @can('analytics.export_detailed')
        <section class="card">
            <h2>Exportação detalhada</h2>
            <p class="muted">Leva o texto das respostas. Nome removido, telefone mascarado e contato substituído por pseudônimo próprio desta exportação — duas exportações não podem ser cruzadas para reidentificar alguém.</p>
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
                        placeholder="Para que esta exportação será usada"></textarea>
                    <p class="muted">Fica registrada com seu nome e a data. E registro de responsabilidade, não verificação automática.</p>
                </div>
                <label for="format_detailed">Formato</label>
                <select id="format_detailed" name="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
                <button class="btn" type="submit">Exportar detalhado</button>
            </form>
        </section>
    @endcan
</x-layouts.app>
