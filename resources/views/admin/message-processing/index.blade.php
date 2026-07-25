<x-layouts.app title="Processamento" breadcrumbs="Mensagens / Processamento">
    <div class="panel">
        <div class="panel-header">
            <h2>Lotes em processamento</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Lote</th>
                        <th>Status</th>
                        <th>Pendentes</th>
                        <th>Enviados</th>
                        <th>Falhas</th>
                        <th>Cancelados</th>
                        <th>Proximo envio</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>{{ $batch->name }}</td>
                            <td><span class="badge">{{ $batch->status->label() }}</span></td>
                            <td>{{ $batch->total_pending }}</td>
                            <td>{{ $batch->total_sent }}</td>
                            <td>{{ $batch->total_failed }}</td>
                            <td>{{ $batch->total_cancelled }}</td>
                            <td>{{ $batch->next_dispatch_at?->format($dateTimeFormat) ?? '-' }}</td>
                            <td><a class="btn ghost" href="{{ route('admin.message-batches.processing', $batch) }}">Acompanhar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhum lote em processamento.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $batches->links() }}
    </div>
</x-layouts.app>
