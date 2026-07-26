<x-layouts.app title="Conversa" breadcrumbs="Atendimento / Conversas / Conversa">
    <div class="conversation-workspace" x-data="{ body: '', detailsOpen: false }">
        @php
            $displayPhone = $conversation->whatsappPhoneDigits();
        @endphp
        <aside class="conversation-panel conversation-panel-list">
            <a class="btn ghost" href="{{ route('admin.conversations.index') }}">Voltar para conversas</a>
            <div class="conversation-selected">
                <strong>{{ $conversation->contact?->name ?? 'Contato nao identificado' }}</strong>
                <span class="muted">{{ $conversation->last_message_at?->format($dateTimeFormat) ?? 'Sem mensagens' }}</span>
            </div>
        </aside>

        <section class="conversation-chat">
            <header class="conversation-chat-header">
                <div>
                    <h2>{{ $conversation->contact?->name ?? 'Contato nao identificado' }}</h2>
                    <p class="muted">
                        {{ $conversation->contact?->phone_normalized ? Str::mask($conversation->contact->phone_normalized, '*', 4, -4) : ($displayPhone ? Str::mask($displayPhone, '*', 4, -4) : 'Telefone nao disponivel') }}
                        | {{ $conversation->status->label() }} | {{ $conversation->priority->label() }}
                    </p>
                </div>
                <button class="btn ghost details-toggle" type="button" x-on:click="detailsOpen = !detailsOpen">Detalhes</button>
            </header>

            @if($conversation->contact?->do_not_contact)
                <div class="alert error">Contato marcado como nao contatar. Respostas pelo sistema ficam bloqueadas.</div>
            @endif

            <div class="conversation-timeline">
                @forelse($conversation->messages()->oldest('created_at')->get() as $message)
                    <article class="message-bubble {{ $message->direction->value }}">
                        <div class="message-meta">
                            <strong>{{ $message->direction->label() }}</strong>
                            <span>{{ ($message->sent_at ?? $message->received_at ?? $message->created_at)?->format($dateTimeFormat) }}</span>
                            <span>{{ $message->status->label() }}</span>
                            @if($message->creator)
                                <span>{{ $message->creator->name }}</span>
                            @endif
                        </div>
                        @can('inbox.view_message_content')
                            <p>{{ $message->body ?: ($message->has_media ? '[midia nao baixada]' : '') }}</p>
                        @else
                            <p class="muted">Conteudo protegido.</p>
                        @endcan
                        @if($message->error_code)
                            <div class="alert error">{{ $message->error_code }} - {{ $message->error_message }}</div>
                        @endif
                    </article>
                @empty
                    <div class="empty-state">Nenhuma mensagem nesta conversa.</div>
                @endforelse

                @foreach($conversation->notes as $note)
                    <article class="message-bubble internal_note">
                        <div class="message-meta"><strong>Nota interna</strong><span>{{ $note->user?->name }}</span><span>{{ $note->created_at?->format($dateTimeFormat) }}</span></div>
                        <p>{{ $note->body }}</p>
                    </article>
                @endforeach
            </div>

            @can('inbox.reply')
                <footer class="conversation-reply">
                    <form method="post" action="{{ route('admin.inbox.reply', $conversation) }}" x-on:submit="$el.querySelector('button[type=submit]').disabled = true">
                        @csrf
                        <label for="reply_body">Resposta manual</label>
                        @php
                            $replyBlocked = !$conversation->contact || $conversation->contact?->do_not_contact || $conversation->contact?->status?->value !== 'active';
                            $replyBlockReason = !$conversation->contact ? 'Associe um contato antes de responder.' : ($conversation->contact?->do_not_contact ? 'Contato marcado como nao contatar.' : ($conversation->contact?->status?->value !== 'active' ? 'Contato inativo ou bloqueado.' : null));
                        @endphp
                        @if($replyBlockReason)
                            <p class="alert error">{{ $replyBlockReason }}</p>
                        @endif
                        <textarea id="reply_body" name="body" rows="4" maxlength="4096" required x-model="body" x-on:keydown.ctrl.enter="$el.form.requestSubmit()" @disabled($replyBlocked)></textarea>
                        <div class="actions" style="justify-content:space-between;align-items:center;">
                            <span class="muted"><span x-text="body.length"></span>/4096 caracteres. Ctrl + Enter envia.</span>
                            <button class="btn" type="submit" @disabled($replyBlocked)>Enviar resposta</button>
                        </div>
                    </form>
                </footer>
            @endcan
        </section>

        <aside class="conversation-details" x-bind:class="{ 'open': detailsOpen }">
            <section class="card">
                <h2>Detalhes</h2>
                <p><strong>Contato:</strong> {{ $conversation->contact?->name ?? 'Nao associado' }}</p>
                <p><strong>Telefone:</strong> {{ $conversation->contact?->phone_normalized ? Str::mask($conversation->contact->phone_normalized, '*', 4, -4) : ($displayPhone ? Str::mask($displayPhone, '*', 4, -4) : 'Nao disponivel') }}</p>
                @if(! $displayPhone && $conversation->whatsappIdentifierForDisplay())
                    <p><strong>Identificador do WhatsApp:</strong> {{ $conversation->whatsappIdentifierForDisplay() }}</p>
                @endif
                <p><strong>E-mail:</strong> {{ $conversation->contact?->email ?? '-' }}</p>
                <p><strong>Cidade:</strong> {{ $conversation->contact?->city ?? '-' }} {{ $conversation->contact?->state }}</p>
                <p><strong>Responsavel:</strong> {{ $conversation->assignee?->name ?? 'Sem responsavel' }}</p>
                <p><strong>Primeira mensagem:</strong> {{ $conversation->messages()->oldest()->first()?->created_at?->format($dateTimeFormat) ?? '-' }}</p>
                <p><strong>Ultima mensagem:</strong> {{ $conversation->last_message_at?->format($dateTimeFormat) ?? '-' }}</p>
                <p><strong>Total de mensagens:</strong> {{ $conversation->messages()->count() }}</p>
                @if(! $conversation->contact && ! $displayPhone)
                    <p class="muted">Este chat nao possui telefone confiavel. A associacao manual de contato e opcional, mas pode ser necessaria para responder.</p>
                @endif
                @if($conversation->contact)
                    <a class="btn ghost" href="{{ route('admin.contacts.show', $conversation->contact) }}">Abrir cadastro</a>
                @endif
            </section>

            <section class="card">
                <h2>Acoes</h2>
                <div class="stack-list">
                    @can('inbox.assign')
                        <form method="post" action="{{ route('admin.inbox.assign', $conversation) }}">@csrf <button class="btn secondary">Atribuir a mim</button></form>
                        <form method="post" action="{{ route('admin.inbox.unassign', $conversation) }}">@csrf <button class="btn ghost">Remover atribuicao</button></form>
                    @endcan
                    @can('inbox.change_status')
                        <form method="post" action="{{ route('admin.inbox.status', $conversation) }}">@csrf <label>Status<select name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($conversation->status === $status)>{{ $status->label() }}</option>@endforeach</select></label><button class="btn secondary">Alterar</button></form>
                    @endcan
                    @can('inbox.change_priority')
                        <form method="post" action="{{ route('admin.inbox.priority', $conversation) }}">@csrf <label>Prioridade<select name="priority">@foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected($conversation->priority === $priority)>{{ $priority->label() }}</option>@endforeach</select></label><button class="btn secondary">Alterar</button></form>
                    @endcan
                    @can('inbox.archive')
                        <form method="post" action="{{ route($conversation->is_archived ? 'admin.inbox.unarchive' : 'admin.inbox.archive', $conversation) }}">@csrf <button class="btn secondary">{{ $conversation->is_archived ? 'Desarquivar' : 'Arquivar' }}</button></form>
                    @endcan
                </div>
            </section>

            @if(!$conversation->contact)
                @can('inbox.associate_contact')
                    <section class="card">
                        <h2>Associar contato</h2>
                        @if(! $displayPhone)
                            <p class="muted">Nao ha telefone confiavel para este chat. Escolha o contato correto manualmente.</p>
                        @endif
                        <form method="post" action="{{ route('admin.inbox.associate-contact', $conversation) }}">
                            @csrf
                            <label>Contato<select name="contact_id">@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} - {{ $contact->phone_normalized }}</option>@endforeach</select></label>
                            <button class="btn">Associar</button>
                        </form>
                    </section>
                @endcan
            @endif

            @can('inbox.add_notes')
                <section class="card">
                    <h2>Nota interna</h2>
                    <form method="post" action="{{ route('admin.inbox.notes.store', $conversation) }}">@csrf <label>Nota<textarea name="body" rows="3" required></textarea></label><button class="btn secondary">Adicionar nota</button></form>
                </section>
            @endcan

            @can('inbox.manage_tags')
                <section class="card">
                    <h2>Etiquetas</h2>
                    <div class="actions">@foreach($conversation->tags as $tag)<span class="badge" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>@endforeach</div>
                    <form method="post" action="{{ route('admin.inbox.tags.store', $conversation) }}">@csrf <label>Nome<input name="name" required></label><label>Cor<input name="color" value="#176b4d"></label><button class="btn secondary">Adicionar</button></form>
                </section>
            @endcan

            @can('inbox.mark_do_not_contact')
                @if($conversation->contact && ! $conversation->contact->do_not_contact)
                    <section class="card">
                        <h2>Nao contatar</h2>
                        <form method="post" action="{{ route('admin.inbox.do-not-contact', $conversation) }}">@csrf <label>Motivo<input name="reason" required></label><button class="btn danger">Marcar nao contatar</button></form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
</x-layouts.app>
