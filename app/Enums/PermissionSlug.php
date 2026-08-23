<?php

namespace App\Enums;

enum PermissionSlug: string
{
    case DashboardView = 'dashboard.view';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
    case ProfileManage = 'profile.manage';
    case ContactsView = 'contacts.view';
    case ContactsCreate = 'contacts.create';
    case ContactsUpdate = 'contacts.update';
    case ContactsDelete = 'contacts.delete';
    case ContactsRestore = 'contacts.restore';
    case ContactsExport = 'contacts.export';
    case ContactsImport = 'contacts.import';
    case ContactsManageTags = 'contacts.manage_tags';
    case ContactsMarkDoNotContact = 'contacts.mark_do_not_contact';
    case ContactsViewSensitiveData = 'contacts.view_sensitive_data';
    case WhatsAppConnectionView = 'whatsapp.connection.view';
    case WhatsAppConnectionManage = 'whatsapp.connection.manage';
    case WhatsAppMetaManage = 'whatsapp.meta.manage';
    case WhatsAppConnectionDisconnect = 'whatsapp.connection.disconnect';
    case WhatsAppConnectionClearSession = 'whatsapp.connection.clear_session';
    case WhatsAppTestMessageSend = 'whatsapp.test_message.send';
    case WhatsAppEventsView = 'whatsapp.events.view';
    case MessageTemplatesView = 'message_templates.view';
    case MessageTemplatesCreate = 'message_templates.create';
    case MessageTemplatesUpdate = 'message_templates.update';
    case MessageTemplatesDelete = 'message_templates.delete';
    case MessageTemplatesRestore = 'message_templates.restore';
    case MessageTemplatesDuplicate = 'message_templates.duplicate';
    case MessageBatchesView = 'message_batches.view';
    case MessageBatchesCreate = 'message_batches.create';
    case MessageBatchesUpdate = 'message_batches.update';
    case MessageBatchesCancel = 'message_batches.cancel';
    case MessageBatchesDuplicate = 'message_batches.duplicate';
    case MessageBatchesViewRecipients = 'message_batches.view_recipients';
    case MessageBatchesExportPreview = 'message_batches.export_preview';
    case MessageProcessingView = 'message_processing.view';
    case MessageProcessingStart = 'message_processing.start';
    case MessageProcessingPause = 'message_processing.pause';
    case MessageProcessingResume = 'message_processing.resume';
    case MessageProcessingStop = 'message_processing.stop';
    case MessageProcessingCancelRecipient = 'message_processing.cancel_recipient';
    case MessageProcessingRetry = 'message_processing.retry';
    case MessageProcessingViewAttempts = 'message_processing.view_attempts';
    case MessageProcessingManageSettings = 'message_processing.manage_settings';
    case MessageProcessingRunMaintenance = 'message_processing.run_maintenance';
    case HistoriesView = 'histories.view';
    case HistoriesViewMessageContent = 'histories.view_message_content';
    case HistoriesViewAttempts = 'histories.view_attempts';
    case HistoriesViewTechnicalDetails = 'histories.view_technical_details';
    case HistoriesExport = 'histories.export';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case ReportsViewContactData = 'reports.view_contact_data';
    case ReportsViewOperationalMetrics = 'reports.view_operational_metrics';
    case MonitoringView = 'monitoring.view';
    case MonitoringViewSensitiveDetails = 'monitoring.view_sensitive_details';
    case MonitoringRunDiagnostics = 'monitoring.run_diagnostics';
    case MaintenanceView = 'maintenance.view';
    case MaintenanceSyncCounters = 'maintenance.sync_counters';
    case MaintenanceRecoverStuck = 'maintenance.recover_stuck';
    case MaintenanceRetryEligible = 'maintenance.retry_eligible';
    case MaintenanceCleanupLogs = 'maintenance.cleanup_logs';
    case MaintenanceApplyRetention = 'maintenance.apply_retention';
    case MaintenanceRunCommands = 'maintenance.run_commands';
    case InboxView = 'inbox.view';
    case InboxViewAll = 'inbox.view_all';
    case InboxViewMessageContent = 'inbox.view_message_content';
    case InboxReply = 'inbox.reply';
    case InboxAssign = 'inbox.assign';
    case InboxChangeStatus = 'inbox.change_status';
    case InboxChangePriority = 'inbox.change_priority';
    case InboxManageTags = 'inbox.manage_tags';
    case InboxAddNotes = 'inbox.add_notes';
    case InboxEditNotes = 'inbox.edit_notes';
    case InboxArchive = 'inbox.archive';
    case InboxBlock = 'inbox.block';
    case InboxMarkDoNotContact = 'inbox.mark_do_not_contact';
    case InboxAssociateContact = 'inbox.associate_contact';
    case InboxViewMetrics = 'inbox.view_metrics';
    case InboxSync = 'inbox.sync';
    case ConversationAutomationView = 'conversation_automation.view';
    case ConversationAutomationManageFlows = 'conversation_automation.manage_flows';
    case ConversationAutomationManageQuestions = 'conversation_automation.manage_questions';
    case ConversationAutomationControl = 'conversation_automation.control';

    // Separada de `control` porque o alcance e outro: `control` pausa e retoma
    // uma conversa, esta liga e desliga o motor para toda a base e decide o
    // texto que sai sem revisão humana.
    case ConversationAutomationManageSettings = 'conversation_automation.manage_settings';
    // Atendimento de entrada. `start` é separada de `manage_profiles` pela
    // mesma razão que `control` é separada de `manage_settings`: iniciar uma
    // conversa é decisão sobre uma pessoa, e editar o perfil é decidir o texto
    // que sai para todas elas sem ninguém ler.
    case InboundAttendanceView = 'inbound_attendance.view';
    case InboundAttendanceStart = 'inbound_attendance.start';
    case InboundAttendanceManageProfiles = 'inbound_attendance.manage_profiles';
    case AiInsightsView = 'ai_insights.view';
    case AiInsightsViewContactData = 'ai_insights.view_contact_data';
    case AiInsightsCorrect = 'ai_insights.correct';
    case AiInsightsReprocess = 'ai_insights.reprocess';
    case AiInsightsManageTaxonomy = 'ai_insights.manage_taxonomy';
    case AiInsightsViewMonitoring = 'ai_insights.view_monitoring';
    case ReplySuggestionsView = 'reply_suggestions.view';
    case ReplySuggestionsApprove = 'reply_suggestions.approve';
    case ReplySuggestionsReject = 'reply_suggestions.reject';
    case ReplySuggestionsRegenerate = 'reply_suggestions.regenerate';
    case ReplySuggestionsFeedback = 'reply_suggestions.feedback';
    case ReplySuggestionsManageSettings = 'reply_suggestions.manage_settings';
    case KnowledgeView = 'knowledge.view';
    case KnowledgeManageBases = 'knowledge.manage_bases';
    case KnowledgeUploadDocuments = 'knowledge.upload_documents';
    case KnowledgeApproveDocuments = 'knowledge.approve_documents';
    case KnowledgeDeleteDocuments = 'knowledge.delete_documents';
    case KnowledgeDownloadDocuments = 'knowledge.download_documents';
    case KnowledgeTestRetrieval = 'knowledge.test_retrieval';
    case KnowledgeManageSettings = 'knowledge.manage_settings';

    // Configuração do provedor de IA. Separada de `settings.manage` porque
    // aqui se digita credencial: quem ajusta nome do sistema e formato de data
    // não precisa, pelo mesmo ato, poder trocar a chave que paga a conta.
    case AiProviderManage = 'ai.provider.manage';

    // Etapa 9E. A separação que mais importa e entre ver agregado, ver
    // conteúdo e ver identificação: saber que saúde foi o tema mais citado,
    // ler o que alguém escreveu e saber quem escreveu são três níveis
    // distintos de exposição, e um perfil de consulta so recebe o primeiro.
    case AnalyticsViewAggregates = 'analytics.view_aggregates';
    case AnalyticsViewContent = 'analytics.view_content';
    case AnalyticsViewIdentification = 'analytics.view_identification';
    case AnalyticsExportAggregates = 'analytics.export_aggregates';
    case AnalyticsExportDetailed = 'analytics.export_detailed';
    case AnalyticsViewCosts = 'analytics.view_costs';
    case AnalyticsViewGovernance = 'analytics.view_governance';

    // Etapa 10. Ver a campanha, administrar a campanha, invalidar participação
    // e executar o sorteio são separados porque quem acompanha os números não
    // precisa poder mexer na lista, e mexer na lista antes de um sorteio é a
    // ação que mais precisa de dono identificado.
    case KeywordCampaignsView = 'keyword_campaigns.view';
    case KeywordCampaignsManage = 'keyword_campaigns.manage';
    case KeywordParticipationsView = 'keyword_participations.view';
    case KeywordParticipationsInvalidate = 'keyword_participations.invalidate';
    case KeywordParticipationsExport = 'keyword_participations.export';
    case KeywordDrawsExecute = 'keyword_draws.execute';
    case KeywordCouponsManage = 'keyword_coupons.manage';

    /*
     | A Limpeza remove participação de gente real, e a lixeira devolve.
     |
     | São três permissões e não uma porque ver o que existe, mandar embora e
     | trazer de volta são decisões de peso diferente: quem apura um pedido de
     | remoção precisa da primeira sem precisar das outras duas.
     */
    case CleanupView = 'cleanup.view';
    case CleanupExecute = 'cleanup.execute';
    case CleanupRestore = 'cleanup.restore';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Visualizar dashboard',
            self::UsersView => 'Visualizar usuários',
            self::UsersManage => 'Gerenciar usuários',
            self::SettingsView => 'Visualizar configurações',
            self::SettingsManage => 'Gerenciar configurações',
            self::AuditView => 'Visualizar auditoria',
            self::ProfileManage => 'Gerenciar próprio perfil',
            self::ContactsView => 'Visualizar contatos',
            self::ContactsCreate => 'Cadastrar contatos',
            self::ContactsUpdate => 'Editar contatos',
            self::ContactsDelete => 'Excluir contatos',
            self::ContactsRestore => 'Restaurar contatos',
            self::ContactsExport => 'Exportar contatos',
            self::ContactsImport => 'Importar contatos',
            self::ContactsManageTags => 'Gerenciar etiquetas',
            self::ContactsMarkDoNotContact => 'Marcar não contatar',
            self::ContactsViewSensitiveData => 'Visualizar dados sensíveis de contatos',
            self::WhatsAppConnectionView => 'Visualizar conexão WhatsApp',
            self::WhatsAppConnectionManage => 'Gerenciar conexão WhatsApp',
            self::WhatsAppMetaManage => 'Configurar a API oficial da Meta e suas credenciais',
            self::WhatsAppConnectionDisconnect => 'Desconectar WhatsApp',
            self::WhatsAppConnectionClearSession => 'Excluir sessão WhatsApp',
            self::WhatsAppTestMessageSend => 'Enviar mensagem de teste WhatsApp',
            self::WhatsAppEventsView => 'Visualizar eventos WhatsApp',
            self::MessageTemplatesView => 'Visualizar modelos de mensagens',
            self::MessageTemplatesCreate => 'Criar modelos de mensagens',
            self::MessageTemplatesUpdate => 'Editar modelos de mensagens',
            self::MessageTemplatesDelete => 'Excluir modelos de mensagens',
            self::MessageTemplatesRestore => 'Restaurar modelos de mensagens',
            self::MessageTemplatesDuplicate => 'Duplicar modelos de mensagens',
            self::MessageBatchesView => 'Visualizar lotes de mensagens',
            self::MessageBatchesCreate => 'Criar lotes de mensagens',
            self::MessageBatchesUpdate => 'Editar lotes de mensagens',
            self::MessageBatchesCancel => 'Cancelar lotes de mensagens',
            self::MessageBatchesDuplicate => 'Duplicar lotes de mensagens',
            self::MessageBatchesViewRecipients => 'Visualizar destinatários de lotes',
            self::MessageBatchesExportPreview => 'Exportar previa de lotes',
            self::MessageProcessingView => 'Visualizar processamento de mensagens',
            self::MessageProcessingStart => 'Iniciar processamento de lote',
            self::MessageProcessingPause => 'Pausar processamento de lote',
            self::MessageProcessingResume => 'Retomar processamento de lote',
            self::MessageProcessingStop => 'Parar processamento de lote',
            self::MessageProcessingCancelRecipient => 'Cancelar destinatário do processamento',
            self::MessageProcessingRetry => 'Tentar novamente destinatário com falha',
            self::MessageProcessingViewAttempts => 'Visualizar tentativas de envio',
            self::MessageProcessingManageSettings => 'Gerenciar configurações de envio',
            self::MessageProcessingRunMaintenance => 'Executar manutenção de processamento',
            self::HistoriesView => 'Visualizar históricos',
            self::HistoriesViewMessageContent => 'Visualizar conteúdo de mensagens no histórico',
            self::HistoriesViewAttempts => 'Visualizar tentativas no histórico',
            self::HistoriesViewTechnicalDetails => 'Visualizar detalhes técnicos do histórico',
            self::HistoriesExport => 'Exportar históricos',
            self::ReportsView => 'Visualizar relatórios',
            self::ReportsExport => 'Exportar relatórios',
            self::ReportsViewContactData => 'Visualizar dados de contato em relatórios',
            self::ReportsViewOperationalMetrics => 'Visualizar métricas operacionais',
            self::MonitoringView => 'Visualizar monitoramento',
            self::MonitoringViewSensitiveDetails => 'Visualizar detalhes sensíveis do monitoramento',
            self::MonitoringRunDiagnostics => 'Executar diagnosticos',
            self::MaintenanceView => 'Visualizar manutenção',
            self::MaintenanceSyncCounters => 'Sincronizar contadores',
            self::MaintenanceRecoverStuck => 'Recuperar mensagens presas',
            self::MaintenanceRetryEligible => 'Tentar novamente mensagens elegíveis',
            self::MaintenanceCleanupLogs => 'Limpar logs e arquivos temporários',
            self::MaintenanceApplyRetention => 'Aplicar retenção de dados',
            self::MaintenanceRunCommands => 'Executar comandos de manutenção',
            self::CleanupView => 'Visualizar a Limpeza',
            self::CleanupExecute => 'Executar limpeza de participações',
            self::CleanupRestore => 'Restaurar limpeza da lixeira',
            self::InboxView => 'Visualizar caixa de entrada',
            self::InboxViewAll => 'Visualizar todas as conversas',
            self::InboxViewMessageContent => 'Visualizar conteúdo de conversas',
            self::InboxReply => 'Responder manualmente conversas',
            self::InboxAssign => 'Atribuir conversas',
            self::InboxChangeStatus => 'Alterar status de conversas',
            self::InboxChangePriority => 'Alterar prioridade de conversas',
            self::InboxManageTags => 'Gerenciar etiquetas de conversas',
            self::InboxAddNotes => 'Adicionar notas internas',
            self::InboxEditNotes => 'Editar notas internas',
            self::InboxArchive => 'Arquivar conversas',
            self::InboxBlock => 'Bloquear conversas',
            self::InboxMarkDoNotContact => 'Marcar contato como não contatar pela conversa',
            self::InboxAssociateContact => 'Associar contato a conversa',
            self::InboxViewMetrics => 'Visualizar métricas da caixa de entrada',
            self::InboxSync => 'Sincronizar conversas do WhatsApp',
            self::ConversationAutomationView => 'Visualizar pesquisa conversacional',
            self::ConversationAutomationManageFlows => 'Gerenciar fluxos conversacionais',
            self::ConversationAutomationManageQuestions => 'Gerenciar perguntas dos fluxos',
            self::ConversationAutomationControl => 'Controlar automação das conversas',
            self::ConversationAutomationManageSettings => 'Ligar, desligar e configurar a automação conversacional',
            self::AiInsightsView => 'Visualizar interpretação por IA',
            self::AiInsightsViewContactData => 'Visualizar dados de contato nas telas analíticas',
            self::AiInsightsCorrect => 'Corrigir classificação e insights',
            self::AiInsightsReprocess => 'Reprocessar interpretação por IA',
            self::AiInsightsManageTaxonomy => 'Gerenciar temas de insights',
            self::AiInsightsViewMonitoring => 'Visualizar monitoramento de IA',
            self::ReplySuggestionsView => 'Visualizar sugestões de resposta',
            self::ReplySuggestionsApprove => 'Aprovar e enviar sugestões de resposta',
            self::ReplySuggestionsReject => 'Rejeitar sugestões de resposta',
            self::ReplySuggestionsRegenerate => 'Regenerar sugestões de resposta',
            self::ReplySuggestionsFeedback => 'Registrar feedback de sugestões',
            self::ReplySuggestionsManageSettings => 'Gerenciar configurações de geração',
            self::KnowledgeView => 'Visualizar base de conhecimento',
            self::KnowledgeManageBases => 'Gerenciar bases de conhecimento',
            self::KnowledgeUploadDocuments => 'Enviar documentos para a base',
            self::KnowledgeApproveDocuments => 'Aprovar, rejeitar e tornar documentos obsoletos',
            self::KnowledgeDeleteDocuments => 'Excluir documentos da base',
            self::KnowledgeDownloadDocuments => 'Baixar arquivo original de documento',
            self::KnowledgeTestRetrieval => 'Testar busca e resposta sem envio',
            self::KnowledgeManageSettings => 'Gerenciar configurações da base de conhecimento',
            self::AiProviderManage => 'Configurar provedor de IA e credenciais',
            self::AnalyticsViewAggregates => 'Ver relatórios agregados',
            self::AnalyticsViewContent => 'Ver conteúdo de mensagens nos relatórios',
            self::AnalyticsViewIdentification => 'Ver identificação do contato nos relatórios',
            self::AnalyticsExportAggregates => 'Exportar relatórios agregados',
            self::AnalyticsExportDetailed => 'Exportar dados detalhados com conteúdo',
            self::AnalyticsViewCosts => 'Ver custos de IA nos relatórios',
            self::AnalyticsViewGovernance => 'Ver relatório de governança',
            self::InboundAttendanceView => 'Ver a fila de mensagens aguardando resposta',
            self::InboundAttendanceStart => 'Iniciar conversa automática a partir da fila',
            self::InboundAttendanceManageProfiles => 'Criar e editar perfis de atendimento de entrada',
            self::KeywordCampaignsView => 'Ver campanhas por palavra-chave',
            self::KeywordCampaignsManage => 'Criar, editar e congelar campanhas por palavra-chave',
            self::KeywordParticipationsView => 'Ver participantes de campanha',
            self::KeywordParticipationsInvalidate => 'Invalidar e conferir participações',
            self::KeywordParticipationsExport => 'Exportar participantes de campanha',
            self::KeywordDrawsExecute => 'Executar o sorteio de uma campanha',
            self::KeywordCouponsManage => 'Importar cupons e ver os códigos',
        };
    }
}
