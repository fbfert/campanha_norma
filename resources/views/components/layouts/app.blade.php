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
    @include('components.layouts.partials.icons')
    <div class="app-shell">
        <aside class="sidebar">
            <h1>{{ $systemName ?? config('app.name') }}</h1>
            @include('components.layouts.partials.nav')
        </aside>
        <div class="main">
            <header class="topbar">
                <div>
                    <strong>{{ $title ?? 'Painel' }}</strong>
                    @php
                        // Mapa: string completa da trilha (como hoje passada em `breadcrumbs="A / B / C"`)
                        // => nome da rota de cada segmento, na mesma ordem/posição. `null` = sem link
                        // (o último segmento, a página atual, nunca deve ter link).
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
                            'Atendimento / Conversas / Iniciar conversa' => [null, 'admin.conversations.index', null],
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
                            'Inicio / Manual / Manual de uso' => ['dashboard', null, null],
                            'Inicio / Manual / Mapa mental' => ['dashboard', 'manual.index', null],
                        ];

                        $breadcrumbTrail = $breadcrumbs ?? 'Início';
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
                    <a class="btn ghost" href="{{ route('profile.show') }}"><x-icon name="user" size="16" />Perfil</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn secondary" type="submit"><x-icon name="logout" size="16" />Sair</button>
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
