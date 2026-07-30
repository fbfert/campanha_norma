<x-layouts.app title="Relatórios" breadcrumbs="Relatorios / Visao geral">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label>De</label><input type="date" name="from" value="{{ request('from', $from->toDateString()) }}"></div>
            <div><label>Até</label><input type="date" name="to" value="{{ request('to', $to->toDateString()) }}"></div>
            <div class="actions"><button class="btn" type="submit">Atualizar</button></div>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Indicadores</h2>
        <div class="stats-grid">
            <div class="stat"><span>Preparadas</span><strong>{{ $metrics['messages']['prepared'] }}</strong></div>
            <div class="stat"><span>Processadas</span><strong>{{ $metrics['messages']['processed'] }}</strong></div>
            <div class="stat"><span>Enviadas</span><strong>{{ $metrics['messages']['sent'] }}</strong></div>
            <div class="stat"><span>Falhas</span><strong>{{ $metrics['messages']['failed'] }}</strong></div>
            <div class="stat"><span>Taxa de sucesso</span><strong>{{ $metrics['messages']['success_rate'] === null ? '—' : $metrics['messages']['success_rate'].'%' }}</strong></div>
            <div class="stat"><span>Tentativas</span><strong>{{ $metrics['messages']['attempts'] }}</strong></div>
        </div>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Atalhos</h2>
        <div class="actions">
            <a class="btn ghost" href="{{ route('admin.reports.batches') }}">Lotes</a>
            <a class="btn ghost" href="{{ route('admin.reports.messages') }}">Mensagens</a>
            <a class="btn ghost" href="{{ route('admin.reports.errors') }}">Erros</a>
            <a class="btn ghost" href="{{ route('admin.reports.not-sent') }}">Não enviados</a>
            <a class="btn ghost" href="{{ route('admin.reports.attempts') }}">Tentativas</a>
            <a class="btn ghost" href="{{ route('admin.reports.rate-limits') }}">Limites</a>
            <a class="btn ghost" href="{{ route('admin.reports.contacts') }}">Contatos</a>
            <a class="btn ghost" href="{{ route('admin.reports.templates') }}">Modelos</a>
        </div>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Gráficos simples</h2>
        <p class="muted">Valores textuais por dia/status para evitar gráficos decorativos e carregamento excessivo.</p>
        <div class="table-wrap"><table><thead><tr><th>Dia</th><th>Status</th><th>Total</th></tr></thead><tbody>@foreach($metrics['charts']['messages_by_day'] as $row)<tr><td>{{ $row->day }}</td><td>{{ $row->processing_status }}</td><td>{{ $row->total }}</td></tr>@endforeach</tbody></table></div>
    </section>
</x-layouts.app>
