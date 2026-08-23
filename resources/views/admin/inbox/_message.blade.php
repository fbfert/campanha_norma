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
        @if($message->has_media)
            {{--
                A mídia é servida por rota autenticada, e não daqui.

                O arquivo só é buscado do WhatsApp quando o navegador pede a
                URL: renderizar a página não pode disparar uma ida ao Puppeteer
                por imagem visível, porque é o mesmo processo que segura a
                sessão de pé. O `loading="lazy"` e o `preload="none"` fecham a
                conta — o que está fora da tela nem chega a ser pedido.
            --}}
            @php $medium = $message->medium; @endphp

            @if($medium?->needsExplanation())
                <p class="muted">
                    <x-icon name="alert" size="16" />
                    {{ $medium->status === \App\Enums\MediaStorageStatus::Purged
                        ? 'Havia um arquivo aqui. Ele passou do prazo de retenção e foi apagado.'
                        : ($medium->status === \App\Enums\MediaStorageStatus::TooLarge
                            ? 'Arquivo grande demais para ser guardado.'
                            : 'Não foi possível baixar este arquivo da sessão do WhatsApp.') }}
                </p>
                <p class="muted">
                    {{ $medium->error_message }}
                </p>
            @else
                @php $url = route('admin.inbox.messages.media', [$conversation ?? $message->conversation_id, $message]); @endphp

                @switch($message->message_type)
                    @case('image')
                    @case('sticker')
                        <a href="{{ $url }}" target="_blank" rel="noopener">
                            <img class="message-media" src="{{ $url }}" alt="Imagem recebida na conversa" loading="lazy">
                        </a>
                        @break

                    @case('ptt')
                    @case('audio')
                        <audio class="message-media" controls preload="none" src="{{ $url }}">
                            Seu navegador não toca áudio. <a href="{{ $url }}">Baixar o arquivo</a>.
                        </audio>
                        @break

                    @case('video')
                        <video class="message-media" controls preload="none" src="{{ $url }}">
                            Seu navegador não toca vídeo. <a href="{{ $url }}">Baixar o arquivo</a>.
                        </video>
                        @break

                    @default
                        <p><a class="btn ghost" href="{{ $url }}" target="_blank" rel="noopener"><x-icon name="download" size="16" />Abrir arquivo recebido</a></p>
                @endswitch
            @endif
        @endif

        @if($message->isReaction())
            {{--
                Reação não é mensagem escrita, e mostrá-la como parágrafo faria
                dela um emoji solto: indistinguível de alguém que mandou só um
                emoji, e sem dizer em que mensagem a pessoa reagiu — que é
                justamente o que decide se aquilo respondeu alguma coisa.
            --}}
            @php $reagida = $message->reactedTo(); @endphp
            <p class="message-reaction">
                <span class="message-reaction-emoji">{{ $message->body }}</span>
                <span class="muted"><x-icon name="reply" size="16" />Reagiu a esta mensagem</span>
            </p>
            @if($reagida)
                <blockquote class="message-reacted">{{ \Illuminate\Support\Str::limit((string) $reagida->body, 200) }}</blockquote>
            @else
                <p class="muted">A mensagem reagida não está nesta conversa &mdash; ela é anterior à sincronização.</p>
            @endif
        @elseif(filled($message->body))
            <p>{{ $message->body }}</p>
        @endif

        {{--
            O que a máquina ouviu ou viu não é o que a pessoa escreveu.

            A transcrição fica marcada, e não vira o corpo da mensagem: numa
            pesquisa, a diferença entre o que foi escrito e o que foi ouvido por
            uma máquina muda o peso do dado.
        --}}
        @php $lida = $message->transcription(); @endphp
        @if($lida?->text)
            <p class="muted">
                <x-icon name="sparkles" size="16" />
                <em>{{ $lida->media_type === 'image' || $lida->media_type === 'sticker' ? 'Descrição automática' : 'Transcrição automática' }}:</em>
                {{ $lida->text }}
            </p>
        @endif

        @if(! $message->has_media && ! $message->isReaction() && blank($message->body))
            <p class="muted">Mensagem sem conteúdo.</p>
        @endif
    @else
        <p class="muted">Conteúdo protegido.</p>
    @endcan
    @if($message->error_code)
        <div class="alert error">{{ $message->error_code }} - {{ $message->error_message }}</div>
    @endif
</article>
