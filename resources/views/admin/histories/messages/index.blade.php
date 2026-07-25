<x-layouts.app title="Historico de mensagens" breadcrumbs="Historicos / Mensagens">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label>Busca</label><input name="q" value="{{ request('q') }}"></div>
            <div><label>Lote</label><select name="message_batch_id"><option value="">Todos</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected((string) request('message_batch_id') === (string) $batch->id)>{{ $batch->name }}</option>@endforeach</select></div>
            <div><label>Status</label><input name="status" value="{{ request('status') }}" placeholder="sent, failed_permanent..."></div>
            <div><label>De</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div><label>Ate</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <div class="actions"><button class="btn" type="submit">Filtrar</button><a class="btn ghost" href="{{ route('admin.histories.messages.index') }}">Limpar</a></div>
        </form>
        @can('histories.export')
            <form method="post" action="{{ route('admin.reports.export') }}" style="margin-top:12px;">
                @csrf
                <input type="hidden" name="report_type" value="messages">
                <input type="hidden" name="format" value="csv">
                <button class="btn secondary" type="submit">Exportar CSV</button>
            </form>
        @endcan
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap"><table><thead><tr><th>Data</th><th>Lote</th><th>Posicao</th><th>Contato</th><th>Telefone</th><th>Cidade</th><th>Mensagem</th><th>Status</th><th>Tentativas</th><th>Erro</th><th>Acoes</th></tr></thead><tbody>
        @forelse($recipients as $recipient)
            <tr>
                <td>{{ ($recipient->sent_at ?? $recipient->failed_at ?? $recipient->created_at)?->format($dateTimeFormat) }}</td>
                <td>{{ $recipient->batch?->name }}</td>
                <td>{{ $recipient->random_position }}</td>
                <td>{{ $recipient->contact_name_snapshot }}</td>
                <td>{{ auth()->user()->can('histories.view_technical_details') ? $recipient->contact_phone_snapshot : Str::mask($recipient->contact_phone_snapshot ?? '', '*', 5, -4) }}</td>
                <td>{{ $recipient->contact_city_snapshot }}</td>
                <td>@can('histories.view_message_content')<span>{{ Str::limit($recipient->rendered_message, 80) }}</span>@else<span class="muted">Conteudo protegido</span>@endcan</td>
                <td>{{ $recipient->processing_status?->label() }}</td>
                <td>{{ $recipient->attempts }}</td>
                <td>{{ $recipient->error_code ?? '-' }}</td>
                <td><a class="btn ghost" href="{{ route('admin.histories.messages.show', $recipient) }}">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="11">Nenhum historico encontrado.</td></tr>
        @endforelse
        </tbody></table></div>
        {{ $recipients->links() }}
    </section>
</x-layouts.app>
