{{--
    Menu principal, agrupado por tarefa.

    Antes eram 34 links numa lista plana, ordenados por ordem de construcao do
    sistema: a conexao do WhatsApp, que precisa funcionar antes de qualquer
    outra coisa, ficava na posicao 33.

    Cada grupo e um `<details>` nativo. O grupo que contem a tela atual abre
    sozinho e os demais ficam fechados, o que reduz o que se le de 34 linhas
    para algo em torno de oito. Nao ha JavaScript envolvido: `<details>` ja
    resolve abrir e fechar, ja e navegavel por teclado e ja e anunciado por
    leitor de tela.

    O grupo Atendimento mostra o contador de conversas nao lidas no proprio
    cabecalho quando esta fechado, senao o aviso desapareceria justamente
    quando o menu esta recolhido.
--}}
@php
    $atendimentoAtivo = request()->routeIs('admin.inbox.*', 'admin.conversations.*', 'admin.reply-suggestions.*');
    $pesquisaAtiva = request()->routeIs('admin.conversation-automation.*', 'admin.conversation-flows.*')
        || request()->routeIs('admin.analytics.dashboard', 'admin.analytics.topics', 'admin.analytics.geography', 'admin.analytics.demands', 'admin.analytics.questions');
    $contatosAtivo = request()->routeIs('admin.contacts.*', 'admin.tags.*');
    $enviosAtivo = request()->routeIs('admin.message-templates.*', 'admin.message-batches.*', 'admin.campaigns.*', 'admin.message-processing.*', 'admin.histories.*', 'admin.reports.*', 'admin.report-exports.*');
    $inteligenciaAtiva = request()->routeIs('admin.ai-insights.*', 'admin.insight-topics.*', 'admin.knowledge.*', 'admin.ai-monitoring.*', 'admin.ai-provider.*', 'admin.analytics.ai-quality');
    $sistemaAtivo = request()->routeIs('admin.whatsapp.*', 'admin.users.*', 'admin.settings.*', 'admin.message-settings.*', 'admin.analytics.governance', 'admin.monitoring.*', 'admin.maintenance.*', 'admin.audit-logs.*');
@endphp

<nav aria-label="Menu principal" class="sidebar-nav">
    <a href="{{ route('dashboard') }}" @class(['nav-root', 'active' => request()->routeIs('dashboard')])>Inicio</a>

    @can('inbox.view')
        <details class="nav-group" @if($atendimentoAtivo) open @endif>
            <summary>
                Atendimento
                @if(($unreadConversationsCount ?? 0) > 0)
                    <span class="nav-badge">{{ $unreadConversationsCount }}</span>
                @endif
            </summary>
            <a href="{{ route('admin.conversations.index') }}" @class(['active' => request()->routeIs('admin.inbox.*', 'admin.conversations.*')])>
                <span class="nav-label"><span class="nav-icon chat-icon" aria-hidden="true"></span>Conversas</span>
            </a>
            @can('reply_suggestions.view')
                <a href="{{ route('admin.reply-suggestions.index') }}" @class(['active' => request()->routeIs('admin.reply-suggestions.*')])>Sugestoes de resposta</a>
            @endcan
        </details>
    @endcan

    @if(auth()->user()->can('conversation_automation.view') || auth()->user()->can('analytics.view_aggregates'))
        <details class="nav-group" @if($pesquisaAtiva) open @endif>
            <summary>Pesquisa</summary>
            @can('conversation_automation.view')
                <a href="{{ route('admin.conversation-automation.index') }}" @class(['active' => request()->routeIs('admin.conversation-automation.*')])>Pesquisa conversacional</a>
                <a href="{{ route('admin.conversation-flows.index') }}" @class(['active' => request()->routeIs('admin.conversation-flows.*')])>Fluxos conversacionais</a>
            @endcan
            @can('analytics.view_aggregates')
                <a href="{{ route('admin.analytics.dashboard') }}" @class(['active' => request()->routeIs('admin.analytics.dashboard')])>Painel da pesquisa</a>
                <a href="{{ route('admin.analytics.topics') }}" @class(['active' => request()->routeIs('admin.analytics.topics')])>Temas mais citados</a>
                <a href="{{ route('admin.analytics.geography') }}" @class(['active' => request()->routeIs('admin.analytics.geography')])>Geografia</a>
                <a href="{{ route('admin.analytics.demands') }}" @class(['active' => request()->routeIs('admin.analytics.demands')])>Demandas</a>
                <a href="{{ route('admin.analytics.questions') }}" @class(['active' => request()->routeIs('admin.analytics.questions')])>Qualidade das perguntas</a>
            @endcan
        </details>
    @endif

    @can('contacts.view')
        <details class="nav-group" @if($contatosAtivo) open @endif>
            <summary>Contatos</summary>
            <a href="{{ route('admin.contacts.index') }}" @class(['active' => request()->routeIs('admin.contacts.index', 'admin.contacts.show', 'admin.contacts.edit', 'admin.contacts.create')])>Todos os contatos</a>
            @can('contacts.import')
                <a href="{{ route('admin.contacts.imports.index') }}" @class(['active' => request()->routeIs('admin.contacts.imports.*', 'admin.contacts.import')])>Importacoes</a>
            @endcan
            @can('contacts.manage_tags')
                <a href="{{ route('admin.tags.index') }}" @class(['active' => request()->routeIs('admin.tags.*')])>Etiquetas</a>
            @endcan
        </details>
    @endcan

    @if(auth()->user()->can('message_templates.view') || auth()->user()->can('message_batches.view'))
        <details class="nav-group" @if($enviosAtivo) open @endif>
            <summary>Envios</summary>
            @can('message_templates.view')
                <a href="{{ route('admin.message-templates.index') }}" @class(['active' => request()->routeIs('admin.message-templates.*')])>Modelos</a>
            @endcan
            @can('message_batches.view')
                <a href="{{ route('admin.message-batches.index') }}" @class(['active' => request()->routeIs('admin.message-batches.*', 'admin.campaigns.*')])>Lotes e campanhas</a>
            @endcan
            @can('message_processing.view')
                <a href="{{ route('admin.message-processing.index') }}" @class(['active' => request()->routeIs('admin.message-processing.*')])>Processamento</a>
            @endcan
            @can('histories.view')
                <a href="{{ route('admin.histories.messages.index') }}" @class(['active' => request()->routeIs('admin.histories.*')])>Historico de mensagens</a>
            @endcan
            @can('reports.view')
                <a href="{{ route('admin.reports.index') }}" @class(['active' => request()->routeIs('admin.reports.index', 'admin.reports.batches', 'admin.reports.messages', 'admin.reports.contacts', 'admin.reports.errors', 'admin.reports.templates', 'admin.reports.attempts', 'admin.reports.not-sent', 'admin.reports.rate-limits')])>Relatorios de envio</a>
                <a href="{{ route('admin.reports.conversations') }}" @class(['active' => request()->routeIs('admin.reports.conversations')])>Relatorio de conversas</a>
            @endcan
            @can('reports.export')
                <a href="{{ route('admin.report-exports.index') }}" @class(['active' => request()->routeIs('admin.report-exports.*')])>Exportacoes</a>
            @endcan
        </details>
    @endif

    @if(auth()->user()->can('ai_insights.view') || auth()->user()->can('knowledge.view') || auth()->user()->can('ai.provider.manage'))
        <details class="nav-group" @if($inteligenciaAtiva) open @endif>
            <summary>Inteligencia</summary>
            @can('ai_insights.view')
                <a href="{{ route('admin.ai-insights.index') }}" @class(['active' => request()->routeIs('admin.ai-insights.*')])>Interpretacao por IA</a>
                <a href="{{ route('admin.insight-topics.index') }}" @class(['active' => request()->routeIs('admin.insight-topics.*')])>Taxonomia de temas</a>
            @endcan
            @can('knowledge.view')
                <a href="{{ route('admin.knowledge.bases.index') }}" @class(['active' => request()->routeIs('admin.knowledge.bases.*', 'admin.knowledge.documents.*')])>Base de conhecimento</a>
            @endcan
            @can('knowledge.test_retrieval')
                <a href="{{ route('admin.knowledge.test') }}" @class(['active' => request()->routeIs('admin.knowledge.test')])>Teste de busca na base</a>
            @endcan
            @can('analytics.view_aggregates')
                <a href="{{ route('admin.analytics.ai-quality') }}" @class(['active' => request()->routeIs('admin.analytics.ai-quality')])>Qualidade da IA</a>
            @endcan
            @can('ai_insights.view_monitoring')
                <a href="{{ route('admin.ai-monitoring.index') }}" @class(['active' => request()->routeIs('admin.ai-monitoring.*')])>Monitoramento de IA</a>
            @endcan
            @can('ai.provider.manage')
                <a href="{{ route('admin.ai-provider.edit') }}" @class(['active' => request()->routeIs('admin.ai-provider.*')])>Provedor de IA</a>
            @endcan
        </details>
    @endif

    @if(auth()->user()->can('whatsapp.connection.view') || auth()->user()->can('view-settings') || auth()->user()->can('view-users') || auth()->user()->can('monitoring.view'))
        <details class="nav-group" @if($sistemaAtivo) open @endif>
            <summary>Sistema</summary>
            @can('whatsapp.connection.view')
                <a href="{{ route('admin.whatsapp.connection') }}" @class(['active' => request()->routeIs('admin.whatsapp.connection')])>Conexao WhatsApp</a>
            @endcan
            @can('whatsapp.events.view')
                <a href="{{ route('admin.whatsapp.events') }}" @class(['active' => request()->routeIs('admin.whatsapp.events')])>Eventos WhatsApp</a>
            @endcan
            @can('view-users')
                <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])>Usuarios</a>
            @endcan
            @can('view-settings')
                <a href="{{ route('admin.settings.edit') }}" @class(['active' => request()->routeIs('admin.settings.*')])>Configuracoes</a>
            @endcan
            @can('message_processing.manage_settings')
                <a href="{{ route('admin.message-settings.edit') }}" @class(['active' => request()->routeIs('admin.message-settings.*')])>Configuracoes de envio</a>
            @endcan
            @can('analytics.view_governance')
                <a href="{{ route('admin.analytics.governance') }}" @class(['active' => request()->routeIs('admin.analytics.governance')])>Governanca</a>
            @endcan
            @can('monitoring.view')
                <a href="{{ route('admin.monitoring.index') }}" @class(['active' => request()->routeIs('admin.monitoring.*')])>Saude do sistema</a>
            @endcan
            @can('maintenance.view')
                <a href="{{ route('admin.maintenance.index') }}" @class(['active' => request()->routeIs('admin.maintenance.*')])>Manutencao</a>
            @endcan
            @can('view-audit')
                <a href="{{ route('admin.audit-logs.index') }}" @class(['active' => request()->routeIs('admin.audit-logs.*')])>Auditoria</a>
            @endcan
        </details>
    @endif
</nav>
