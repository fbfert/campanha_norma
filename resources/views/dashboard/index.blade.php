<x-layouts.app title="Dashboard" breadcrumbs="Inicio / Dashboard">
    <div class="grid grid-3">
        <section class="card"><strong>Usuários ativos</strong><h2>{{ $activeUsers }}</h2></section>
        <section class="card"><strong>Usuários bloqueados</strong><h2>{{ $blockedUsers }}</h2></section>
        <section class="card"><strong>Administradores</strong><h2>{{ $administratorCount }}</h2></section>
        <section class="card"><strong>Último acesso</strong><p>{{ $currentUser->last_login_at?->format($dateTimeFormat) ?? 'Sem registro anterior' }}</p></section>
        <section class="card"><strong>Situação geral</strong><p>Fundação administrativa ativa</p></section>
        <section class="card"><strong>Ambiente</strong><p>{{ $environment }}</p></section>
        <section class="card"><strong>Laravel</strong><p>{{ $laravelVersion }}</p></section>
        <section class="card"><strong>PHP</strong><p>{{ $phpVersion }}</p></section>
        <section class="card"><strong>Data do sistema</strong><p>@livewire(\App\Http\Livewire\SystemClock::class)</p></section>
    </div>
    @if($ai['disponivel'] ?? false)
        @php
            $moeda = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
            $milhar = fn (int $valor): string => number_format($valor, 0, ',', '.');
        @endphp
        <h2>Inteligência artificial &mdash; mês atual</h2>
        <div class="grid grid-3">
            <section class="card">
                <strong>Gasto no mês</strong>
                <h2>{{ $moeda($ai['gasto_entrada'] + $ai['gasto_saida']) }}</h2>
                <p class="muted">Entrada e saída somadas, pelo preço configurado agora.</p>
            </section>
            <section class="card">
                <strong>Gasto com entrada</strong>
                <h2>{{ $moeda($ai['gasto_entrada']) }}</h2>
                <p class="muted">{{ $milhar($ai['tokens_entrada']) }} tokens a {{ $moeda($ai['preco_entrada']) }} por mil.</p>
            </section>
            <section class="card">
                <strong>Gasto com saída</strong>
                <h2>{{ $moeda($ai['gasto_saida']) }}</h2>
                <p class="muted">{{ $milhar($ai['tokens_saida']) }} tokens a {{ $moeda($ai['preco_saida']) }} por mil.</p>
            </section>
            <section class="card">
                <strong>Chamadas no mês</strong>
                <h2>{{ $milhar($ai['chamadas_mes']) }}</h2>
                <p class="muted">{{ $milhar($ai['chamadas_hoje']) }} hoje.</p>
            </section>
            <section class="card">
                <strong>Chamadas com falha</strong>
                <h2>{{ $milhar($ai['falhas_mes']) }}</h2>
                <p class="muted">Falha não gera texto, mas pode ter consumido tokens.</p>
            </section>
            <section class="card">
                <strong>Modelo em uso</strong>
                <p>{{ $ai['modelo'] }}</p>
                {{-- O gravado usa o preço vigente em cada chamada; o recalculado
                     responde "quanto custaria hoje". Divergiram quando o preço
                     mudou, e esconder isso faria o card parecer errado. --}}
                @if(abs(($ai['gasto_entrada'] + $ai['gasto_saida']) - $ai['gasto_registrado']) > 0.01)
                    <p class="muted">Registrado na época das chamadas: {{ $moeda($ai['gasto_registrado']) }}.</p>
                @endif
            </section>
        </div>
    @endif

    <h2>Contatos</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Total de contatos</strong><h2>{{ $totalContacts }}</h2></section>
        <section class="card"><strong>Contatos ativos</strong><h2>{{ $activeContacts }}</h2></section>
        <section class="card"><strong>Contatos bloqueados</strong><h2>{{ $blockedContacts }}</h2></section>
        <section class="card"><strong>Não contatar</strong><h2>{{ $doNotContactContacts }}</h2></section>
        <section class="card"><strong>Cadastrados hoje</strong><h2>{{ $contactsToday }}</h2></section>
        <section class="card"><strong>Importados no mês</strong><h2>{{ $contactsImportedMonth }}</h2></section>
        <section class="card"><strong>Sem cidade</strong><h2>{{ $contactsWithoutCity }}</h2></section>
        <section class="card"><strong>Sem e-mail</strong><h2>{{ $contactsWithoutEmail }}</h2></section>
    </div>
    <h2>Módulos futuros</h2>
    <div class="grid grid-3">
        <section class="card">
            <strong>Status do WhatsApp</strong>
            @if($whatsappConnection)
                <h2>{{ $whatsappConnection->status->label() }}</h2>
                <p>{{ $whatsappConnection->phone_number ?? 'Número não conectado' }}</p>
                <p class="muted">Última atividade: {{ $whatsappConnection->last_activity_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
                @can('whatsapp.connection.view')
                    <a class="btn ghost" href="{{ route('admin.whatsapp.connection') }}">Abrir conexão</a>
                @endcan
            @else
                <p class="muted">Não inicializado</p>
                @can('whatsapp.connection.view')
                    <a class="btn ghost" href="{{ route('admin.whatsapp.connection') }}">Abrir conexão</a>
                @endcan
            @endif
        </section>
    </div>
    <h2>Mensagens e lotes</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Modelos ativos</strong><h2>{{ $activeTemplates }}</h2></section>
        <section class="card"><strong>Modelos inativos</strong><h2>{{ $inactiveTemplates }}</h2></section>
        <section class="card"><strong>Lotes em rascunho</strong><h2>{{ $draftBatches }}</h2></section>
        <section class="card"><strong>Lotes preparados</strong><h2>{{ $readyBatches }}</h2></section>
        <section class="card"><strong>Lotes cancelados</strong><h2>{{ $cancelledBatches }}</h2></section>
        <section class="card"><strong>Aptos no último lote</strong><h2>{{ $latestBatchEligible }}</h2></section>
        <section class="card"><strong>Excluídos no último lote</strong><h2>{{ $latestBatchExcluded }}</h2></section>
    </div>
    <h2>Processamento</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Lotes em processamento</strong><h2>{{ $processingBatches }}</h2></section>
        <section class="card"><strong>Lotes pausados</strong><h2>{{ $pausedBatches }}</h2></section>
        <section class="card"><strong>Mensagens pendentes</strong><h2>{{ $pendingMessages }}</h2></section>
        <section class="card"><strong>Mensagens enviadas hoje</strong><h2>{{ $messagesSentToday }}</h2></section>
        <section class="card"><strong>Mensagens enviadas no mês</strong><h2>{{ $messagesSentMonth }}</h2></section>
        <section class="card"><strong>Falhas hoje</strong><h2>{{ $sendFailuresToday }}</h2></section>
        <section class="card"><strong>Falhas no mês</strong><h2>{{ $sendFailuresMonth }}</h2></section>
        <section class="card"><strong>Lotes concluidos hoje</strong><h2>{{ $completedBatchesToday }}</h2></section>
        <section class="card"><strong>Tentativas em repetição</strong><h2>{{ $retryingMessages }}</h2></section>
        <section class="card"><strong>Resultados incertos</strong><h2>{{ $uncertainResults }}</h2></section>
        <section class="card"><strong>Uso do limite diário</strong><p>{{ $dailyLimitUsage }}</p></section>
        <section class="card"><strong>Próximo envio</strong><p>{{ $nextSendAt ? \Illuminate\Support\Carbon::parse($nextSendAt)->format($dateTimeFormat) : '-' }}</p></section>
        <section class="card"><strong>Última atividade</strong><p>{{ $latestProcessingActivity?->updated_at?->format($dateTimeFormat) ?? '-' }}</p></section>
        <section class="card"><strong>Status dos workers</strong><p>{{ $workerStatus?->label() ?? 'Sem permissão ou sem registro' }}</p></section>
        <section class="card"><strong>Status do Redis</strong><p>{{ $redisStatus?->label() ?? 'Sem permissão ou sem registro' }}</p></section>
        <section class="card"><strong>Status do Scheduler</strong><p>{{ $schedulerStatus?->label() ?? 'Sem permissão ou sem registro' }}</p></section>
    </div>
    <h2>Atendimento</h2>
    <div class="grid grid-3">
        @can('inbound_attendance.view')
            {{-- O mesmo número do topo, clicável, ao lado das outras filas de
                 atendimento. Um cartão que só mostra zero não pede nada, e é
                 exatamente isso que se quer ver de manhã. --}}
            <section class="card">
                <strong>Aguardando resposta</strong>
                <h2><a href="{{ route('admin.inbound-attendance.index') }}">{{ $inboundPendingCount ?? 0 }}</a></h2>
            </section>
        @endcan
        <section class="card"><strong>Novas mensagens</strong><h2>{{ $inboxMetrics['new'] ?? 0 }}</h2></section>
        <section class="card"><strong>Aguardando operador</strong><h2>{{ $inboxMetrics['waiting_operator'] ?? 0 }}</h2></section>
        <section class="card"><strong>Sem responsável</strong><h2>{{ $inboxMetrics['unassigned'] ?? 0 }}</h2></section>
        <section class="card"><strong>Mensagens recebidas hoje</strong><h2>{{ $inboxMetrics['received_today'] ?? 0 }}</h2></section>
        <section class="card"><strong>Respostas manuais hoje</strong><h2>{{ $inboxMetrics['manual_sent_today'] ?? 0 }}</h2></section>
        <section class="card"><strong>Falhas de resposta manual</strong><h2>{{ $inboxMetrics['manual_reply_failures'] ?? 0 }}</h2></section>
    </div>
</x-layouts.app>
