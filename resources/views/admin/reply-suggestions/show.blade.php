<x-layouts.app title="Revisar sugestão" breadcrumbs="Inicio / Pesquisa conversacional / Sugestoes / Detalhes">
    <section class="card">
        <span class="badge" style="background:var(--ai-mark);color:var(--text-inverse);">Gerado por IA</span>
        <span class="muted" style="margin-left:8px;">
            {{ $suggestion->action->label() }} &middot; modo {{ $suggestion->mode->label() }} &middot;
            prompt {{ $suggestion->prompt_version }} &middot; tentativa {{ $suggestion->generation_attempt }} &middot;
            turno {{ $suggestion->turn_number }}
        </span>

        @if($stale)
            <p class="alert error" style="margin-top:8px;">
                Esta sugestão esta <strong>obsoleta</strong>: a pessoa enviou uma mensagem nova depois da que originou o texto. O envio esta bloqueado.
            </p>
        @elseif(! $sendCheck['allowed'])
            <p class="alert error" style="margin-top:8px;">Envio bloqueado: {{ $sendCheck['reason'] }}</p>
        @endif

        @if($suggestion->handoff_reason)
            <p class="alert error" style="margin-top:8px;">
                Encaminhamento sugerido: {{ $suggestion->handoff_reason->label() }}
            </p>
        @endif

        @if($suggestion->validation_error)
            <p class="alert error" style="margin-top:8px;">Texto reprovado na validação: {{ $suggestion->validation_error }}</p>
        @endif
    </section>

    <div class="grid grid-2" style="margin-top:16px;">
        <section class="card">
            <h2>O que a pessoa disse</h2>
            <p><strong>Pergunta original:</strong> {{ $suggestion->state?->selected_question_snapshot ?? '-' }}</p>
            <p style="white-space:pre-wrap;">{{ $suggestion->sourceMessage?->body ?? '-' }}</p>
            <p class="muted">
                Contato:
                @if($canSeeContactData)
                    {{ $suggestion->conversation?->contact?->name ?? 'não identificado' }}
                @else
                    {{ $suggestion->conversation?->contact?->phone_normalized ? Str::mask($suggestion->conversation->contact->phone_normalized, '*', 4, -4) : 'não identificado' }}
                @endif
            </p>
            @if($suggestion->insight)
                <p><strong>Resumo:</strong> {{ $suggestion->insight->summary ?? '-' }}</p>
                <p><strong>Tema:</strong> {{ $suggestion->insight->topic?->name ?? '-' }}</p>
            @endif
            @if($suggestion->classification)
                <p><strong>Classificação:</strong> {{ $suggestion->classification->classification->label() }}</p>
            @endif
            <p><strong>Confiança da geração:</strong> {{ $suggestion->confidence !== null ? number_format($suggestion->confidence, 2) : '-' }}</p>
            @if($suggestion->conversation)
                <a class="btn ghost" href="{{ route('admin.conversations.show', $suggestion->conversation) }}">Abrir conversa</a>
            @endif
        </section>

        <section class="card">
            <h2>Texto gerado</h2>
            <p style="white-space:pre-wrap;">{{ $suggestion->generated_text ?? 'Nenhum texto gerado para esta ação.' }}</p>

            @if($suggestion->wasEdited())
                <h2 style="margin-top:16px;">Texto enviado</h2>
                <p style="white-space:pre-wrap;">{{ $suggestion->final_text }}</p>
            @endif

            @if($suggestion->status->value === 'sent')
                <p class="muted">
                    Enviada {{ $suggestion->sent_at?->format($dateTimeFormat) }}
                    @if($suggestion->auto_sent) automaticamente @else por {{ $suggestion->approver?->name ?? 'operador' }} @endif
                </p>
            @endif
        </section>
    </div>

    @if($suggestion->status->isLive() && ! $stale)
        @can('reply_suggestions.approve')
            <section class="card" style="margin-top:16px;">
                <h2>Aprovar e enviar</h2>
                <p class="muted">Edite se precisar. O texto original permanece armazenado.</p>
                <form method="post" action="{{ route('admin.reply-suggestions.approve', $suggestion) }}">
                    @csrf
                    <label for="final_text">Texto a enviar</label>
                    <textarea id="final_text" name="final_text" rows="4">{{ old('final_text', $suggestion->outgoingText()) }}</textarea>
                    <div class="actions" style="margin-top:12px;">
                        <button class="btn" type="submit" @disabled(! $sendCheck['allowed'])>Aprovar e enviar</button>
                    </div>
                </form>
            </section>
        @endcan
    @endif

    @if($suggestion->grounding_status)
        <section class="card" style="margin-top:16px;">
            <h2>Fundamentação na base oficial</h2>
            <p>
                Veredito: <strong>{{ $suggestion->grounding_status->label() }}</strong>.
                @unless($suggestion->grounding_status->allowsSending())
                    Esta sugestão não pode ser enviada: a validação de fundamentação reprovou o texto.
                @endunless
            </p>
            @if($suggestion->grounding_error)
                <p class="muted">Motivo: {{ $suggestion->grounding_error }}</p>
            @endif

            <h3>Fontes usadas</h3>
            @forelse($suggestion->citations as $citation)
                <article class="card nested">
                    <p class="muted">
                        {{ $citation->document_title_snapshot ?: 'Documento removido' }}
                        @if($citation->document_version) &middot; versão {{ $citation->document_version }} @endif
                        @if($citation->page) &middot; página {{ $citation->page }} @endif
                        @if($citation->section) &middot; seção {{ $citation->section }} @endif
                        @unless($citation->is_valid)
                            &middot; <strong>citação recusada</strong>{{ $citation->invalid_reason ? ': '.$citation->invalid_reason : '' }}
                        @endunless
                    </p>
                    @if($citation->content_snapshot)
                        <p>{{ $citation->content_snapshot }}</p>
                    @endif
                </article>
            @empty
                <p class="muted">Nenhuma fonte registrada para esta sugestão.</p>
            @endforelse
            <p class="muted">
                O conteúdo acima e a copia do trecho no momento em que ele foi usado. Ele continua aqui mesmo que o documento
                original tenha sido substituído ou removido depois.
            </p>
        </section>
    @endif

    <section class="card" style="margin-top:16px;">
        <h2>Outras ações</h2>
        <div class="grid grid-2">
            @can('reply_suggestions.reject')
                <form method="post" action="{{ route('admin.reply-suggestions.reject', $suggestion) }}">
                    @csrf
                    <label for="reason">Rejeitar (motivo opcional)</label>
                    <input id="reason" name="reason" value="{{ old('reason') }}">
                    <div class="actions" style="margin-top:8px;"><button class="btn danger">Rejeitar</button></div>
                </form>
            @endcan

            @can('reply_suggestions.regenerate')
                <form method="post" action="{{ route('admin.reply-suggestions.regenerate', $suggestion) }}">
                    @csrf
                    <label for="justification">Regenerar (justificativa obrigatória)</label>
                    <input id="justification" name="justification" required value="{{ old('justification') }}">
                    <div class="actions" style="margin-top:8px;"><button class="btn secondary">Regenerar</button></div>
                </form>
            @endcan

            @can('reply_suggestions.reject')
                <form method="post" action="{{ route('admin.reply-suggestions.take-over', $suggestion) }}" onsubmit="return confirm('Assumir esta conversa manualmente e pausar a automacao?')">
                    @csrf
                    <div class="actions"><button class="btn ghost">Assumir manualmente</button></div>
                </form>
            @endcan

            @can('reply_suggestions.feedback')
                <form method="post" action="{{ route('admin.reply-suggestions.feedback', $suggestion) }}">
                    @csrf
                    <label for="feedback">Feedback sobre a sugestão</label>
                    <select id="feedback" name="feedback">
                        @foreach($feedbacks as $option)
                            <option value="{{ $option->value }}" @selected($suggestion->feedback?->value === $option->value)>{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <input name="reason" placeholder="Motivo (opcional)" style="margin-top:8px;">
                    <div class="actions" style="margin-top:8px;"><button class="btn ghost">Registrar feedback</button></div>
                </form>
            @endcan
        </div>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Histórico desta mensagem</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tentativa</th><th>Ação</th><th>Situação</th><th>Justificativa</th><th>Data</th><th></th></tr></thead>
                <tbody>
                    @foreach($history as $item)
                        <tr>
                            <td>{{ $item->generation_attempt }}</td>
                            <td>{{ $item->action->label() }}</td>
                            <td>{{ $item->status->label() }}</td>
                            <td>{{ Str::limit($item->regeneration_reason ?? $item->rejection_reason ?? $item->blocked_reason ?? '-', 50) }}</td>
                            <td>{{ $item->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td class="actions">
                                @if($item->id !== $suggestion->id)
                                    <a class="btn ghost" href="{{ route('admin.reply-suggestions.show', $item) }}">Abrir</a>
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
</x-layouts.app>
