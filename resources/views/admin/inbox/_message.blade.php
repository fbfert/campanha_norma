<article class="message-bubble {{ $message->direction->value }}" data-message-id="{{ $message->id }}">
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
