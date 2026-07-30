<x-layouts.app title="Tentativas do destinatário" breadcrumbs="Mensagens / Processamento / Tentativas">
    <div class="panel">
        <h2>{{ $recipient->contact_name_snapshot }}</h2>
        <p class="muted">Request ID: {{ Str::mask($recipient->request_id ?? '', '*', 8, -8) }}</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tentativa</th><th>Status</th><th>Início</th><th>Fim</th><th>Erro</th><th>ID externo</th></tr></thead>
                <tbody>
                    @forelse($attempts as $attempt)
                        <tr>
                            <td>{{ $attempt->attempt_number }}</td>
                            <td>{{ $attempt->status->value }}</td>
                            <td>{{ $attempt->started_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td>{{ $attempt->finished_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td>{{ $attempt->error_code ? $attempt->error_code . ' - ' . $attempt->error_message : '-' }}</td>
                            <td>{{ $attempt->external_message_id ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Nenhuma tentativa registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
