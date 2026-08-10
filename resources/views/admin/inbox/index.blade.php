<x-layouts.app title="CONVERSAS" breadcrumbs="Atendimento / Conversas">
    <section class="card">
        <div class="actions" style="justify-content:space-between;align-items:flex-start;">
            <div>
                <h2 style="margin-top:0;">Conversas</h2>
                @if($latestSync)
                    <p class="muted">Última sincronização: {{ $latestSync->status->label() }} @if($latestSync->finished_at)em {{ $latestSync->finished_at->format($dateTimeFormat) }}@endif | chats {{ $latestSync->chats_processed }} | mensagens importadas {{ $latestSync->messages_imported }} | modo {{ data_get($latestSync->options, 'sync_mode') === 'compatibility' ? 'compatibilidade' : 'padrao' }}</p>
                    @if($latestSync->error_code)
                        @if($syncFailureNotice && $syncFailureNotice['superada'])
                            {{-- A falha é anterior à reconexão: informa, sem alarmar. --}}
                            <p class="alert warning">
                                Esta falha é anterior à reconexão do WhatsApp, feita em
                                {{ $syncFailureNotice['reconectado_em']->format($dateTimeFormat) }}, e não descreve o
                                estado atual. A sincronização automática roda a cada 15 minutos e a próxima execução
                                deve substituir esta linha. Para não esperar, use "Atualizar conversas".
                            </p>
                        @else
                            <p class="alert error">{{ $latestSync->error_code }} - {{ $latestSync->error_message }}</p>
                        @endif
                    @endif
                @else
                    <p class="muted">Nenhuma sincronização executada.</p>
                @endif
            </div>
            <div class="actions">
                @can('inbox.sync')
                    <form method="post" action="{{ route('admin.conversations.sync') }}" onsubmit="return confirm('Buscar no WhatsApp as conversas da sessao atual e trazer o que houver de novo?')">
                        @csrf
                        <button class="btn secondary" type="submit" @disabled($syncActive)>
                            <x-icon name="refresh" size="16" />
                            {{ $syncActive ? ($latestSync?->status?->value === 'pending' ? 'Aguardando worker...' : 'Atualizando...') : 'Atualizar conversas' }}
                        </button>
                    </form>
                @endcan
                @if(auth()->user()->can('inbox.reply') && auth()->user()->can('contacts.view'))
                    <a class="btn" href="{{ route('admin.conversations.create') }}"><x-icon name="plus" size="16" />Nova conversa</a>
                @endif
            </div>
        </div>
        <form method="get" class="filters-grid conversation-filters">
            <label>Busca <input name="q" value="{{ request('q') }}" placeholder="Nome, telefone, cidade ou mensagem"></label>
            <label>Status
                <select name="status">
                    <option value="">Todos</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label>Prioridade
                <select name="priority">
                    <option value="">Todas</option>
                    @foreach($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label>Responsável
                <select name="assigned">
                    <option value="">Todos</option>
                    <option value="me" @selected(request('assigned') === 'me')>Atribuidas a mim</option>
                    <option value="none" @selected(request('assigned') === 'none')>Sem responsável</option>
                </select>
            </label>
            <label>Etiqueta
                <select name="tag_id">
                    <option value="">Todas</option>
                    @foreach($tags as $tag)
                        <option value="{{ $tag->id }}" @selected((string) request('tag_id') === (string) $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
            </label>
            {{-- O seletor de situação já filtra por isto, mas fica no meio de
                 outros seis campos. Quem abre esta tela quer saber quem está
                 esperando por nós, e isso merece um clique. --}}
            <label><input type="checkbox" name="awaiting_operator" value="1" @checked(request()->boolean('awaiting_operator'))> Aguardando operador</label>
            <label><input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))> Somente não lidas</label>
            <label><input type="checkbox" name="no_contact" value="1" @checked(request()->boolean('no_contact'))> Sem contato associado</label>
            <label><input type="checkbox" name="do_not_contact" value="1" @checked(request()->boolean('do_not_contact'))> Não contatar</label>
            <label><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Arquivadas</label>
            <label><input type="checkbox" name="not_archived" value="1" @checked(request()->boolean('not_archived'))> Não arquivadas</label>
            <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
        </form>
    </section>

    <section class="conversation-shell" style="margin-top:16px;">
        <div class="conversation-list">
            @forelse($conversations as $conversation)
                @php($last = $conversation->latestMessage)
                @php($displayPhone = $conversation->whatsappPhoneDigits())
                <a class="conversation-list-item {{ $conversation->unread_count > 0 ? 'unread' : '' }}" href="{{ route('admin.conversations.show', $conversation) }}">
                    <div class="conversation-list-top">
                        <strong>{{ $conversation->contact?->name ?? ($last?->sender_name_snapshot ?: 'Contato não identificado') }}</strong>
                        <span class="muted">{{ $conversation->last_message_at?->format($conversation->last_message_at->isToday() ? 'H:i' : $dateFormat) }}</span>
                    </div>
                    <div class="muted">
                        {{ $conversation->contact?->phone_normalized ? Str::mask($conversation->contact->phone_normalized, '*', 4, -4) : ($displayPhone ? Str::mask($displayPhone, '*', 4, -4) : 'Telefone não disponível') }}
                    </div>
                    @if(! $displayPhone && $conversation->whatsappIdentifierForDisplay())
                        <div class="muted">ID WhatsApp: {{ $conversation->whatsappIdentifierForDisplay() }}</div>
                    @endif
                    <div class="conversation-preview">@can('inbox.view_message_content'){{ Str::limit($last?->body ?: ($last?->has_media ? '[midia]' : 'Sem mensagens'), 90) }}@else Conteúdo protegido @endcan</div>
                    <div class="conversation-meta">
                        <span class="badge {{ $conversation->status === \App\Enums\ConversationStatus::WaitingOperator ? 'awaiting-operator' : '' }}">{{ $conversation->status->label() }}</span>
                        <span class="badge">{{ $conversation->priority->label() }}</span>
                        <span>{{ $conversation->assignee?->name ?? 'Sem responsável' }}</span>
                        @if($conversation->unread_count > 0)<span class="unread-pill">{{ $conversation->unread_count }}</span>@endif
                    </div>
                    @if($conversation->contact?->do_not_contact)<div class="conversation-warning">Não contatar</div>@endif
                </a>
            @empty
                <div class="card">Nenhuma conversa encontrada.</div>
            @endforelse
        </div>
    </section>
    <div style="margin-top:16px;">{{ $conversations->links() }}</div>
</x-layouts.app>
