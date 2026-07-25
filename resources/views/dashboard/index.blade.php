<x-layouts.app title="Dashboard" breadcrumbs="Inicio / Dashboard">
    <div class="grid grid-3">
        <section class="card"><strong>Usuarios ativos</strong><h2>{{ $activeUsers }}</h2></section>
        <section class="card"><strong>Usuarios bloqueados</strong><h2>{{ $blockedUsers }}</h2></section>
        <section class="card"><strong>Administradores</strong><h2>{{ $administratorCount }}</h2></section>
        <section class="card"><strong>Ultimo acesso</strong><p>{{ $currentUser->last_login_at?->format($dateTimeFormat) ?? 'Sem registro anterior' }}</p></section>
        <section class="card"><strong>Situacao geral</strong><p>Fundacao administrativa ativa</p></section>
        <section class="card"><strong>Ambiente</strong><p>{{ $environment }}</p></section>
        <section class="card"><strong>Laravel</strong><p>{{ $laravelVersion }}</p></section>
        <section class="card"><strong>PHP</strong><p>{{ $phpVersion }}</p></section>
        <section class="card"><strong>Data do sistema</strong><p>@livewire(\App\Http\Livewire\SystemClock::class)</p></section>
    </div>
    <h2>Contatos</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Total de contatos</strong><h2>{{ $totalContacts }}</h2></section>
        <section class="card"><strong>Contatos ativos</strong><h2>{{ $activeContacts }}</h2></section>
        <section class="card"><strong>Contatos bloqueados</strong><h2>{{ $blockedContacts }}</h2></section>
        <section class="card"><strong>Nao contatar</strong><h2>{{ $doNotContactContacts }}</h2></section>
        <section class="card"><strong>Cadastrados hoje</strong><h2>{{ $contactsToday }}</h2></section>
        <section class="card"><strong>Importados no mes</strong><h2>{{ $contactsImportedMonth }}</h2></section>
        <section class="card"><strong>Sem cidade</strong><h2>{{ $contactsWithoutCity }}</h2></section>
        <section class="card"><strong>Sem e-mail</strong><h2>{{ $contactsWithoutEmail }}</h2></section>
    </div>
    <h2>Modulos futuros</h2>
    <div class="grid grid-3">
        <section class="card">
            <strong>Status do WhatsApp</strong>
            @if($whatsappConnection)
                <h2>{{ $whatsappConnection->status->label() }}</h2>
                <p>{{ $whatsappConnection->phone_number ?? 'Numero nao conectado' }}</p>
                <p class="muted">Ultima atividade: {{ $whatsappConnection->last_activity_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
                @can('whatsapp.connection.view')
                    <a class="btn ghost" href="{{ route('admin.whatsapp.connection') }}">Abrir conexao</a>
                @endcan
            @else
                <p class="muted">Nao inicializado</p>
                @can('whatsapp.connection.view')
                    <a class="btn ghost" href="{{ route('admin.whatsapp.connection') }}">Abrir conexao</a>
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
        <section class="card"><strong>Aptos no ultimo lote</strong><h2>{{ $latestBatchEligible }}</h2></section>
        <section class="card"><strong>Excluidos no ultimo lote</strong><h2>{{ $latestBatchExcluded }}</h2></section>
    </div>
    <h2>Processamento</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Lotes em processamento</strong><h2>{{ $processingBatches }}</h2></section>
        <section class="card"><strong>Lotes pausados</strong><h2>{{ $pausedBatches }}</h2></section>
        <section class="card"><strong>Mensagens pendentes</strong><h2>{{ $pendingMessages }}</h2></section>
        <section class="card"><strong>Mensagens enviadas hoje</strong><h2>{{ $messagesSentToday }}</h2></section>
        <section class="card"><strong>Mensagens enviadas no mes</strong><h2>{{ $messagesSentMonth }}</h2></section>
        <section class="card"><strong>Falhas hoje</strong><h2>{{ $sendFailuresToday }}</h2></section>
        <section class="card"><strong>Falhas no mes</strong><h2>{{ $sendFailuresMonth }}</h2></section>
        <section class="card"><strong>Lotes concluidos hoje</strong><h2>{{ $completedBatchesToday }}</h2></section>
        <section class="card"><strong>Tentativas em repeticao</strong><h2>{{ $retryingMessages }}</h2></section>
        <section class="card"><strong>Resultados incertos</strong><h2>{{ $uncertainResults }}</h2></section>
        <section class="card"><strong>Uso do limite diario</strong><p>{{ $dailyLimitUsage }}</p></section>
        <section class="card"><strong>Proximo envio</strong><p>{{ $nextSendAt ? \Illuminate\Support\Carbon::parse($nextSendAt)->format($dateTimeFormat) : '-' }}</p></section>
        <section class="card"><strong>Ultima atividade</strong><p>{{ $latestProcessingActivity?->updated_at?->format($dateTimeFormat) ?? '-' }}</p></section>
        <section class="card"><strong>Status dos workers</strong><p>{{ $workerStatus?->label() ?? 'Sem permissao ou sem registro' }}</p></section>
        <section class="card"><strong>Status do Redis</strong><p>{{ $redisStatus?->label() ?? 'Sem permissao ou sem registro' }}</p></section>
        <section class="card"><strong>Status do Scheduler</strong><p>{{ $schedulerStatus?->label() ?? 'Sem permissao ou sem registro' }}</p></section>
    </div>
    <h2>Atendimento</h2>
    <div class="grid grid-3">
        <section class="card"><strong>Novas mensagens</strong><h2>{{ $inboxMetrics['new'] ?? 0 }}</h2></section>
        <section class="card"><strong>Aguardando operador</strong><h2>{{ $inboxMetrics['waiting_operator'] ?? 0 }}</h2></section>
        <section class="card"><strong>Sem responsavel</strong><h2>{{ $inboxMetrics['unassigned'] ?? 0 }}</h2></section>
        <section class="card"><strong>Mensagens recebidas hoje</strong><h2>{{ $inboxMetrics['received_today'] ?? 0 }}</h2></section>
        <section class="card"><strong>Respostas manuais hoje</strong><h2>{{ $inboxMetrics['manual_sent_today'] ?? 0 }}</h2></section>
        <section class="card"><strong>Falhas de resposta manual</strong><h2>{{ $inboxMetrics['manual_reply_failures'] ?? 0 }}</h2></section>
    </div>
</x-layouts.app>
