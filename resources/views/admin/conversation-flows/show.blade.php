<x-layouts.app title="Fluxo" breadcrumbs="Inicio / Pesquisa conversacional / Fluxos / Detalhes">
    <section class="card">
        <div class="actions" style="justify-content:space-between;">
            <h2>{{ $flow->name }}</h2>
            <div class="actions">@can('conversation_automation.manage_flows')<a class="btn" href="{{ route('admin.conversation-flows.edit', $flow) }}">Editar</a>@endcan</div>
        </div>
        <p><strong>Status:</strong> {{ $flow->status->label() }}</p>
        <p>{{ $flow->description }}</p>
        <p><strong>Modelo de apresentação:</strong> {{ $flow->presentationTemplate?->name ?? 'Texto livre' }}</p>
        <p><strong>Texto de apresentação:</strong></p>
        <pre style="white-space:pre-wrap;">{{ $flow->presentation_text }}</pre>
        <p><strong>Texto de agradecimento:</strong></p>
        <pre style="white-space:pre-wrap;">{{ $flow->thank_you_text }}</pre>
        <p><strong>Texto de recusa:</strong></p>
        <pre style="white-space:pre-wrap;">{{ $flow->permission_denied_text }}</pre>
        <p><strong>Perguntas principais:</strong> {{ $flow->max_main_questions }} | <strong>Aprofundamentos:</strong> {{ $flow->max_followups }} | <strong>Validade:</strong> {{ $flow->validity_hours }} horas</p>
        <p><strong>Avisar que a mensagem e automática:</strong> {{ $flow->transparency_enabled ? 'Sim' : 'Não' }}</p>
        <pre style="white-space:pre-wrap;">{{ $flow->transparency_text }}</pre>
        <p class="muted">Criado por {{ $flow->creator?->name ?? '-' }} em {{ $flow->created_at?->format($dateTimeFormat) }} | Atualizado em {{ $flow->updated_at?->format($dateTimeFormat) }}</p>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="actions" style="justify-content:space-between;">
            <h2>Perguntas</h2>
            <div class="actions">@can('conversation_automation.manage_questions')<a class="btn" href="{{ route('admin.conversation-flows.questions.create', $flow) }}">Nova pergunta</a>@endcan</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Título interno</th><th>Texto</th><th>Categoria</th><th>Peso</th><th>Ordem</th><th>Ativa</th><th>Versão</th><th>Ações</th></tr></thead>
                <tbody>
                    @forelse($flow->questions as $question)
                        <tr>
                            <td>{{ $question->internal_title }}<br><span class="muted">{{ $question->creator?->name ?? '-' }}</span></td>
                            <td>{{ $question->text }}</td>
                            <td>{{ $question->category ?: '-' }}</td>
                            <td>{{ $question->weight }}</td>
                            <td>{{ $question->display_order }}</td>
                            <td>{{ $question->is_active ? 'Sim' : 'Não' }}</td>
                            <td>{{ $question->version }}</td>
                            <td class="actions">@can('conversation_automation.manage_questions')<a class="btn ghost" href="{{ route('admin.conversation-flows.questions.edit', [$flow, $question]) }}">Editar</a><form method="post" action="{{ route('admin.conversation-flows.questions.destroy', [$flow, $question]) }}" onsubmit="return confirm('Excluir esta pergunta?')">@csrf @method('delete')<button class="btn danger" type="submit">Excluir</button></form>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhuma pergunta cadastrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
