<x-layouts.app title="Dossiê" breadcrumbs="Inicio / Pauta de resposta / Dossie">
    @include('admin.pauta._aviso')

    {{-- A ordem dos blocos é a ordem de leitura no celular, em trinta segundos:
         quem é, o que ela disse, o que ela quer, o que a campanha já defende e,
         por último e em destaque, o que não pode ser prometido. --}}
    <section class="card">
        <h2>{{ $dossie['name'] ?? 'Sem nome cadastrado' }}</h2>
        <p class="muted">
            {{ $dossie['city'] ?? 'cidade não cadastrada' }}{{ $dossie['state'] ? ' — '.$dossie['state'] : '' }}
            @if($dossie['declared_locality'])
                — declarou morar em <strong>{{ $dossie['declared_locality'] }}</strong>
            @endif
        </p>
        <p class="muted">
            Tema: {{ $dossie['topic'] ?? 'sem tema atribuído' }} —
            urgência: {{ $dossie['urgency']?->label() ?? 'não informada' }} —
            sentimento: {{ $dossie['sentiment']?->label() ?? 'não informado' }}
        </p>

        @if($dossie['low_confidence'])
            <p class="alert alert-error">
                <strong>Confiança baixa ({{ number_format((float) $dossie['confidence'], 2, ',', '.') }}).</strong>
                A leitura automática pode ter errado. Confira a mensagem original antes de responder.
            </p>
        @endif
    </section>

    <section class="card">
        <h2>O que ela escreveu</h2>
        <blockquote class="manual-quote">{{ $dossie['sentence'] ?: 'A mensagem de origem não tem texto.' }}</blockquote>
        <p class="muted">Literal, sem resumo. É o que a pessoa escreveu, e não a leitura que o sistema fez dela.</p>
    </section>

    <section class="card">
        <h2>O que ela levantou</h2>
        <table>
            <tbody>
                <tr><th>Problema identificado</th><td>{{ $dossie['identified_problem'] ?: '—' }}</td></tr>
                <tr><th>Ação sugerida</th><td>{{ $dossie['suggested_action'] ?: '—' }}</td></tr>
                <tr><th>Resultado desejado</th><td>{{ $dossie['desired_result'] ?: '—' }}</td></tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>O que a campanha já defende</h2>
        @if($dossie['response_guidance'])
            <p>{{ $dossie['response_guidance'] }}</p>
        @else
            <p class="muted">Nenhuma orientação escrita para este tema. Preencher isso no cadastro do tema é trabalho humano, e é o que dá apoio a quem responde.</p>
        @endif

        @if($dossie['official_excerpt'])
            <h3>Trecho do documento aprovado</h3>
            <blockquote class="manual-quote">{{ $dossie['official_excerpt'] }}</blockquote>
        @endif
    </section>

    {{-- A linha vermelha em destaque forte, e dita mesmo quando falta.
         Seção ausente em silêncio seria lida como "não há nada a evitar aqui",
         que é o contrário do que a ausência significa. --}}
    <section class="card">
        <h2>Linha vermelha — o que não prometer</h2>
        @if($dossie['red_lines'])
            <p class="alert alert-error"><strong>{{ $dossie['red_lines'] }}</strong></p>
        @else
            <p class="alert alert-error">
                <strong>Nenhuma linha vermelha escrita para este tema.</strong>
                Isso não quer dizer que não haja: quer dizer que ninguém escreveu ainda. Promessa dita na
                própria voz da candidata não tem retratação possível.
            </p>
        @endif
    </section>

    <section class="card">
        <h2>Depois de responder</h2>

        @if($dossie['answered'])
            <p class="muted">
                Já marcada como respondida
                @if($dossie['answered_at']) em {{ $dossie['answered_at']->format('d/m/Y H:i') }} @endif
                @if($dossie['answered_source'] === 'manual')
                    — marcada à mão{{ $dossie['answered_by'] ? ' por '.$dossie['answered_by'] : '' }}.
                @else
                    — detectada pela sincronização.
                @endif
            </p>
        @endif

        <div class="actions">
            <form method="post" action="{{ route('admin.pauta.responder', $insight) }}">
                @csrf
                <button class="btn" type="submit"><x-icon name="check" size="16" /> Marcar como respondida</button>
            </form>
            <a class="btn ghost" href="{{ route('admin.conversations.show', $insight->conversation_id) }}">
                <x-icon name="chat" size="16" /> Abrir a conversa
            </a>
        </div>

        <p class="muted">
            Marcar não envia nada: grava só a marca e o registro de auditoria. Quem responde pelo mesmo número
            pareado ao sistema nem precisa deste botão — o áudio aparece na sincronização e a fila se marca sozinha.
        </p>
    </section>
</x-layouts.app>
