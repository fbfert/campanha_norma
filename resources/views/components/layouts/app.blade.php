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
                    <a href="{{ route('admin.campaigns.create') }}" @class(['active' => request()->routeIs('admin.campaigns.create')])>Campanha</a>
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
                    <a href="{{ route('admin.conversations.index') }}" @class(['active' => request()->routeIs('admin.inbox.*', 'admin.conversations.*')])>
                        <span class="nav-label"><span class="nav-icon chat-icon" aria-hidden="true"></span>CONVERSAS</span>
                        @if(($unreadConversationsCount ?? 0) > 0)<span class="nav-badge">{{ $unreadConversationsCount }}</span>@endif
                    </a>
                @endcan
                @can('conversation_automation.view')
                    <a href="{{ route('admin.conversation-automation.index') }}" @class(['active' => request()->routeIs('admin.conversation-automation.*')])>Pesquisa conversacional</a>
                    <a href="{{ route('admin.conversation-flows.index') }}" @class(['active' => request()->routeIs('admin.conversation-flows.*')])>Fluxos conversacionais</a>
                @endcan
                @can('ai_insights.view')
                    <a href="{{ route('admin.ai-insights.index') }}" @class(['active' => request()->routeIs('admin.ai-insights.*')])>Interpretacao por IA</a>
                    <a href="{{ route('admin.insight-topics.index') }}" @class(['active' => request()->routeIs('admin.insight-topics.*')])>Temas de insights</a>
                @endcan
                @can('reply_suggestions.view')
                    <a href="{{ route('admin.reply-suggestions.index') }}" @class(['active' => request()->routeIs('admin.reply-suggestions.*')])>Sugestoes de resposta</a>
                @endcan
                @can('knowledge.view')
                    <a href="{{ route('admin.knowledge.bases.index') }}" @class(['active' => request()->routeIs('admin.knowledge.bases.*') || request()->routeIs('admin.knowledge.documents.*')])>Base de conhecimento</a>
                @endcan
                @can('knowledge.test_retrieval')
                    <a href="{{ route('admin.knowledge.test') }}" @class(['active' => request()->routeIs('admin.knowledge.test')])>Teste de busca na base</a>
                @endcan
                @can('ai_insights.view_monitoring')
                    <a href="{{ route('admin.ai-monitoring.index') }}" @class(['active' => request()->routeIs('admin.ai-monitoring.*')])>Monitoramento de IA</a>
                @endcan
                @can('ai.provider.manage')
                    <a href="{{ route('admin.ai-provider.edit') }}" @class(['active' => request()->routeIs('admin.ai-provider.*')])>Provedor de IA</a>
                @endcan
                @can('analytics.view_aggregates')
                    <a href="{{ route('admin.analytics.dashboard') }}" @class(['active' => request()->routeIs('admin.analytics.dashboard')])>Painel da pesquisa</a>
                    <a href="{{ route('admin.analytics.topics') }}" @class(['active' => request()->routeIs('admin.analytics.topics')])>Temas</a>
                    <a href="{{ route('admin.analytics.geography') }}" @class(['active' => request()->routeIs('admin.analytics.geography')])>Geografia</a>
                    <a href="{{ route('admin.analytics.demands') }}" @class(['active' => request()->routeIs('admin.analytics.demands')])>Demandas</a>
                    <a href="{{ route('admin.analytics.ai-quality') }}" @class(['active' => request()->routeIs('admin.analytics.ai-quality')])>Qualidade da IA</a>
                    <a href="{{ route('admin.analytics.questions') }}" @class(['active' => request()->routeIs('admin.analytics.questions')])>Qualidade das perguntas</a>
                @endcan
                @can('analytics.view_governance')
                    <a href="{{ route('admin.analytics.governance') }}" @class(['active' => request()->routeIs('admin.analytics.governance')])>Governanca</a>
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
                    @php
                        // Mapa: string completa da trilha (como hoje passada em `breadcrumbs="A / B / C"`)
                        // => nome da rota de cada segmento, na mesma ordem/posicao. `null` = sem link
                        // (o ultimo segmento, a pagina atual, nunca deve ter link).
                        $breadcrumbRouteMap = [
                            'Inicio / Perfil' => ['dashboard', null],
                            'Inicio / Dashboard' => ['dashboard', null],
                            'Relatorios / Visao geral' => ['admin.reports.index', null],
                            'Relatorios / Erros' => ['admin.reports.index', null],
                            'Relatorios / Nao enviados' => ['admin.reports.index', null],
                            'Relatorios / Limites' => ['admin.reports.index', null],
                            'Relatorios / Lotes' => ['admin.reports.index', null],
                            'Relatorios / Modelos' => ['admin.reports.index', null],
                            'Relatorios / Conversas' => ['admin.reports.index', null],
                            'Relatorios / Tentativas' => ['admin.reports.index', null],
                            'Relatorios / Contatos' => ['admin.reports.index', null],
                            'Relatorios / Mensagens' => ['admin.reports.index', null],
                            'Inicio / Contatos / Etiquetas' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / Contatos / Etiquetas / Editar' => ['dashboard', 'admin.contacts.index', 'admin.tags.index', null],
                            'Inicio / Contatos / Importar' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / Contatos / Etiquetas / Nova' => ['dashboard', 'admin.contacts.index', 'admin.tags.index', null],
                            'Inicio / Contatos' => ['dashboard', null],
                            'Inicio / Contatos / Detalhes' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / Contatos / Novo' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / Contatos / Editar' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / Contatos / Importações' => ['dashboard', 'admin.contacts.index', null],
                            'Inicio / WhatsApp / Eventos' => ['dashboard', null, null],
                            'Inicio / Contatos / Importações / Detalhes' => ['dashboard', 'admin.contacts.index', 'admin.contacts.imports.index', null],
                            'Operacao / Monitoramento' => [null, null],
                            'Inicio / Configuracoes' => ['dashboard', null],
                            'Atendimento / Conversas / Conversa' => [null, 'admin.conversations.index', null],
                            'Inicio / WhatsApp / Conexao' => ['dashboard', null, null],
                            'Operacao / Monitoramento / Jobs falhos' => [null, 'admin.monitoring.index', null],
                            'Mensagens / Processamento' => [null, null],
                            'Atendimento / Conversas' => [null, null],
                            'Mensagens / Processamento / Tentativas' => [null, 'admin.message-processing.index', null],
                            'Inicio / Pesquisa conversacional / Automacao' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Automacao / Detalhes' => ['dashboard', null, 'admin.conversation-automation.index', null],
                            'Historicos / Mensagens / Detalhe' => [null, 'admin.histories.messages.index', null],
                            'Inicio / Mensagens / Modelos / Detalhes' => ['dashboard', null, 'admin.message-templates.index', null],
                            'Mensagens / Configuracoes de envio' => [null, null],
                            'Contatos / Historico de mensagens' => ['admin.contacts.index', null],
                            'Inicio / Mensagens / Modelos / Editar' => ['dashboard', null, 'admin.message-templates.index', null],
                            'Historicos / Mensagens' => [null, null],
                            'Inicio / Mensagens / Modelos / Novo' => ['dashboard', null, 'admin.message-templates.index', null],
                            'Inicio / Mensagens / Modelos' => ['dashboard', null, null],
                            'Inicio / Auditoria / Detalhes' => ['dashboard', 'admin.audit-logs.index', null],
                            'Inicio / Mensagens / Modelos / Previa' => ['dashboard', null, 'admin.message-templates.index', null],
                            'Operacao / Manutencao' => [null, null],
                            'Inicio / Mensagens / Lotes' => ['dashboard', null, null],
                            'Inicio / Auditoria' => ['dashboard', null],
                            'Inicio / Mensagens / Campanha / Editar' => ['dashboard', null, 'admin.message-batches.index', null],
                            'Inicio / Mensagens / Lotes / Editar' => ['dashboard', null, 'admin.message-batches.index', null],
                            'Inicio / Mensagens / Lotes / Detalhes' => ['dashboard', null, 'admin.message-batches.index', null],
                            'Inicio / Mensagens / Campanha' => ['dashboard', null, null],
                            'Inicio / Mensagens / Lotes / Novo' => ['dashboard', null, 'admin.message-batches.index', null],
                            'Inicio / Mensagens / Lotes / Destinatarios' => ['dashboard', null, 'admin.message-batches.index', null],
                            'Operacao / Exportacoes' => [null, null],
                            'Operacao / Exportacoes / Detalhe' => [null, 'admin.report-exports.index', null],
                            'Inicio / Usuarios' => ['dashboard', null],
                            'Inicio / Usuarios / Detalhes' => ['dashboard', 'admin.users.index', null],
                            'Inicio / Usuarios / Editar' => ['dashboard', 'admin.users.index', null],
                            'Inicio / Usuarios / Cadastrar' => ['dashboard', 'admin.users.index', null],
                            'Inicio / Pesquisa conversacional / Fluxos' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Fluxos / Novo' => ['dashboard', null, 'admin.conversation-flows.index', null],
                            'Inicio / Pesquisa conversacional / Fluxos / Editar' => ['dashboard', null, 'admin.conversation-flows.index', null],
                            'Inicio / Pesquisa conversacional / Fluxos / Detalhes' => ['dashboard', null, 'admin.conversation-flows.index', null],
                            'Inicio / Pesquisa conversacional / Fluxos / Nova pergunta' => ['dashboard', null, 'admin.conversation-flows.index', null],
                            'Inicio / Pesquisa conversacional / Fluxos / Editar pergunta' => ['dashboard', null, 'admin.conversation-flows.index', null],
                            'Inicio / Pesquisa conversacional / Interpretacao' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Interpretacao / Detalhes' => ['dashboard', null, 'admin.ai-insights.index', null],
                            'Inicio / Pesquisa conversacional / Temas' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Temas / Novo' => ['dashboard', null, 'admin.insight-topics.index', null],
                            'Inicio / Pesquisa conversacional / Temas / Editar' => ['dashboard', null, 'admin.insight-topics.index', null],
                            'Inicio / Pesquisa conversacional / Monitoramento de IA' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Sugestoes' => ['dashboard', null, null],
                            'Inicio / Pesquisa conversacional / Sugestoes / Detalhes' => ['dashboard', null, 'admin.reply-suggestions.index', null],
                            'Inicio / Base de conhecimento' => ['dashboard', null],
                            'Inicio / Base de conhecimento / Nova base' => ['dashboard', 'admin.knowledge.bases.index', null],
                            'Inicio / Base de conhecimento / Editar base' => ['dashboard', 'admin.knowledge.bases.index', null],
                            'Inicio / Base de conhecimento / Base' => ['dashboard', 'admin.knowledge.bases.index', null],
                            'Inicio / Base de conhecimento / Base / Novo documento' => ['dashboard', 'admin.knowledge.bases.index', null, null],
                            'Inicio / Base de conhecimento / Base / Documento' => ['dashboard', 'admin.knowledge.bases.index', null, null],
                            'Inicio / Base de conhecimento / Teste de busca' => ['dashboard', 'admin.knowledge.bases.index', null],
                        ];

                        $breadcrumbTrail = $breadcrumbs ?? 'Inicio';
                        $breadcrumbSegments = array_map('trim', explode('/', $breadcrumbTrail));
                        $breadcrumbRoutes = $breadcrumbRouteMap[$breadcrumbTrail] ?? [];
                    @endphp
                    <div class="muted breadcrumb-trail">
                        @foreach($breadcrumbSegments as $index => $segment)
                            @php $routeName = $breadcrumbRoutes[$index] ?? null; @endphp
                            @if(! $loop->last && $routeName && \Illuminate\Support\Facades\Route::has($routeName))
                                <a href="{{ route($routeName) }}">{{ $segment }}</a>
                            @else
                                <span>{{ $segment }}</span>
                            @endif
                            @if(! $loop->last)<span class="breadcrumb-sep"> / </span>@endif
                        @endforeach
                    </div>
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
