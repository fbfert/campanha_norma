<article class="message-bubble {{ $message->direction->value }}" data-message-id="{{ $message->id }}">
    <div class="message-meta">
        <strong>{{ $message->direction->label() }}</strong>
        @if($message->origin === \App\Enums\ConversationMessageOrigin::Automation)
            <span class="badge" style="background:var(--text-muted);color:var(--text-inverse);">Automática</span>
        @endif
        @if($message->generated_by_ai)
            <span class="badge" style="background:var(--ai-mark);color:var(--text-inverse);" title="Texto sugerido por IA{{ $message->approver ? ', aprovado por '.$message->approver->name : '' }}">
                Sugerida por IA{{ $message->approver ? ' - aprovada por '.$message->approver->name : ' - envio automático' }}
            </span>
        @endif
        <span>{{ ($message->sent_at ?? $message->received_at ?? $message->created_at)?->format($dateTimeFormat) }}</span>
        <span>{{ $message->status->label() }}</span>
        @if($message->creator)
            <span>{{ $message->creator->name }}</span>
        @endif
    </div>
    @can('inbox.view_message_content')
        <p>{{ $message->body ?: ($message->has_media ? '[midia não baixada]' : '') }}</p>
    @else
        <p class="muted">Conteúdo protegido.</p>
    @endcan
    @if($message->error_code)
        <div class="alert error">{{ $message->error_code }} - {{ $message->error_message }}</div>
    @endif
</article>
