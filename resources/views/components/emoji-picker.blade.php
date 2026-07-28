@props(['target'])
@php
    $emojiGroups = [
        'Sorrisos' => ['😀','😁','😂','🤣','😊','😉','😍','😘','😜','🤔','😐','😶','🙄','😴','😢','😭','😡','😱','🥳','😎','🤗','🙏','😇','🤝'],
        'Gestos' => ['👍','👎','👌','✌️','🤞','👏','🙌','💪','👋','🤙','☝️','👆','👇','👉','👈','✋'],
        'Coracoes' => ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','💕','💖','💗','💯'],
        'Negocios' => ['✅','❌','⭐','🎉','🎁','📦','🚚','💰','💳','📅','⏰','📞','📱','📍','✉️','📝','🔔','🔥','⚡','💡','🛒','🏷️','📈','🤝'],
    ];
@endphp
<div class="emoji-picker" x-data="{ open: false }" x-on:click.outside="open = false">
    <button type="button" class="btn ghost emoji-picker-toggle" x-on:click="open = !open" title="Inserir emoji" aria-label="Inserir emoji">😀</button>
    <div class="emoji-picker-panel" x-show="open" x-cloak x-transition style="display:none;">
        @foreach($emojiGroups as $label => $emojis)
            <div class="emoji-picker-group">
                <span class="emoji-picker-group-label">{{ $label }}</span>
                <div class="emoji-picker-grid">
                    @foreach($emojis as $emoji)
                        <button type="button" class="emoji-picker-item" x-on:click="
                            const el = document.getElementById('{{ $target }}');
                            if (el) {
                                const start = el.selectionStart ?? el.value.length;
                                const end = el.selectionEnd ?? el.value.length;
                                el.focus();
                                el.setRangeText('{{ $emoji }}', start, end, 'end');
                                el.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        ">{{ $emoji }}</button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
