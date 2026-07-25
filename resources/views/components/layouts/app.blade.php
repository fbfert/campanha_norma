<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim(($title ?? '') . ' - ' . ($systemName ?? config('app.name')), ' -') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <h1 style="font-size:20px;margin:0 0 18px;">{{ $systemName ?? config('app.name') }}</h1>
            <nav aria-label="Menu principal">
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
                @can('view-users')
                    <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])>Usuarios</a>
                @endcan
                @can('contacts.view')
                    <a href="{{ route('admin.contacts.index') }}" @class(['active' => request()->routeIs('admin.contacts.index', 'admin.contacts.show', 'admin.contacts.edit')])>Todos os contatos</a>
                @endcan
                @can('contacts.create')
                    <a href="{{ route('admin.contacts.create') }}" @class(['active' => request()->routeIs('admin.contacts.create')])>Novo contato</a>
                @endcan
                @can('contacts.import')
                    <a href="{{ route('admin.contacts.import') }}" @class(['active' => request()->routeIs('admin.contacts.import')])>Importar contatos</a>
                    <a href="{{ route('admin.contacts.imports.index') }}" @class(['active' => request()->routeIs('admin.contacts.imports.*')])>Historico de importacoes</a>
                @endcan
                @can('contacts.manage_tags')
                    <a href="{{ route('admin.tags.index') }}" @class(['active' => request()->routeIs('admin.tags.*')])>Etiquetas</a>
                @endcan
                @can('message_templates.view')
                    <a href="{{ route('admin.message-templates.index') }}" @class(['active' => request()->routeIs('admin.message-templates.*')])>Modelos</a>
                @endcan
                @can('message_batches.create')
                    <a href="{{ route('admin.message-batches.create') }}" @class(['active' => request()->routeIs('admin.message-batches.create')])>Novo lote</a>
                @endcan
                @can('message_batches.view')
                    <a href="{{ route('admin.message-batches.index') }}" @class(['active' => request()->routeIs('admin.message-batches.*') && ! request()->routeIs('admin.message-batches.create')])>Lotes</a>
                @endcan
                @can('message_processing.view')
                    <a href="{{ route('admin.message-processing.index') }}" @class(['active' => request()->routeIs('admin.message-processing.*')])>Processamento</a>
                @endcan
                @can('message_processing.manage_settings')
                    <a href="{{ route('admin.message-settings.edit') }}" @class(['active' => request()->routeIs('admin.message-settings.*')])>Configuracoes de envio</a>
                @endcan
                @can('inbox.view')
                    <a href="{{ route('admin.inbox.index') }}" @class(['active' => request()->routeIs('admin.inbox.*')])>Caixa de entrada</a>
                @endcan
                @can('histories.view')
                    <a href="{{ route('admin.histories.messages.index') }}" @class(['active' => request()->routeIs('admin.histories.*')])>Historico de mensagens</a>
                @endcan
                @can('reports.view')
                    <a href="{{ route('admin.reports.index') }}" @class(['active' => request()->routeIs('admin.reports.*')])>Relatorios</a>
                    <a href="{{ route('admin.reports.conversations') }}" @class(['active' => request()->routeIs('admin.reports.conversations')])>Relatorio de conversas</a>
                @endcan
                @if(auth()->user()->can('monitoring.view') || auth()->user()->can('maintenance.view'))
                    @can('monitoring.view')
                        <a href="{{ route('admin.monitoring.index') }}" @class(['active' => request()->routeIs('admin.monitoring.*')])>Monitoramento</a>
                    @endcan
                    @can('reports.export')
                        <a href="{{ route('admin.report-exports.index') }}" @class(['active' => request()->routeIs('admin.report-exports.*')])>Exportacoes</a>
                    @endcan
                    @can('maintenance.view')
                        <a href="{{ route('admin.maintenance.index') }}" @class(['active' => request()->routeIs('admin.maintenance.*')])>Manutencao</a>
                    @endcan
                @endif
                @can('view-settings')
                    <a href="{{ route('admin.settings.edit') }}" @class(['active' => request()->routeIs('admin.settings.*')])>Configuracoes</a>
                @endcan
                @can('view-audit')
                    <a href="{{ route('admin.audit-logs.index') }}" @class(['active' => request()->routeIs('admin.audit-logs.*')])>Auditoria</a>
                @endcan
                @can('whatsapp.connection.view')
                    <a href="{{ route('admin.whatsapp.connection') }}" @class(['active' => request()->routeIs('admin.whatsapp.*')])>Conexao WhatsApp</a>
                @endcan
                @can('whatsapp.events.view')
                    <a href="{{ route('admin.whatsapp.events') }}" @class(['active' => request()->routeIs('admin.whatsapp.events')])>Eventos WhatsApp</a>
                @endcan
                <span class="disabled-link">Novo envio</span>
                <span class="disabled-link">Status dos envios</span>
                <span class="disabled-link">Historico</span>
            </nav>
        </aside>
        <div class="main">
            <header class="topbar">
                <div>
                    <strong>{{ $title ?? 'Painel' }}</strong>
                    <div class="muted">{{ $breadcrumbs ?? 'Inicio' }}</div>
                </div>
                <div class="actions">
                    <span>{{ auth()->user()->name }}</span>
                    <span class="muted">{{ auth()->user()->roles->pluck('name')->join(', ') }}</span>
                    <a class="btn ghost" href="{{ route('profile.show') }}">Perfil</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn secondary" type="submit">Sair</button>
                    </form>
                </div>
            </header>
            <main class="content">
                @include('components.flash')
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
