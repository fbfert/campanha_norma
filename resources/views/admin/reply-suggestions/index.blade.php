<x-layouts.app title="Sugestoes de resposta" breadcrumbs="Inicio / Pesquisa conversacional / Sugestoes">
    <section class="card">
        <p class="muted">
            Respostas sugeridas por IA para aprofundar a opiniao da propria pessoa. Modo global atual:
            <strong>{{ $globalMode->label() }}</strong>.
            @if(! $globalMode->allowsSending())
                Nenhuma sugestao pode ser enviada neste modo.
            @endif
        </p>
        <p class="muted">Cada sugestao e aprovada individualmente. Nao existe aprovacao em massa.</p>
        <form method="get" class="grid grid-3">
            <div>
                <label for="status">Situacao</label>
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
                        <th>Sugestao</th>
                        <th>Acao</th>
                        <th>Tema</th>
                        <th>Confianca</th>
                        <th>Situacao</th>
                        <th>Criada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suggestions as $suggestion)
                        <tr>
                            <td>
                                @if($canSeeContactData)
                                    {{ $suggestion->conversation?->contact?->name ?? 'Contato nao identificado' }}
                                @else
                                    {{ $suggestion->conversation?->contact?->phone_normalized ? Str::mask($suggestion->conversation->contact->phone_normalized, '*', 4, -4) : 'Contato nao identificado' }}
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
                                    <span class="badge" style="background:#b45309;color:#fff;">Obsoleta</span>
                                @endif
                            </td>
                            <td>{{ $suggestion->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.reply-suggestions.show', $suggestion) }}">Revisar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9">Nenhuma sugestao nesta situacao.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $suggestions->links() }}
    </section>
</x-layouts.app>
