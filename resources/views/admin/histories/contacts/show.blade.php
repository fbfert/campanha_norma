<x-layouts.app title="Historico do contato" breadcrumbs="Contatos / Historico de mensagens">
    <section class="card">
        <h2>{{ $contact->name }}</h2>
        <div class="stats-grid">
            <div class="stat"><span>Total</span><strong>{{ $summary['total'] }}</strong></div>
            <div class="stat"><span>Enviadas</span><strong>{{ $summary['sent'] }}</strong></div>
            <div class="stat"><span>Falhas</span><strong>{{ $summary['failed'] }}</strong></div>
            <div class="stat"><span>Canceladas</span><strong>{{ $summary['cancelled'] }}</strong></div>
        </div>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap"><table><thead><tr><th>Data</th><th>Lote</th><th>Contato usado</th><th>Mensagem</th><th>Status</th><th>Tentativas</th><th>Erro</th></tr></thead><tbody>@foreach($recipients as $recipient)<tr><td>{{ $recipient->created_at?->format($dateTimeFormat) }}</td><td>{{ $recipient->batch?->name }}</td><td>{{ $recipient->contact_name_snapshot }}</td><td>@can('histories.view_message_content'){{ Str::limit($recipient->rendered_message, 80) }}@else Conteudo protegido @endcan</td><td>{{ $recipient->processing_status?->label() }}</td><td>{{ $recipient->attempts }}</td><td>{{ $recipient->error_code ?? '-' }}</td></tr>@endforeach</tbody></table></div>
        {{ $recipients->links() }}
    </section>
</x-layouts.app>
