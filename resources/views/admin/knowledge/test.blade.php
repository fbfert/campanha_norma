<x-layouts.app title="Teste de busca na base" breadcrumbs="Inicio / Base de conhecimento / Teste de busca">
    <section class="card">
        <p class="muted">
            Esta tela não envia nada para ninguém e não chama o provedor de IA. Ela mostra o que a base devolveria para uma consulta
            e confere se um texto se sustenta nos trechos devolvidos.
        </p>
        @unless($knowledgeEnabled)
            <p class="muted">A recuperação esta desligada em configurações. O teste continua funcionando; a geração de respostas não usa a base.</p>
        @endunless

        <form method="get">
            <div>
                <label for="query">Consulta</label>
                <input id="query" name="query" type="text" maxlength="1000" required value="{{ request('query') }}">
            </div>

            <fieldset>
                <legend>Bases consultadas</legend>
                @foreach($bases as $base)
                    <label>
                        <input type="checkbox" name="base_ids[]" value="{{ $base->id }}"
                            @checked(in_array((string) $base->id, (array) request('base_ids', []), true))>
                        {{ $base->name }} ({{ $base->status->label() }})
                    </label>
                @endforeach
            </fieldset>

            <div>
                <label for="strategy">Estratégia</label>
                <select id="strategy" name="strategy">
                    <option value="">Usar a configurada</option>
                    @foreach($strategies as $item)
                        <option value="{{ $item->value }}" @selected(request('strategy') === $item->value)>{{ $item->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="answer">Texto a conferir (opcional)</label>
                <textarea id="answer" name="answer" rows="3" maxlength="2000">{{ request('answer') }}</textarea>
                <p class="muted">Escreva uma resposta candidata para ver se ela se sustenta nos trechos recuperados.</p>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Buscar</button>
                <a class="btn ghost" href="{{ route('admin.knowledge.test') }}">Limpar</a>
            </div>
        </form>
    </section>

    @if($result)
        <section class="card">
            <h2>Resultado</h2>
            <dl class="grid grid-3">
                <div><dt>Estratégia usada</dt><dd>{{ $result->strategy->label() }}</dd></div>
                <div><dt>Candidatos avaliados</dt><dd>{{ $result->candidateCount }}</dd></div>
                <div><dt>Trechos devolvidos</dt><dd>{{ $result->count() }}</dd></div>
                <div><dt>Maior pontuação</dt><dd>{{ $result->maxScore() !== null ? number_format($result->maxScore(), 4, ',', '.') : '-' }}</dd></div>
                <div><dt>Duração</dt><dd>{{ $result->durationMs }} ms</dd></div>
                <div><dt>Degradação</dt><dd>{{ $result->degradedReason ?: 'Nenhuma' }}</dd></div>
            </dl>

            @forelse($result->chunks as $chunk)
                <article class="card nested">
                    <p class="muted">
                        {{ $chunk->documentTitle }} &middot; document_id={{ $chunk->documentId }} &middot; chunk_id={{ $chunk->reference() }}
                        @if($chunk->page) &middot; página {{ $chunk->page }} @endif
                        @if($chunk->section) &middot; seção {{ $chunk->section }} @endif
                        &middot; pontuação {{ number_format($chunk->score, 4, ',', '.') }}
                    </p>
                    <p>{{ $chunk->content }}</p>
                </article>
            @empty
                <p class="muted">Nenhum trecho aprovado responde a esta consulta.</p>
            @endforelse
        </section>
    @endif

    @if($verdict)
        <section class="card">
            <h2>Conferência de fundamentação</h2>
            <p>
                Veredito: <strong>{{ $verdict->status->label() }}</strong>.
                {{ $verdict->allowsSending() ? 'Um texto assim poderia ser enviado.' : 'Um texto assim seria bloqueado e encaminhado para atendimento humano.' }}
            </p>
            @if($verdict->errorSummary())
                <p class="muted">Motivo: {{ $verdict->errorSummary() }}</p>
            @endif
            <p class="muted">Trechos que sustentariam o texto: {{ count($verdict->citations) }}.</p>
        </section>
    @endif
</x-layouts.app>
