<section class="card" style="margin-top:16px;">
    <h2>Histórico de alterações</h2>
    @forelse($history as $event)
        <p><strong>{{ $event->created_at->format($dateTimeFormat) }}</strong> - {{ $event->action }} - {{ $event->user?->name ?? 'Sistema' }}<br><span class="muted">{{ $event->description }}</span></p>
    @empty
        <p class="muted">Nenhum histórico registrado.</p>
    @endforelse
</section>
