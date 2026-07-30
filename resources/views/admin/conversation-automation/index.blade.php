<x-layouts.app title="Pesquisa conversacional" breadcrumbs="Inicio / Pesquisa conversacional / Automacao">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="stage">Estagio</label><select id="stage" name="stage"><option value="">Todos</option>@foreach($stages as $stage)<option value="{{ $stage->value }}" @selected(request('stage') === $stage->value)>{{ $stage->label() }}</option>@endforeach</select></div>
            <div><label for="flow_id">Fluxo</label><select id="flow_id" name="flow_id"><option value="">Todos</option>@foreach($flows as $flow)<option value="{{ $flow->id }}" @selected((string) request('flow_id') === (string) $flow->id)>{{ $flow->name }}</option>@endforeach</select></div>
            <div class="actions"><button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button><a class="btn ghost" href="{{ route('admin.conversation-automation.index') }}">Limpar</a></div>
        </form>
        <div class="actions" style="margin-top:12px;">
            <a class="btn ghost" href="{{ route('admin.conversation-automation.index', ['needs_human' => 1]) }}">Aguardando humano</a>
            <a class="btn ghost" href="{{ route('admin.conversation-automation.index', ['paused' => 1]) }}">Pausadas</a>
        </div>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Contato</th><th>Fluxo</th><th>Estagio</th><th>Mensagens automáticas</th><th>Pausada</th><th>Humano</th><th>Última transição</th><th>Ações</th></tr></thead>
                <tbody>
                    @forelse($states as $state)
                        <tr>
                            <td>{{ $state->conversation?->contact?->name ?? 'Contato não identificado' }}</td>
                            <td>{{ $state->flow?->name ?? '-' }}</td>
                            <td>{{ $state->current_stage->label() }}</td>
                            <td>{{ $state->automated_messages_count }}</td>
                            <td>{{ $state->is_paused ? 'Sim' : 'Não' }}</td>
                            <td>{{ $state->needs_human_review ? 'Sim' : 'Não' }}</td>
                            <td>{{ $state->last_transition_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.conversation-automation.show', $state) }}">Ver</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhuma conversa em automação.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $states->links() }}
    </section>
</x-layouts.app>
