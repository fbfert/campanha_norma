<x-layouts.app title="Como iniciar uma pesquisa" breadcrumbs="Inicio / Manual / Iniciar uma pesquisa">
    {{--
        Mesmo desenho do mapa geral: lista aninhada com CSS, sem imagem e sem
        biblioteca. Aqui a hierarquia carrega ordem — passo 1, 2, 3 — e não
        apenas agrupamento, por isso os galhos são numerados.
    --}}
    <section class="card">
        <h2>Os cinco passos</h2>
        <p class="muted">
            Do fluxo pronto até a primeira resposta chegando. Cada galho e um passo, na ordem em que
            precisa ser feito; as folhas são o que conferir dentro dele. O passo 3 e o que costuma ser
            esquecido, e o único que falha sem avisar.
        </p>
        <div class="actions">
            <a class="btn secondary" href="{{ route('manual.index') }}#pesquisa"><x-icon name="book" size="16" />Ler a seção do manual</a>
            <a class="btn ghost" href="{{ route('manual.mind-map') }}"><x-icon name="mind-map" size="16" />Ver o mapa do sistema inteiro</a>
        </div>
    </section>

    <section class="card mindmap-card">
        <div class="mindmap">
            <p class="mindmap-root">
                <x-icon name="poll" size="22" />
                <span>Fazer uma pesquisa chegar em quem responde</span>
            </p>

            <ul class="mindmap-branches">
                @foreach($steps as $index => $step)
                    <li class="mindmap-branch" style="--branch: var(--branch-{{ $index % 6 }});">
                        <a class="mindmap-node" href="{{ route('manual.index') }}#pesquisa">
                            <x-icon name="{{ $step['icon'] }}" size="18" />
                            <span>
                                <strong>{{ $step['passo'] }}. {{ $step['title'] }}</strong>
                                <span class="muted">{{ $step['summary'] }}</span>
                                <span class="muted">{{ $step['where'] }}</span>
                            </span>
                        </a>
                        <ul>
                            @foreach($step['topics'] as $topic)
                                <li><span class="mindmap-leaf">{{ $topic }}</span></li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="card">
        <h2>Os dois erros que fazem a pesquisa não acontecer</h2>
        @foreach($steps as $step)
            @if($step['warning'])
                <p class="alert warning">
                    <strong>Passo {{ $step['passo'] }} &mdash; {{ $step['title'] }}:</strong>
                    {{ $step['warning'] }}
                </p>
            @endif
        @endforeach
    </section>

    <section class="card">
        <h2>O que esta valendo agora</h2>
        <p class="muted">Valores lidos da configuração neste momento, e não escritos neste texto.</p>
        <div class="manual-facts">
            <div>
                <span class="muted">Automação</span>
                <strong>{{ $operational['automation_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
            </div>
            <div>
                <span class="muted">Envio automático</span>
                <strong>{{ $operational['auto_send_enabled'] === '1' ? 'Liberado' : 'Bloqueado' }}</strong>
            </div>
            <div>
                <span class="muted">Respostas automáticas</span>
                <strong>{{ $operational['window_start'] }} &ndash; {{ $operational['window_end'] }}</strong>
            </div>
            <div>
                <span class="muted">Ritmo de envio</span>
                <strong>{{ $operational['max_per_minute'] }}/min &middot; {{ $operational['max_per_hour'] }}/h &middot; {{ $operational['max_per_day'] }}/dia</strong>
            </div>
        </div>
        <p class="muted">
            O teto diário e o que decide o tamanho útil de um lote: com {{ $operational['max_per_day'] }} por dia,
            um lote maior que isso continua no dia seguinte, sem erro e sem aviso.
        </p>
        <p class="muted">
            Resposta que chega fora da janela de {{ $operational['window_start'] }} às {{ $operational['window_end'] }}
            não recebe a pergunta seguinte, e não ha nova tentativa quando a janela abre: a conversa so anda
            se a pessoa escrever de novo dentro do horário.
        </p>
    </section>

    <section class="card">
        <h2>Antes de disparar para valer</h2>
        <ol class="mindmap-path">
            <li>Monte um lote com dois ou três números seus e siga os cinco passos.</li>
            <li>Responda "sim" e confira se a primeira pergunta chega em segundos.</li>
            <li>Responda cada pergunta e veja a seguinte chegar, até o agradecimento final.</li>
            <li>Abra <strong>Pesquisa conversacional</strong> e confira se a conversa consta como concluída.</li>
            <li>So depois disso repita com a base de verdade.</li>
        </ol>
    </section>
</x-layouts.app>
