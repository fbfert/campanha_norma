<x-layouts.app title="Automacao da conversa" breadcrumbs="Inicio / Pesquisa conversacional / Automacao / Detalhes">
    <div class="grid grid-2">
        <section class="card">
            <h2>Estado</h2>
            <p><strong>Contato:</strong> {{ $state->conversation?->contact?->name ?? 'Contato nao identificado' }}</p>
            <p><strong>Fluxo:</strong> {{ $state->flow?->name ?? '-' }}</p>
            <p><strong>Estagio:</strong> {{ $state->current_stage->label() }}</p>
            <p><strong>Mensagens automaticas:</strong> {{ $state->automated_messages_count }}</p>
            <p><strong>Tentativas:</strong> {{ $state->attempts_count }}</p>
            <p><strong>Pausada:</strong> {{ $state->is_paused ? 'Sim' : 'Nao' }}</p>
            <p><strong>Aguardando humano:</strong> {{ $state->needs_human_review ? 'Sim' : 'Nao' }}</p>
            <p><strong>Motivo de encerramento:</strong> {{ $state->end_reason ?? '-' }}</p>
            <p><strong>Inicio:</strong> {{ $state->started_at?->format($dateTimeFormat) ?? '-' }}</p>
            <p><strong>Ultima transicao:</strong> {{ $state->last_transition_at?->format($dateTimeFormat) ?? '-' }}</p>
            <p><strong>Conclusao:</strong> {{ $state->completed_at?->format($dateTimeFormat) ?? '-' }}</p>
            <p><strong>Expira em:</strong> {{ $state->expires_at?->format($dateTimeFormat) ?? '-' }}</p>
            @if($state->conversation)
                <a class="btn ghost" href="{{ route('admin.conversations.show', $state->conversation) }}">Abrir conversa</a>
            @endif
        </section>

        <section class="card">
            <h2>Pergunta selecionada</h2>
            @if($state->selected_question_snapshot)
                <p>{{ $state->selected_question_snapshot }}</p>
                <p class="muted">Texto congelado no momento do envio.</p>
            @else
                <p class="muted">Nenhuma pergunta selecionada ate agora.</p>
            @endif

            @can('conversation_automation.control')
                <h2 style="margin-top:16px;">Acoes</h2>
                <div class="stack-list">
                    @if($state->is_paused)
                        <form method="post" action="{{ route('admin.conversation-automation.resume', $state) }}">@csrf <button class="btn secondary">Retomar automacao</button></form>
                    @else
                        <form method="post" action="{{ route('admin.conversation-automation.pause', $state) }}">@csrf <button class="btn secondary">Pausar automacao</button></form>
                    @endif
                    <form method="post" action="{{ route('admin.conversation-automation.take-over', $state) }}">@csrf <button class="btn ghost">Assumir manualmente</button></form>
                    <form method="post" action="{{ route('admin.conversation-automation.finish', $state) }}" onsubmit="return confirm('Encerrar a automacao desta conversa?')">@csrf <button class="btn danger">Encerrar automacao</button></form>
                </div>
            @endcan
        </section>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2>Perguntas usadas</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pergunta</th><th>Selecionada em</th><th>Enviada em</th><th>Resultado</th></tr></thead>
                <tbody>
                    @forelse($state->questionUsages as $usage)
                        <tr>
                            <td>{{ $usage->question_snapshot }}</td>
                            <td>{{ $usage->selected_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td>{{ $usage->sent_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td>{{ $usage->result ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhuma pergunta usada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Historico de transicoes</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>De</th><th>Para</th><th>Evento</th><th>Decisao</th><th>Responsavel</th><th>Data</th></tr></thead>
                <tbody>
                    @forelse($state->transitions as $transition)
                        <tr>
                            <td>{{ $transition->from_stage ?? '-' }}</td>
                            <td>{{ $transition->to_stage }}</td>
                            <td>{{ $transition->trigger_event }}</td>
                            <td>{{ $transition->decision ?? '-' }}</td>
                            <td>{{ $transition->user?->name ?? 'Sistema' }}</td>
                            <td>{{ $transition->created_at?->format($dateTimeFormat) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Nenhuma transicao registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
