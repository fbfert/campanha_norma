<x-layouts.app title="Monitoramento de IA" breadcrumbs="Inicio / Pesquisa conversacional / Monitoramento de IA">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div>
                <label for="days">Período (dias)</label>
                <input id="days" name="days" type="number" min="1" max="90" value="{{ $days }}">
            </div>
            <div class="actions"><button class="btn" type="submit">Atualizar</button></div>
        </form>
        <p class="muted" style="margin-top:8px;">
            Provedor: <strong>{{ $provider }}</strong> &middot; modelo: <strong>{{ $model }}</strong> &middot;
            disjuntor: <strong>{{ $circuitOpen ? 'aberto' : 'fechado' }}</strong> ({{ $circuitFailures }} falhas consecutivas)
        </p>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Execuções desde {{ $metrics['since']->format($dateTimeFormat) }}</h2>
        <div class="grid grid-3">
            <p><strong>Total:</strong> {{ $metrics['total'] }}</p>
            <p><strong>Concluidas:</strong> {{ $metrics['succeeded'] }}</p>
            <p><strong>Falhas:</strong> {{ $metrics['failed'] }}</p>
            <p><strong>Saída invalida:</strong> {{ $metrics['invalid_output'] }}</p>
            <p><strong>Latência média:</strong> {{ $metrics['average_latency_ms'] }} ms</p>
            <p><strong>Latência máxima:</strong> {{ $metrics['max_latency_ms'] }} ms</p>
            <p><strong>Tokens:</strong> {{ number_format($metrics['total_tokens'], 0, ',', '.') }}</p>
            <p><strong>Custo estimado:</strong> {{ $metrics['estimated_cost'] > 0 ? number_format($metrics['estimated_cost'], 4) : 'não configurado' }}</p>
            <p><strong>Baixa confiança:</strong> {{ $metrics['low_confidence'] }}</p>
            <p><strong>Insights aguardando revisão:</strong> {{ $metrics['awaiting_review'] }}</p>
            <p><strong>Classificações aguardando revisão:</strong> {{ $metrics['classifications_awaiting_review'] }}</p>
            <p><strong>Execuções presas:</strong> {{ $metrics['stuck'] }}</p>
        </div>
    </section>

    <div class="grid grid-2" style="margin-top:16px;">
        <section class="card">
            <h2>Falhas por código</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Código</th><th>Total</th></tr></thead>
                    <tbody>
                        @forelse($metrics['errors_by_code'] as $code => $total)
                            <tr><td>{{ $code }}</td><td>{{ $total }}</td></tr>
                        @empty
                            <tr><td colspan="2">Nenhuma falha no período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Falhas por provedor</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Provedor</th><th>Total</th></tr></thead>
                    <tbody>
                        @forelse($metrics['failures_by_provider'] as $name => $total)
                            <tr><td>{{ $name }}</td><td>{{ $total }}</td></tr>
                        @empty
                            <tr><td colspan="2">Nenhuma falha no período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2>Execuções presas</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Finalidade</th><th>Provedor</th><th>Tentativa</th><th>Início</th></tr></thead>
                <tbody>
                    @forelse($stuck as $run)
                        <tr>
                            <td>{{ $run->id }}</td>
                            <td>{{ $run->purpose->label() }}</td>
                            <td>{{ $run->provider }}</td>
                            <td>{{ $run->attempt }}</td>
                            <td>{{ $run->started_at?->format($dateTimeFormat) ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Nenhuma execução presa.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Execuções recentes</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Finalidade</th><th>Status</th><th>Modelo</th><th>Latência</th><th>Tokens</th><th>Erro</th><th>Data</th></tr></thead>
                <tbody>
                    @forelse($recent as $run)
                        <tr>
                            <td>{{ $run->id }}</td>
                            <td>{{ $run->purpose->label() }}</td>
                            <td>{{ $run->status->label() }}</td>
                            <td>{{ $run->model }}</td>
                            <td>{{ $run->latency_ms !== null ? $run->latency_ms.' ms' : '-' }}</td>
                            <td>{{ $run->total_tokens ?? '-' }}</td>
                            <td>{{ $run->error_code ?? '-' }}</td>
                            <td>{{ $run->created_at?->format($dateTimeFormat) ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhuma execução registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
