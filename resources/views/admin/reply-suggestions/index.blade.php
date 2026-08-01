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
                            <td>{{ Str::limit($suggestion->outgoingText() ?: '-', 70) }}</td>
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
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.reply-suggestions.show', $suggestion) }}">Revisar</a></td>
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
