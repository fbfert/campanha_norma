<x-layouts.app title="Sugestões de resposta" breadcrumbs="Inicio / Pesquisa conversacional / Sugestoes">
    <section class="card">
        <p class="muted">
            Respostas sugeridas por IA para aprofundar a opinião da própria pessoa. Modo global atual:
            <strong>{{ $globalMode->label() }}</strong>.
            @if(! $globalMode->allowsSending())
                Nenhuma sugestão pode ser enviada neste modo.
            @endif
        </p>
        <p class="muted">Cada sugestão e aprovada individualmente. Não existe aprovação em massa.</p>
        <form method="get" class="grid grid-3">
            <div>
                <label for="status">Situação</label>
                <select id="status" name="status">
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status', 'pending') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="actions">
                <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
                <a class="btn ghost" href="{{ route('admin.reply-suggestions.index') }}">Pendentes</a>
            </div>
        </form>

        @php
            // Contagem para a confirmação: aprovar "todas" sem saber quantas
            // são e o que transforma revisão em carimbo.
            $pendentesValidas = \App\Models\ConversationReplySuggestion::query()
                ->where('status', \App\Enums\ReplySuggestionStatus::Pending)
                ->get()
                ->reject(fn ($item) => $item->isStale())
                ->count();
        @endphp

        <div class="actions" style="margin-top:12px;">
            @can('reply_suggestions.reject')
                <form method="post" action="{{ route('admin.reply-suggestions.discard-stale') }}"
                    onsubmit="return confirm('Retirar da fila as sugestões obsoletas? Nenhuma mensagem será enviada.')">
                    @csrf
                    <button class="btn secondary" type="submit">Descartar obsoletas</button>
                </form>
            @endcan

            @can('reply_suggestions.approve')
                <form method="post" action="{{ route('admin.reply-suggestions.approve-all') }}"
                    onsubmit="return confirm('Aprovar e enviar {{ $pendentesValidas }} sugestão(ões) sem abrir uma a uma? As mensagens vão para os contatos.')">
                    @csrf
                    <button class="btn" type="submit">Aprovar todas pendentes ({{ $pendentesValidas }})</button>
                </form>
            @endcan
        </div>

        <p class="muted">
            <strong>Descartar obsoletas</strong> tira da fila o que perdeu a validade porque a pessoa escreveu
            de novo; nada e enviado. <strong>Aprovar todas</strong> envia de fato: as obsoletas ficam de fora e
            cada uma ainda passa por todos os guards, mas ninguém le os textos um a um.
        </p>
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Contato</th>
                        <th>Mensagem da pessoa</th>
                        <th>Sugestão</th>
                        <th>Ação</th>
                        <th>Tema</th>
                        <th>Confiança</th>
                        <th>Situação</th>
                        <th>Criada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suggestions as $suggestion)
                        <tr>
                            <td>
                                @if($canSeeContactData)
                                    {{ $suggestion->conversation?->contact?->name ?? 'Contato não identificado' }}
                                @else
                                    {{ $suggestion->conversation?->contact?->phone_normalized ? Str::mask($suggestion->conversation->contact->phone_normalized, '*', 4, -4) : 'Contato não identificado' }}
                                @endif
                            </td>
                            <td>{{ Str::limit($suggestion->sourceMessage?->body ?? '-', 70) }}</td>
                            {{-- Texto inteiro, e não recortado: existe um botão de
                                 aprovar nesta linha, e aprovar o que não se leu e o
                                 contrário do que a revisão individual serve. --}}
                            <td>{{ $suggestion->outgoingText() ?: '-' }}</td>
                            <td>{{ $suggestion->action->label() }}</td>
                            <td>{{ $suggestion->topic?->name ?? '-' }}</td>
                            <td>{{ $suggestion->confidence !== null ? number_format($suggestion->confidence, 2) : '-' }}</td>
                            <td>
                                {{ $suggestion->status->label() }}
                                @if($suggestion->status->isLive() && $suggestion->isStale())
                                    <span class="badge" style="background:var(--warning);color:var(--text-inverse);">Obsoleta</span>
                                @endif
                            </td>
                            <td>{{ $suggestion->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td class="actions">
                                @if($suggestion->status === \App\Enums\ReplySuggestionStatus::Pending && ! $suggestion->isStale())
                                    @can('reply_suggestions.approve')
                                        <form method="post" action="{{ route('admin.reply-suggestions.approve', $suggestion) }}" onsubmit="return confirm('Aprovar e enviar este texto para o contato?')">
                                            @csrf
                                            <button class="btn" type="submit">Aprovar</button>
                                        </form>
                                    @endcan
                                @endif
                                <a class="btn ghost" href="{{ route('admin.reply-suggestions.show', $suggestion) }}">Revisar</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9">Nenhuma sugestão nesta situação.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $suggestions->links() }}
    </section>
</x-layouts.app>
