<x-layouts.app title="Conversa" breadcrumbs="Atendimento / Caixa de entrada / Conversa">
    <section class="card">
        <div class="actions" style="justify-content:space-between;">
            <div>
                <h2>{{ $conversation->contact?->name ?? 'Contato nao identificado' }}</h2>
                <p class="muted">{{ $conversation->contact?->phone_normalized ? Str::mask($conversation->contact->phone_normalized, '*', 4, -4) : 'Telefone nao associado' }} | {{ $conversation->status->label() }} | {{ $conversation->priority->label() }}</p>
                @if($conversation->contact?->do_not_contact)<div class="alert danger">Contato marcado como nao contatar.</div>@endif
            </div>
            <div class="actions">
                @if($conversation->contact)<a class="btn ghost" href="{{ route('admin.contacts.show', $conversation->contact) }}">Abrir cadastro</a>@endif
            </div>
        </div>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Acoes</h2>
        <div class="actions">
            @can('inbox.assign')
                <form method="post" action="{{ route('admin.inbox.assign', $conversation) }}">@csrf <button class="btn secondary">Atribuir a mim</button></form>
                <form method="post" action="{{ route('admin.inbox.unassign', $conversation) }}">@csrf <button class="btn ghost">Remover atribuicao</button></form>
            @endcan
            @can('inbox.change_status')
                <form method="post" action="{{ route('admin.inbox.status', $conversation) }}">@csrf <select name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($conversation->status === $status)>{{ $status->label() }}</option>@endforeach</select><button class="btn secondary">Status</button></form>
            @endcan
            @can('inbox.change_priority')
                <form method="post" action="{{ route('admin.inbox.priority', $conversation) }}">@csrf <select name="priority">@foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected($conversation->priority === $priority)>{{ $priority->label() }}</option>@endforeach</select><button class="btn secondary">Prioridade</button></form>
            @endcan
            @can('inbox.archive')
                <form method="post" action="{{ route($conversation->is_archived ? 'admin.inbox.unarchive' : 'admin.inbox.archive', $conversation) }}">@csrf <button class="btn secondary">{{ $conversation->is_archived ? 'Desarquivar' : 'Arquivar' }}</button></form>
            @endcan
        </div>
    </section>

    @if(!$conversation->contact)
        @can('inbox.associate_contact')
            <section class="card" style="margin-top:16px;">
                <h2>Associar contato</h2>
                <form method="post" action="{{ route('admin.inbox.associate-contact', $conversation) }}" class="actions">
                    @csrf
                    <select name="contact_id">@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} - {{ $contact->phone_normalized }}</option>@endforeach</select>
                    <button class="btn">Associar</button>
                </form>
            </section>
        @endcan
    @endif

    <section class="card" style="margin-top:16px;">
        <h2>Linha do tempo</h2>
        <div class="stack-list">
            @foreach($conversation->messages()->oldest()->get() as $message)
                <div class="card" style="margin:8px 0;background:{{ $message->direction->value === 'incoming' ? '#eef8f3' : ($message->direction->value === 'internal_note' ? '#fff8e8' : '#f5f7fb') }};">
                    <strong>{{ $message->direction->label() }}</strong>
                    <span class="muted">{{ $message->created_at?->format($dateTimeFormat) }} | {{ $message->status->label() }}</span>
                    @can('inbox.view_message_content')<p style="white-space:pre-wrap;">{{ $message->body }}</p>@else<p class="muted">Conteudo protegido.</p>@endcan
                    @if($message->error_code)<p class="alert danger">{{ $message->error_code }} - {{ $message->error_message }}</p>@endif
                </div>
            @endforeach
            @foreach($conversation->notes as $note)
                <div class="card" style="margin:8px 0;background:#fff8e8;"><strong>Nota interna</strong><span class="muted"> {{ $note->user?->name }} - {{ $note->created_at?->format($dateTimeFormat) }}</span><p>{{ $note->body }}</p></div>
            @endforeach
        </div>
    </section>

    @can('inbox.reply')
        <section class="card" style="margin-top:16px;">
            <h2>Resposta manual</h2>
            <form method="post" action="{{ route('admin.inbox.reply', $conversation) }}">
                @csrf
                <label>Mensagem<textarea name="body" rows="4" maxlength="4096" required></textarea></label>
                <button class="btn" type="submit">Enviar resposta</button>
            </form>
        </section>
    @endcan

    @can('inbox.add_notes')
        <section class="card" style="margin-top:16px;">
            <h2>Nota interna</h2>
            <form method="post" action="{{ route('admin.inbox.notes.store', $conversation) }}">@csrf <label>Nota<textarea name="body" rows="3" required></textarea></label><button class="btn secondary">Adicionar nota</button></form>
        </section>
    @endcan

    @can('inbox.manage_tags')
        <section class="card" style="margin-top:16px;">
            <h2>Etiquetas</h2>
            <div class="actions">@foreach($conversation->tags as $tag)<span class="badge" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>@endforeach</div>
            <form method="post" action="{{ route('admin.inbox.tags.store', $conversation) }}" class="actions">@csrf <input name="name" placeholder="Nova etiqueta" required><input name="color" value="#176b4d"><button class="btn secondary">Adicionar</button></form>
        </section>
    @endcan

    @can('inbox.mark_do_not_contact')
        @if($conversation->contact && !$conversation->contact->do_not_contact)
            <section class="card" style="margin-top:16px;">
                <h2>Nao contatar</h2>
                <form method="post" action="{{ route('admin.inbox.do-not-contact', $conversation) }}" class="actions">@csrf <input name="reason" placeholder="Motivo" required><button class="btn danger">Marcar nao contatar</button></form>
            </section>
        @endif
    @endcan
</x-layouts.app>
