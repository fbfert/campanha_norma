<x-layouts.app title="Insight" breadcrumbs="Inicio / Pesquisa conversacional / Interpretacao / Detalhes">
    <section class="card">
        <span class="badge" style="background:#4f46e5;color:#fff;">Gerado por IA</span>
        <span class="muted" style="margin-left:8px;">
            Modelo {{ $insight->run?->model ?? '-' }} &middot; prompt {{ $insight->prompt_version }} &middot; extracao v{{ $insight->extraction_version }}
        </span>
        @if($insight->requires_human_review && ! $insight->reviewed)
            <p style="margin-top:8px;"><strong>Aguardando revisao humana:</strong> {{ \App\Enums\InsightReviewReason::tryFrom((string) $insight->review_reason)?->label() ?? $insight->review_reason }}</p>
        @endif
    </section>

    <div class="grid grid-2" style="margin-top:16px;">
        <section class="card">
            <h2>Mensagem original</h2>
            <p class="muted">Fonte primaria e imutavel.</p>
            <p><strong>Pergunta:</strong> {{ $insight->question_snapshot ?? '-' }}</p>
            <p style="white-space:pre-wrap;">{{ $insight->sourceMessage?->body ?? '-' }}</p>
            <p class="muted">
                Contato:
                @if($canSeeContactData)
                    {{ $insight->conversation?->contact?->name ?? 'nao identificado' }}
                @else
                    {{ $insight->conversation?->contact?->phone_normalized ? Str::mask($insight->conversation->contact->phone_normalized, '*', 4, -4) : 'nao identificado' }}
                @endif
            </p>
            @if($insight->conversation)
                <a class="btn ghost" href="{{ route('admin.conversations.show', $insight->conversation) }}">Abrir conversa</a>
            @endif
        </section>

        <section class="card">
            <h2>Interpretacao</h2>
            <p><strong>Resumo:</strong> {{ $insight->summary ?? '-' }}</p>
            <p><strong>Tema principal:</strong> {{ $insight->topic?->name ?? '-' }} <span class="muted">({{ $insight->main_topic_raw ?? 'sem saida do modelo' }})</span></p>
            <p><strong>Temas secundarios:</strong>
                @forelse($insight->topicLinks->where('role', 'secondary') as $link)
                    <span class="badge" style="background:{{ $link->topic?->color ?? '#64748b' }};color:#fff;">{{ $link->topic?->name }}</span>
                @empty
                    -
                @endforelse
            </p>
            <p><strong>Problema identificado:</strong> {{ $insight->identified_problem ?? '-' }}</p>
            <p><strong>Acao sugerida:</strong> {{ $insight->suggested_action ?? '-' }}</p>
            <p><strong>Resultado desejado:</strong> {{ $insight->desired_result ?? '-' }}</p>
            <p><strong>Grupo afetado:</strong> {{ $insight->affected_group ?? '-' }}</p>
            <p><strong>Localidade declarada:</strong> {{ $insight->locality_text ?? '-' }}</p>
            <p><strong>Regiao:</strong> {{ $insight->region ?? '-' }}</p>
            <p><strong>Urgencia:</strong> {{ $insight->urgency?->label() ?? '-' }}</p>
            <p><strong>Sentimento:</strong> {{ $insight->sentiment?->label() ?? '-' }}</p>
            <p><strong>Palavras-chave:</strong> {{ implode(', ', $insight->keywords ?? []) ?: '-' }}</p>
            <p><strong>Confianca:</strong> {{ $insight->confidence !== null ? number_format($insight->confidence, 2) : '-' }}</p>
            @if($classification)
                <p><strong>Classificacao:</strong> {{ $classification->classification->label() }}
                    <span class="muted">({{ $classification->source->label() }})</span>
                </p>
            @endif
        </section>
    </div>

    @can('ai_insights.correct')
        <section class="card" style="margin-top:16px;">
            <h2>Correcao manual</h2>
            <p class="muted">O valor original e preservado no historico. Correcoes nao retroalimentam o modelo.</p>
            <form method="post" action="{{ route('admin.ai-insights.correct', $insight) }}">
                @csrf
                @method('put')
                <div class="grid grid-2">
                    <div>
                        <label for="summary">Resumo</label>
                        <textarea id="summary" name="summary" rows="3">{{ old('summary', $insight->summary) }}</textarea>
                    </div>
                    <div>
                        <label for="identified_problem">Problema identificado</label>
                        <textarea id="identified_problem" name="identified_problem" rows="3">{{ old('identified_problem', $insight->identified_problem) }}</textarea>
                    </div>
                    <div>
                        <label for="suggested_action">Acao sugerida</label>
                        <textarea id="suggested_action" name="suggested_action" rows="2">{{ old('suggested_action', $insight->suggested_action) }}</textarea>
                    </div>
                    <div>
                        <label for="desired_result">Resultado desejado</label>
                        <textarea id="desired_result" name="desired_result" rows="2">{{ old('desired_result', $insight->desired_result) }}</textarea>
                    </div>
                    <div>
                        <label for="affected_group">Grupo afetado</label>
                        <input id="affected_group" name="affected_group" value="{{ old('affected_group', $insight->affected_group) }}">
                    </div>
                    <div>
                        <label for="locality_text">Localidade declarada</label>
                        <input id="locality_text" name="locality_text" value="{{ old('locality_text', $insight->locality_text) }}">
                    </div>
                    <div>
                        <label for="region">Regiao</label>
                        <input id="region" name="region" value="{{ old('region', $insight->region) }}">
                    </div>
                    <div>
                        <label for="insight_topic_id">Tema principal</label>
                        <select id="insight_topic_id" name="insight_topic_id">
                            <option value="">Sem tema</option>
                            @foreach($topics as $topic)
                                <option value="{{ $topic->id }}" @selected((string) old('insight_topic_id', $insight->insight_topic_id) === (string) $topic->id)>{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="urgency">Urgencia</label>
                        <select id="urgency" name="urgency">
                            <option value="">Nao definida</option>
                            @foreach($urgencies as $urgency)
                                <option value="{{ $urgency->value }}" @selected(old('urgency', $insight->urgency?->value) === $urgency->value)>{{ $urgency->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="sentiment">Sentimento</label>
                        <select id="sentiment" name="sentiment">
                            <option value="">Nao definido</option>
                            @foreach($sentiments as $sentiment)
                                <option value="{{ $sentiment->value }}" @selected(old('sentiment', $insight->sentiment?->value) === $sentiment->value)>{{ $sentiment->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($classification)
                        <div>
                            <label for="classification">Classificacao</label>
                            <select id="classification" name="classification">
                                <option value="">Manter</option>
                                @foreach($classifications as $option)
                                    <option value="{{ $option->value }}" @selected(old('classification') === $option->value)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="reason">Motivo da correcao</label>
                        <input id="reason" name="reason" value="{{ old('reason') }}">
                    </div>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit">Salvar correcao</button>
                </div>
            </form>
            <div class="actions" style="margin-top:12px;">
                <form method="post" action="{{ route('admin.ai-insights.approve', $insight) }}">@csrf <button class="btn secondary">Marcar como revisado</button></form>
                @can('ai_insights.reprocess')
                    <form method="post" action="{{ route('admin.ai-insights.reprocess', $insight) }}" onsubmit="return confirm('Reprocessar a interpretacao desta mensagem?')">@csrf <button class="btn ghost">Reprocessar</button></form>
                @endcan
            </div>
        </section>
    @endcan

    <div class="grid grid-2" style="margin-top:16px;">
        <section class="card">
            <h2>Versoes</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Extracao</th><th>Prompt</th><th>Confianca</th><th>Data</th><th></th></tr></thead>
                    <tbody>
                        @foreach($versions as $version)
                            <tr>
                                <td>v{{ $version->extraction_version }}</td>
                                <td>{{ $version->prompt_version }}</td>
                                <td>{{ $version->confidence !== null ? number_format($version->confidence, 2) : '-' }}</td>
                                <td>{{ $version->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                                <td class="actions">
                                    @if($version->id !== $insight->id)
                                        <a class="btn ghost" href="{{ route('admin.ai-insights.show', $version) }}">Abrir</a>
                                    @else
                                        <span class="muted">Atual</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Historico de correcoes</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Campo</th><th>Original</th><th>Corrigido</th><th>Usuario</th><th>Data</th></tr></thead>
                    <tbody>
                        @forelse($insight->corrections as $correction)
                            <tr>
                                <td>{{ $correction->field }}</td>
                                <td>{{ Str::limit($correction->original_value ?? '-', 60) }}</td>
                                <td>{{ Str::limit($correction->corrected_value ?? '-', 60) }}</td>
                                <td>{{ $correction->user?->name ?? '-' }}</td>
                                <td>{{ $correction->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Nenhuma correcao registrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
