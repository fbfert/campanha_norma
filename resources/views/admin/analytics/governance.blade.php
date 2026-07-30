<x-layouts.app title="Governanca" breadcrumbs="Inicio / Relatorios / Governanca">
    @include('admin.analytics.partials.filters')

    @if($report['divergences'] !== [])
        <section class="card">
            <h2>Divergencias de configuracao</h2>
            <p class="muted">Estados que parecem ligados e nao produzem efeito. Cada um destes gera silencio, nao erro: ninguem reclama e nada acontece.</p>
            <ul>@foreach($report['divergences'] as $divergence)<li>{{ $divergence }}</li>@endforeach</ul>
        </section>
    @endif

    <section class="card">
        <h2>Interruptores</h2>
        <table><tbody>
            @foreach(['automation' => 'Automacao conversacional', 'auto_send' => 'Envio automatico', 'interpretation' => 'Interpretacao por IA', 'generation' => 'Geracao de respostas', 'knowledge' => 'Base de conhecimento'] as $key => $label)
                <tr><th>{{ $label }}</th><td>{{ $report['switches'][$key] ? 'ligado' : 'desligado' }}</td></tr>
            @endforeach
        </tbody></table>
    </section>

    <section class="card">
        <h2>Fluxos</h2>
        @if($report['flows'] === [])
            @include('admin.analytics.partials.empty', ['message' => 'Nenhum fluxo cadastrado.'])
        @else
            <table>
                <thead><tr><th>Fluxo</th><th>Situacao</th><th>Perguntas ativas</th><th>Aprofundamentos</th><th>Aviso de automacao</th></tr></thead>
                <tbody>@foreach($report['flows'] as $flow)
                    <tr><td>{{ $flow['name'] }}</td><td>{{ $flow['status'] }}</td><td>{{ $flow['active_questions'] }}</td>
                        <td>{{ $flow['max_followups'] }}</td><td>{{ $flow['transparency_enabled'] ? 'sim' : 'nao' }}</td></tr>
                @endforeach</tbody>
            </table>
        @endif
    </section>

    <section class="card">
        <h2>Base oficial</h2>
        <table><tbody>
            <tr><th>Documentos aprovados</th><td>{{ $report['documents']['approved'] }}</td></tr>
            <tr><th>Obsoletos</th><td>{{ $report['documents']['obsolete'] }}</td></tr>
            <tr><th>Com falha</th><td>{{ $report['documents']['failed'] }}</td></tr>
            <tr><th>Total</th><td>{{ $report['documents']['total'] }}</td></tr>
        </tbody></table>
    </section>

    <section class="card">
        <h2>Versoes em uso</h2>
        <table><tbody>@foreach($report['versions'] as $label => $value)
            <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach</tbody></table>
    </section>

    <section class="card">
        <h2>Limiares</h2>
        <table><tbody>@foreach($report['thresholds'] as $label => $value)
            <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach</tbody></table>
    </section>

    <section class="card">
        <h2>Eventos sensiveis no periodo</h2>
        <table><tbody>
            <tr><th>Opt-outs</th><td>{{ $report['sensitive']['opt_outs'] }}</td></tr>
            <tr><th>Contatos marcados como nao contatar</th><td>{{ $report['sensitive']['do_not_contact'] }}</td></tr>
            <tr><th>Encaminhamentos a humano</th><td>{{ $report['sensitive']['handoffs'] }}</td></tr>
            <tr><th>Sugestoes bloqueadas</th><td>{{ $report['sensitive']['blocked_suggestions'] }}</td></tr>
        </tbody></table>
    </section>

    <section class="card">
        <h2>Pendencias e falhas</h2>
        <table><tbody>
            <tr><th>Sugestoes aguardando decisao</th><td>{{ $report['pending']['suggestions_pending'] }}</td></tr>
            <tr><th>Insights aguardando revisao</th><td>{{ $report['pending']['insights_unreviewed'] }}</td></tr>
            <tr><th>Documentos em processamento</th><td>{{ $report['pending']['documents_processing'] }}</td></tr>
            <tr><th>Conversas aguardando humano</th><td>{{ $report['pending']['states_waiting_human'] }}</td></tr>
            <tr><th>Execucoes de IA com falha</th><td>{{ $report['failures']['ai_runs_failed'] }}</td></tr>
            <tr><th>Jobs na fila de falhas</th><td>{{ $report['failures']['jobs_failed'] }}</td></tr>
            <tr><th>Documentos com falha</th><td>{{ $report['failures']['documents_failed'] }}</td></tr>
        </tbody></table>
    </section>

    <section class="card">
        <h2>Ultimas alteracoes de configuracao</h2>
        @if($report['changes'] === [])
            @include('admin.analytics.partials.empty', ['message' => 'Nenhuma alteracao registrada.'])
        @else
            <table>
                <thead><tr><th>Acao</th><th>Descricao</th><th>Quando</th></tr></thead>
                <tbody>@foreach($report['changes'] as $change)
                    <tr><td>{{ $change['action'] }}</td><td>{{ $change['description'] }}</td>
                        <td>{{ $change['created_at']?->format('d/m/Y H:i') }}</td></tr>
                @endforeach</tbody>
            </table>
        @endif
    </section>
</x-layouts.app>
