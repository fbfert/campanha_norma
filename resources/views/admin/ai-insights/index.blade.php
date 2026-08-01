<x-layouts.app title="Interpretação por IA" breadcrumbs="Inicio / Pesquisa conversacional / Interpretacao">
    <section class="card">
        <p class="muted">Resultados gerados por inteligência artificial a partir das respostas da pesquisa. A mensagem original permanece inalterada e continua sendo a fonte de verdade.</p>
        <form method="get" class="grid grid-3">
            <div>
                <label for="topic_id">Tema</label>
                <select id="topic_id" name="topic_id">
                    <option value="">Todos</option>
                    @foreach($topics as $topic)
                        <option value="{{ $topic->id }}" @selected((string) request('topic_id') === (string) $topic->id)>{{ $topic->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="urgency">Urgência</label>
                <select id="urgency" name="urgency">
                    <option value="">Todas</option>
                    @foreach($urgencies as $urgency)
                        <option value="{{ $urgency->value }}" @selected(request('urgency') === $urgency->value)>{{ $urgency->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="sentiment">Sentimento</label>
                <select id="sentiment" name="sentiment">
                    <option value="">Todos</option>
                    @foreach($sentiments as $sentiment)
                        <option value="{{ $sentiment->value }}" @selected(request('sentiment') === $sentiment->value)>{{ $sentiment->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="reason">Motivo de revisão</label>
                <select id="reason" name="reason">
                    <option value="">Todos</option>
                    @foreach($reasons as $reason)
                        <option value="{{ $reason->value }}" @selected(request('reason') === $reason->value)>{{ $reason->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="actions">
                <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
                <a class="btn ghost" href="{{ route('admin.ai-insights.index') }}">Limpar</a>
            </div>
        </form>
        <div class="actions" style="margin-top:12px;">
            <a class="btn ghost" href="{{ route('admin.ai-insights.index', ['needs_review' => 1]) }}">Fila de revisão</a>
            <a class="btn ghost" href="{{ route('admin.insight-topics.index') }}">Temas</a>
            @can('ai_insights.view_monitoring')
                <a class="btn ghost" href="{{ route('admin.ai-monitoring.index') }}">Monitoramento</a>
            @endcan
        </div>
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Contato</th>
                        <th>Resumo</th>
                        <th>Tema</th>
                        <th>Urgência</th>
                        <th>Confiança</th>
                        <th>Revisão</th>
                        <th>Data</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($insights as $insight)
                        <tr>
                            <td>
                                @if($canSeeContactData)
                                    {{ $insight->conversation?->contact?->name ?? 'Contato não identificado' }}
                                @else
                                    {{-- Telas analíticas mascaram identificação sem a permissão específica. --}}
                                    {{ $insight->conversation?->contact?->phone_normalized ? Str::mask($insight->conversation->contact->phone_normalized, '*', 4, -4) : 'Contato não identificado' }}
                                @endif
                            </td>
                            <td>{{ Str::limit($insight->summary ?? '-', 90) }}</td>
                            <td>{{ $insight->topic?->name ?? '-' }}</td>
                            <td>{{ $insight->urgency?->label() ?? '-' }}</td>
                            <td>{{ $insight->confidence !== null ? number_format($insight->confidence, 2) : '-' }}</td>
                            <td>
                                @if($insight->requires_human_review && ! $insight->reviewed)
                                    <span class="badge" style="background:var(--warning);color:var(--text-inverse);">Pendente</span>
                                @elseif($insight->reviewed)
                                    <span class="badge" style="background:var(--success);color:var(--text-inverse);">Revisado</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $insight->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.ai-insights.show', $insight) }}">Ver</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhum insight registrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $insights->links() }}
    </section>
</x-layouts.app>
