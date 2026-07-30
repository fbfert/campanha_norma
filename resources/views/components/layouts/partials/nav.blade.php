{{--
    Menu principal, agrupado por tarefa.

    Antes eram 34 links numa lista plana, ordenados por ordem de construção do
    sistema: a conexão do WhatsApp, que precisa funcionar antes de qualquer
    outra coisa, ficava na posição 33.

    Cada grupo e um `<details>` nativo. O grupo que contem a tela atual abre
    sozinho e os demais ficam fechados, o que reduz o que se le de 34 linhas
    para algo em torno de oito. Não ha JavaScript envolvido: `<details>` já
    resolve abrir e fechar, já e navegável por teclado e já e anunciado por
    leitor de tela.

    O grupo Atendimento mostra o contador de conversas não lidas no próprio
    cabeçalho quando esta fechado, senão o aviso desapareceria justamente
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
    $manualAtivo = request()->routeIs('manual.*');
@endphp

<nav aria-label="Menu principal" class="sidebar-nav">
    <a href="{{ route('dashboard') }}" @class(['nav-root', 'active' => request()->routeIs('dashboard')])><x-icon name="home" /><span>Início</span></a>

    @can('inbox.view')
        <details class="nav-group" @if($atendimentoAtivo) open @endif>
            <summary>
                <x-icon name="inbox" />Atendimento
                @if(($unreadConversationsCount ?? 0) > 0)
                    <span class="nav-badge">{{ $unreadConversationsCount }}</span>
                @endif
            </summary>
            <a href="{{ route('admin.conversations.index') }}" @class(['active' => request()->routeIs('admin.inbox.*', 'admin.conversations.*')])>
                <x-icon name="chat" /><span>Conversas</span>
            </a>
            @can('reply_suggestions.view')
                <a href="{{ route('admin.reply-suggestions.index') }}" @class(['active' => request()->routeIs('admin.reply-suggestions.*')])><x-icon name="reply" /><span>Sugestões de resposta</span></a>
            @endcan
        </details>
    @endcan

    @if(auth()->user()->can('conversation_automation.view') || auth()->user()->can('analytics.view_aggregates'))
        <details class="nav-group" @if($pesquisaAtiva) open @endif>
            <summary><x-icon name="poll" />Pesquisa</summary>
            @can('conversation_automation.view')
                <a href="{{ route('admin.conversation-automation.index') }}" @class(['active' => request()->routeIs('admin.conversation-automation.*')])><x-icon name="poll" /><span>Pesquisa conversacional</span></a>
                <a href="{{ route('admin.conversation-flows.index') }}" @class(['active' => request()->routeIs('admin.conversation-flows.*')])><x-icon name="flow" /><span>Fluxos conversacionais</span></a>
            @endcan
            @can('analytics.view_aggregates')
                <a href="{{ route('admin.analytics.dashboard') }}" @class(['active' => request()->routeIs('admin.analytics.dashboard')])><x-icon name="chart" /><span>Painel da pesquisa</span></a>
                <a href="{{ route('admin.analytics.topics') }}" @class(['active' => request()->routeIs('admin.analytics.topics')])><x-icon name="tag" /><span>Temas mais citados</span></a>
                <a href="{{ route('admin.analytics.geography') }}" @class(['active' => request()->routeIs('admin.analytics.geography')])><x-icon name="map" /><span>Geografia</span></a>
                <a href="{{ route('admin.analytics.demands') }}" @class(['active' => request()->routeIs('admin.analytics.demands')])><x-icon name="megaphone" /><span>Demandas</span></a>
                <a href="{{ route('admin.analytics.questions') }}" @class(['active' => request()->routeIs('admin.analytics.questions')])><x-icon name="question" /><span>Qualidade das perguntas</span></a>
            @endcan
        </details>
    @endif

    @can('contacts.view')
        <details class="nav-group" @if($contatosAtivo) open @endif>
            <summary><x-icon name="users" />Contatos</summary>
            <a href="{{ route('admin.contacts.index') }}" @class(['active' => request()->routeIs('admin.contacts.index', 'admin.contacts.show', 'admin.contacts.edit', 'admin.contacts.create')])><x-icon name="users" /><span>Todos os contatos</span></a>
            @can('contacts.import')
                <a href="{{ route('admin.contacts.imports.index') }}" @class(['active' => request()->routeIs('admin.contacts.imports.*', 'admin.contacts.import')])><x-icon name="upload" /><span>Importações</span></a>
            @endcan
            @can('contacts.manage_tags')
                <a href="{{ route('admin.tags.index') }}" @class(['active' => request()->routeIs('admin.tags.*')])><x-icon name="tag" /><span>Etiquetas</span></a>
            @endcan
        </details>
    @endcan

    @if(auth()->user()->can('message_templates.view') || auth()->user()->can('message_batches.view'))
        <details class="nav-group" @if($enviosAtivo) open @endif>
            <summary><x-icon name="send" />Envios</summary>
            @can('message_templates.view')
                <a href="{{ route('admin.message-templates.index') }}" @class(['active' => request()->routeIs('admin.message-templates.*')])><x-icon name="file" /><span>Modelos</span></a>
            @endcan
            @can('message_batches.view')
                <a href="{{ route('admin.message-batches.index') }}" @class(['active' => request()->routeIs('admin.message-batches.*', 'admin.campaigns.*')])><x-icon name="layers" /><span>Lotes e campanhas</span></a>
            @endcan
            @can('message_processing.view')
                <a href="{{ route('admin.message-processing.index') }}" @class(['active' => request()->routeIs('admin.message-processing.*')])><x-icon name="play" /><span>Processamento</span></a>
            @endcan
            @can('histories.view')
                <a href="{{ route('admin.histories.messages.index') }}" @class(['active' => request()->routeIs('admin.histories.*')])><x-icon name="clock" /><span>Histórico de mensagens</span></a>
            @endcan
            @can('reports.view')
                <a href="{{ route('admin.reports.index') }}" @class(['active' => request()->routeIs('admin.reports.index', 'admin.reports.batches', 'admin.reports.messages', 'admin.reports.contacts', 'admin.reports.errors', 'admin.reports.templates', 'admin.reports.attempts', 'admin.reports.not-sent', 'admin.reports.rate-limits')])><x-icon name="report" /><span>Relatórios de envio</span></a>
                <a href="{{ route('admin.reports.conversations') }}" @class(['active' => request()->routeIs('admin.reports.conversations')])><x-icon name="report" /><span>Relatório de conversas</span></a>
            @endcan
            @can('reports.export')
                <a href="{{ route('admin.report-exports.index') }}" @class(['active' => request()->routeIs('admin.report-exports.*')])><x-icon name="download" /><span>Exportações</span></a>
            @endcan
        </details>
    @endif

    @if(auth()->user()->can('ai_insights.view') || auth()->user()->can('knowledge.view') || auth()->user()->can('ai.provider.manage'))
        <details class="nav-group" @if($inteligenciaAtiva) open @endif>
            <summary><x-icon name="sparkles" />Inteligência</summary>
            @can('ai_insights.view')
                <a href="{{ route('admin.ai-insights.index') }}" @class(['active' => request()->routeIs('admin.ai-insights.*')])><x-icon name="sparkles" /><span>Interpretação por IA</span></a>
                <a href="{{ route('admin.insight-topics.index') }}" @class(['active' => request()->routeIs('admin.insight-topics.*')])><x-icon name="tree" /><span>Taxonomia de temas</span></a>
            @endcan
            @can('knowledge.view')
                <a href="{{ route('admin.knowledge.bases.index') }}" @class(['active' => request()->routeIs('admin.knowledge.bases.*', 'admin.knowledge.documents.*')])><x-icon name="book" /><span>Base de conhecimento</span></a>
            @endcan
            @can('knowledge.test_retrieval')
                <a href="{{ route('admin.knowledge.test') }}" @class(['active' => request()->routeIs('admin.knowledge.test')])><x-icon name="search" /><span>Teste de busca na base</span></a>
            @endcan
            @can('analytics.view_aggregates')
                <a href="{{ route('admin.analytics.ai-quality') }}" @class(['active' => request()->routeIs('admin.analytics.ai-quality')])><x-icon name="gauge" /><span>Qualidade da IA</span></a>
            @endcan
            @can('ai_insights.view_monitoring')
                <a href="{{ route('admin.ai-monitoring.index') }}" @class(['active' => request()->routeIs('admin.ai-monitoring.*')])><x-icon name="activity" /><span>Monitoramento de IA</span></a>
            @endcan
            @can('ai.provider.manage')
                <a href="{{ route('admin.ai-provider.edit') }}" @class(['active' => request()->routeIs('admin.ai-provider.*')])><x-icon name="plug" /><span>Provedor de IA</span></a>
            @endcan
        </details>
    @endif

    @if(auth()->user()->can('whatsapp.connection.view') || auth()->user()->can('view-settings') || auth()->user()->can('view-users') || auth()->user()->can('monitoring.view'))
        <details class="nav-group" @if($sistemaAtivo) open @endif>
            <summary><x-icon name="settings" />Sistema</summary>
            @can('whatsapp.connection.view')
                <a href="{{ route('admin.whatsapp.connection') }}" @class(['active' => request()->routeIs('admin.whatsapp.connection')])><x-icon name="phone" /><span>Conexão WhatsApp</span></a>
            @endcan
            @can('whatsapp.events.view')
                <a href="{{ route('admin.whatsapp.events') }}" @class(['active' => request()->routeIs('admin.whatsapp.events')])><x-icon name="bell" /><span>Eventos WhatsApp</span></a>
            @endcan
            @can('view-users')
                <a href="{{ route('admin.users.index') }}" @class(['active' => request()->routeIs('admin.users.*')])><x-icon name="user" /><span>Usuários</span></a>
            @endcan
            @can('view-settings')
                <a href="{{ route('admin.settings.edit') }}" @class(['active' => request()->routeIs('admin.settings.*')])><x-icon name="settings" /><span>Configurações</span></a>
            @endcan
            @can('message_processing.manage_settings')
                <a href="{{ route('admin.message-settings.edit') }}" @class(['active' => request()->routeIs('admin.message-settings.*')])><x-icon name="send" /><span>Configurações de envio</span></a>
            @endcan
            @can('analytics.view_governance')
                <a href="{{ route('admin.analytics.governance') }}" @class(['active' => request()->routeIs('admin.analytics.governance')])><x-icon name="shield" /><span>Governança</span></a>
            @endcan
            @can('monitoring.view')
                <a href="{{ route('admin.monitoring.index') }}" @class(['active' => request()->routeIs('admin.monitoring.*')])><x-icon name="pulse" /><span>Saúde do sistema</span></a>
            @endcan
            @can('maintenance.view')
                <a href="{{ route('admin.maintenance.index') }}" @class(['active' => request()->routeIs('admin.maintenance.*')])><x-icon name="wrench" /><span>Manutenção</span></a>
            @endcan
            @can('view-audit')
                <a href="{{ route('admin.audit-logs.index') }}" @class(['active' => request()->routeIs('admin.audit-logs.*')])><x-icon name="scroll" /><span>Auditoria</span></a>
            @endcan
        </details>
    @endif

    {{-- Sem `@can`: quem entrou no sistema pode ler como o sistema funciona.
         Fica por último porque e material de consulta, não de operação. --}}
    <details class="nav-group" @if($manualAtivo) open @endif>
        <summary><x-icon name="book" />Manual</summary>
        <a href="{{ route('manual.index') }}" @class(['active' => request()->routeIs('manual.index')])><x-icon name="book" /><span>Manual de uso</span></a>
        <a href="{{ route('manual.mind-map') }}" @class(['active' => request()->routeIs('manual.mind-map')])><x-icon name="mind-map" /><span>Mapa mental</span></a>
    </details>
</nav>
