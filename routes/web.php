<?php

use App\Http\Controllers\Admin\Ai\AiMonitoringController;
use App\Http\Controllers\Admin\Ai\AiProviderController;
use App\Http\Controllers\Admin\Ai\ConversationInsightController;
use App\Http\Controllers\Admin\Ai\InsightTopicController;
use App\Http\Controllers\Admin\Analytics\AnalyticsController;
use App\Http\Controllers\Admin\Analytics\AnalyticsExportController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Contacts\ContactBulkController;
use App\Http\Controllers\Admin\Contacts\ContactController;
use App\Http\Controllers\Admin\Contacts\ContactImportController;
use App\Http\Controllers\Admin\Contacts\TagController;
use App\Http\Controllers\Admin\ConversationAutomation\ConversationAutomationSettingsController;
use App\Http\Controllers\Admin\ConversationAutomation\ConversationFlowController;
use App\Http\Controllers\Admin\ConversationAutomation\ConversationFlowQuestionController;
use App\Http\Controllers\Admin\ConversationAutomation\ConversationFlowStateController;
use App\Http\Controllers\Admin\InboundAttendance\InboundAttendanceProfileController;
use App\Http\Controllers\Admin\InboundAttendance\InboundAttendanceQueueController;
use App\Http\Controllers\Admin\Histories\MessageHistoryController;
use App\Http\Controllers\Admin\Inbox\ConversationMediaController;
use App\Http\Controllers\Admin\Inbox\InboxController;
use App\Http\Controllers\Admin\Knowledge\KnowledgeBaseController;
use App\Http\Controllers\Admin\Knowledge\KnowledgeDocumentController;
use App\Http\Controllers\Admin\Knowledge\KnowledgeTestController;
use App\Http\Controllers\Admin\Maintenance\MaintenanceController;
use App\Http\Controllers\Admin\MessageBatches\MessageBatchController;
use App\Http\Controllers\Admin\MessageProcessing\MessageProcessingController;
use App\Http\Controllers\Admin\MessageProcessing\MessageSettingsController;
use App\Http\Controllers\Admin\MessageTemplates\MessageTemplateController;
use App\Http\Controllers\Admin\Monitoring\MonitoringController;
use App\Http\Controllers\Admin\Reports\ReportController;
use App\Http\Controllers\Admin\Reports\ReportExportController;
use App\Http\Controllers\Admin\ResponseGeneration\ReplySuggestionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WhatsApp\MetaSettingsController;
use App\Http\Controllers\Admin\WhatsApp\WhatsAppConnectionController;
use App\Http\Controllers\Admin\WhatsApp\WhatsAppEventController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Internal\MetaWebhookController;
use App\Http\Controllers\Internal\WhatsAppIncomingController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::post('/internal/whatsapp/incoming', WhatsAppIncomingController::class)
    ->middleware('throttle:60,1')
    ->name('internal.whatsapp.incoming');

/*
 | Webhook da API oficial da Meta.
 |
 | O limite é bem mais folgado que o do serviço Node porque quem chama é a Meta,
 | e ela reenvia enquanto não receber 200: estrangular aqui não reduz o tráfego,
 | multiplica. Uma requisição pode trazer várias mensagens de uma vez.
 */
Route::get('/internal/whatsapp/meta', [MetaWebhookController::class, 'verify'])
    ->name('internal.whatsapp.meta.verify');

Route::post('/internal/whatsapp/meta', [MetaWebhookController::class, 'receive'])
    ->middleware('throttle:600,1')
    ->name('internal.whatsapp.meta.receive');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware(['auth'])->group(function (): void {
    Route::get('/force-password', [ForcedPasswordController::class, 'edit'])->name('password.force.edit');
    Route::put('/force-password', [ForcedPasswordController::class, 'update'])->name('password.force.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'password.changed'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Manual. Sem permissão própria: quem entrou no sistema pode ler como o
    // sistema funciona.
    Route::get('/manual', [ManualController::class, 'index'])->name('manual.index');
    Route::get('/manual/mapa-mental', [ManualController::class, 'mindMap'])->name('manual.mind-map');
    Route::get('/manual/iniciar-pesquisa', [ManualController::class, 'surveyStart'])->name('manual.survey-start');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::resource('users', UserController::class);
        Route::patch('/users/{user}/status', [UserController::class, 'status'])->name('users.status');
        Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');

        Route::get('/message-processing', [MessageProcessingController::class, 'index'])->name('message-processing.index');
        Route::get('/message-settings', [MessageSettingsController::class, 'edit'])->name('message-settings.edit');
        Route::put('/message-settings', [MessageSettingsController::class, 'update'])->name('message-settings.update');

        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('/inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        Route::get('/conversations', [InboxController::class, 'index'])->name('conversations.index');
        // Antes de `/conversations/{conversation}`, senão "nova" seria lido como
        // identificador de conversa.
        Route::get('/conversations/nova', [InboxController::class, 'create'])->name('conversations.create');
        Route::post('/conversations/nova', [InboxController::class, 'store'])->name('conversations.store');
        Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->name('conversations.show');
        Route::post('/conversations/sync', [InboxController::class, 'sync'])->name('conversations.sync');
        Route::post('/inbox/{conversation}/read', [InboxController::class, 'show'])->name('inbox.read');
        Route::get('/inbox/{conversation}/messages', [InboxController::class, 'messages'])->name('inbox.messages');
        Route::get('/inbox/{conversation}/messages/{message}/media', [ConversationMediaController::class, 'show'])->name('inbox.messages.media');
        Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
        Route::post('/inbox/{conversation}/assign', [InboxController::class, 'assign'])->name('inbox.assign');
        Route::post('/inbox/{conversation}/unassign', [InboxController::class, 'unassign'])->name('inbox.unassign');
        Route::post('/inbox/{conversation}/status', [InboxController::class, 'status'])->name('inbox.status');
        Route::post('/inbox/{conversation}/priority', [InboxController::class, 'priority'])->name('inbox.priority');
        Route::post('/inbox/{conversation}/archive', [InboxController::class, 'archive'])->name('inbox.archive');
        Route::post('/inbox/{conversation}/unarchive', [InboxController::class, 'unarchive'])->name('inbox.unarchive');
        Route::post('/inbox/{conversation}/notes', [InboxController::class, 'note'])->name('inbox.notes.store');
        Route::put('/inbox/{conversation}/notes/{note}', [InboxController::class, 'updateNote'])->name('inbox.notes.update');
        Route::post('/inbox/{conversation}/tags', [InboxController::class, 'tag'])->name('inbox.tags.store');
        Route::delete('/inbox/{conversation}/tags/{tag}', [InboxController::class, 'removeTag'])->name('inbox.tags.destroy');
        Route::post('/inbox/{conversation}/do-not-contact', [InboxController::class, 'doNotContact'])->name('inbox.do-not-contact');
        Route::post('/inbox/{conversation}/associate-contact', [InboxController::class, 'associateContact'])->name('inbox.associate-contact');
        Route::post('/inbox/{conversation}/associate-contact/new', [InboxController::class, 'createAndAssociateContact'])->name('inbox.associate-contact.create');

        // Antes da rota de detalhe: `settings` seria capturado como {state}.
        Route::get('/conversation-automation/settings', [ConversationAutomationSettingsController::class, 'edit'])->name('conversation-automation.settings.edit');
        Route::put('/conversation-automation/settings', [ConversationAutomationSettingsController::class, 'update'])->name('conversation-automation.settings.update');
        Route::put('/conversation-automation/settings/limiares', [ConversationAutomationSettingsController::class, 'updateThresholds'])->name('conversation-automation.settings.thresholds');

        Route::get('/conversation-automation', [ConversationFlowStateController::class, 'index'])->name('conversation-automation.index');
        Route::get('/conversation-automation/{state}', [ConversationFlowStateController::class, 'show'])->name('conversation-automation.show');
        Route::post('/conversation-automation/{state}/pause', [ConversationFlowStateController::class, 'pause'])->name('conversation-automation.pause');
        Route::post('/conversation-automation/{state}/resume', [ConversationFlowStateController::class, 'resume'])->name('conversation-automation.resume');
        Route::post('/conversation-automation/{state}/finish', [ConversationFlowStateController::class, 'finish'])->name('conversation-automation.finish');
        Route::post('/conversation-automation/{state}/take-over', [ConversationFlowStateController::class, 'takeOver'])->name('conversation-automation.take-over');

        Route::get('/conversation-flows/{conversationFlow}/questions/create', [ConversationFlowQuestionController::class, 'create'])->name('conversation-flows.questions.create');
        Route::post('/conversation-flows/{conversationFlow}/questions', [ConversationFlowQuestionController::class, 'store'])->name('conversation-flows.questions.store');
        Route::get('/conversation-flows/{conversationFlow}/questions/{question}/edit', [ConversationFlowQuestionController::class, 'edit'])->name('conversation-flows.questions.edit');
        Route::put('/conversation-flows/{conversationFlow}/questions/{question}', [ConversationFlowQuestionController::class, 'update'])->name('conversation-flows.questions.update');
        Route::delete('/conversation-flows/{conversationFlow}/questions/{question}', [ConversationFlowQuestionController::class, 'destroy'])->name('conversation-flows.questions.destroy');
        Route::resource('conversation-flows', ConversationFlowController::class)->parameters(['conversation-flows' => 'conversationFlow']);

        // Perfis antes da fila: sem isto, `profiles` seria capturado como
        // parâmetro pela rota de detalhe da fila, se ela ganhar uma um dia.
        Route::post('/inbound-attendance/toggle', [InboundAttendanceProfileController::class, 'toggle'])->name('inbound-attendance.toggle');
        Route::put('/inbound-attendance/exclusions', [InboundAttendanceProfileController::class, 'updateExclusions'])->name('inbound-attendance.exclusions');
        Route::get('/inbound-attendance/profiles', [InboundAttendanceProfileController::class, 'index'])->name('inbound-attendance.profiles.index');
        Route::get('/inbound-attendance/profiles/create', [InboundAttendanceProfileController::class, 'create'])->name('inbound-attendance.profiles.create');
        Route::post('/inbound-attendance/profiles', [InboundAttendanceProfileController::class, 'store'])->name('inbound-attendance.profiles.store');
        Route::get('/inbound-attendance/profiles/{profile}/edit', [InboundAttendanceProfileController::class, 'edit'])->name('inbound-attendance.profiles.edit');
        Route::put('/inbound-attendance/profiles/{profile}', [InboundAttendanceProfileController::class, 'update'])->name('inbound-attendance.profiles.update');
        Route::delete('/inbound-attendance/profiles/{profile}', [InboundAttendanceProfileController::class, 'destroy'])->name('inbound-attendance.profiles.destroy');

        Route::get('/inbound-attendance', [InboundAttendanceQueueController::class, 'index'])->name('inbound-attendance.index');
        Route::post('/inbound-attendance/start', [InboundAttendanceQueueController::class, 'start'])->name('inbound-attendance.start');
        Route::post('/inbound-attendance/{conversation}/ignore', [InboundAttendanceQueueController::class, 'ignore'])->name('inbound-attendance.ignore');

        Route::get('/ai-insights', [ConversationInsightController::class, 'index'])->name('ai-insights.index');
        Route::get('/ai-insights/{insight}', [ConversationInsightController::class, 'show'])->name('ai-insights.show');
        Route::put('/ai-insights/{insight}', [ConversationInsightController::class, 'correct'])->name('ai-insights.correct');
        Route::post('/ai-insights/{insight}/approve', [ConversationInsightController::class, 'approve'])->name('ai-insights.approve');
        Route::post('/ai-insights/{insight}/reprocess', [ConversationInsightController::class, 'reprocess'])->name('ai-insights.reprocess');
        Route::get('/ai-monitoring', [AiMonitoringController::class, 'index'])->name('ai-monitoring.index');

        // Etapa 9E-0: provedor de IA configurado pela tela. Fora do grupo de
        // configurações gerais porque aqui se manipula credencial.
        Route::get('/ai-provider', [AiProviderController::class, 'edit'])->name('ai-provider.edit');
        Route::put('/ai-provider', [AiProviderController::class, 'update'])->name('ai-provider.update');
        Route::post('/ai-provider/test', [AiProviderController::class, 'test'])->name('ai-provider.test');

        // Etapa 9E: relatórios analíticos. Somente leitura; nenhuma rota aqui
        // envia mensagem, altera conversa ou liga qualquer automação.
        Route::get('/analytics', [AnalyticsController::class, 'dashboard'])->name('analytics.dashboard');
        Route::get('/analytics/temas', [AnalyticsController::class, 'topics'])->name('analytics.topics');
        Route::get('/analytics/geografia', [AnalyticsController::class, 'geography'])->name('analytics.geography');
        Route::get('/analytics/demandas', [AnalyticsController::class, 'demands'])->name('analytics.demands');
        Route::get('/analytics/qualidade-ia', [AnalyticsController::class, 'aiQuality'])->name('analytics.ai-quality');
        Route::get('/analytics/perguntas', [AnalyticsController::class, 'questions'])->name('analytics.questions');
        Route::get('/analytics/governanca', [AnalyticsController::class, 'governance'])->name('analytics.governance');
        Route::post('/analytics/exportar', [AnalyticsExportController::class, 'store'])->name('analytics.export');

        Route::get('/reply-suggestions', [ReplySuggestionController::class, 'index'])->name('reply-suggestions.index');
        // Antes da rota de detalhe: `descartar-obsoletas` seria capturado como
        // identificador de sugestão.
        Route::post('/reply-suggestions/descartar-obsoletas', [ReplySuggestionController::class, 'discardStale'])->name('reply-suggestions.discard-stale');
        Route::post('/reply-suggestions/aprovar-todas', [ReplySuggestionController::class, 'approveAll'])->name('reply-suggestions.approve-all');
        Route::get('/reply-suggestions/{suggestion}', [ReplySuggestionController::class, 'show'])->name('reply-suggestions.show');
        Route::post('/reply-suggestions/{suggestion}/approve', [ReplySuggestionController::class, 'approve'])->name('reply-suggestions.approve');
        Route::post('/reply-suggestions/{suggestion}/reject', [ReplySuggestionController::class, 'reject'])->name('reply-suggestions.reject');
        Route::post('/reply-suggestions/{suggestion}/regenerate', [ReplySuggestionController::class, 'regenerate'])->name('reply-suggestions.regenerate');
        Route::post('/reply-suggestions/{suggestion}/take-over', [ReplySuggestionController::class, 'takeOver'])->name('reply-suggestions.take-over');
        Route::post('/reply-suggestions/{suggestion}/feedback', [ReplySuggestionController::class, 'feedback'])->name('reply-suggestions.feedback');
        // Etapa 9D: base oficial e aprovada.
        Route::get('/knowledge/test', [KnowledgeTestController::class, 'index'])->name('knowledge.test');
        Route::get('/knowledge/bases', [KnowledgeBaseController::class, 'index'])->name('knowledge.bases.index');
        // Antes de `/knowledge/bases/{base}`, senão "export" seria lido como
        // identificador de base.
        Route::get('/knowledge/bases/export', [KnowledgeBaseController::class, 'export'])->name('knowledge.bases.export');
        Route::get('/knowledge/bases/importar', [KnowledgeBaseController::class, 'import'])->name('knowledge.bases.import');
        Route::post('/knowledge/bases/importar', [KnowledgeBaseController::class, 'importPreview'])->name('knowledge.bases.import.preview');
        Route::post('/knowledge/bases/importar/confirmar', [KnowledgeBaseController::class, 'importConfirm'])->name('knowledge.bases.import.confirm');
        Route::get('/knowledge/bases/create', [KnowledgeBaseController::class, 'create'])->name('knowledge.bases.create');
        Route::post('/knowledge/bases', [KnowledgeBaseController::class, 'store'])->name('knowledge.bases.store');
        Route::get('/knowledge/bases/{base}', [KnowledgeBaseController::class, 'show'])->name('knowledge.bases.show');
        Route::get('/knowledge/bases/{base}/edit', [KnowledgeBaseController::class, 'edit'])->name('knowledge.bases.edit');
        Route::put('/knowledge/bases/{base}', [KnowledgeBaseController::class, 'update'])->name('knowledge.bases.update');
        Route::post('/knowledge/bases/{base}/status', [KnowledgeBaseController::class, 'status'])->name('knowledge.bases.status');
        Route::get('/knowledge/bases/{base}/documents/create', [KnowledgeDocumentController::class, 'create'])->name('knowledge.documents.create');
        Route::post('/knowledge/bases/{base}/documents', [KnowledgeDocumentController::class, 'store'])->name('knowledge.documents.store');
        Route::get('/knowledge/bases/{base}/documents/{document}', [KnowledgeDocumentController::class, 'show'])->name('knowledge.documents.show');
        Route::get('/knowledge/bases/{base}/documents/{document}/download', [KnowledgeDocumentController::class, 'download'])->name('knowledge.documents.download');
        Route::post('/knowledge/bases/{base}/documents/{document}/approve', [KnowledgeDocumentController::class, 'approve'])->name('knowledge.documents.approve');
        Route::post('/knowledge/bases/{base}/documents/{document}/reject', [KnowledgeDocumentController::class, 'reject'])->name('knowledge.documents.reject');
        Route::post('/knowledge/bases/{base}/documents/{document}/obsolete', [KnowledgeDocumentController::class, 'obsolete'])->name('knowledge.documents.obsolete');
        Route::post('/knowledge/bases/{base}/documents/{document}/reprocess', [KnowledgeDocumentController::class, 'reprocess'])->name('knowledge.documents.reprocess');
        Route::delete('/knowledge/bases/{base}/documents/{document}', [KnowledgeDocumentController::class, 'destroy'])->name('knowledge.documents.destroy');

        Route::get('/insight-topics/export', [InsightTopicController::class, 'export'])->name('insight-topics.export');
        Route::get('/insight-topics/importar', [InsightTopicController::class, 'import'])->name('insight-topics.import');
        Route::post('/insight-topics/importar', [InsightTopicController::class, 'importPreview'])->name('insight-topics.import.preview');
        Route::post('/insight-topics/importar/confirmar', [InsightTopicController::class, 'importConfirm'])->name('insight-topics.import.confirm');
        Route::resource('insight-topics', InsightTopicController::class)
            ->except('show')
            ->parameters(['insight-topics' => 'insightTopic']);

        Route::get('/histories/messages', [MessageHistoryController::class, 'index'])->name('histories.messages.index');
        Route::get('/histories/messages/{recipient}', [MessageHistoryController::class, 'show'])->name('histories.messages.show');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/batches', [ReportController::class, 'batches'])->name('reports.batches');
        Route::get('/reports/messages', [ReportController::class, 'messages'])->name('reports.messages');
        Route::get('/reports/errors', [ReportController::class, 'errors'])->name('reports.errors');
        Route::get('/reports/not-sent', [ReportController::class, 'notSent'])->name('reports.not-sent');
        Route::get('/reports/attempts', [ReportController::class, 'attempts'])->name('reports.attempts');
        Route::get('/reports/rate-limits', [ReportController::class, 'rateLimits'])->name('reports.rate-limits');
        Route::get('/reports/contacts', [ReportController::class, 'contacts'])->name('reports.contacts');
        Route::get('/reports/templates', [ReportController::class, 'templates'])->name('reports.templates');
        Route::get('/reports/conversations', [ReportController::class, 'conversations'])->name('reports.conversations');
        Route::post('/reports/export', [ReportExportController::class, 'store'])->name('reports.export');
        Route::get('/report-exports', [ReportExportController::class, 'index'])->name('report-exports.index');
        Route::get('/report-exports/{export}', [ReportExportController::class, 'show'])->name('report-exports.show');
        Route::get('/report-exports/{export}/download', [ReportExportController::class, 'download'])->name('report-exports.download');
        Route::post('/report-exports/{export}/retry', [ReportExportController::class, 'retry'])->name('report-exports.retry');
        Route::delete('/report-exports/{export}', [ReportExportController::class, 'destroy'])->name('report-exports.destroy');

        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::post('/monitoring/run', [MonitoringController::class, 'run'])->name('monitoring.run');
        Route::get('/monitoring/failed-jobs', [MonitoringController::class, 'failedJobs'])->name('monitoring.failed-jobs');
        Route::delete('/monitoring/failed-jobs/{job}', [MonitoringController::class, 'deleteFailedJob'])->name('monitoring.failed-jobs.destroy');

        Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('/maintenance/sync-counters', [MaintenanceController::class, 'syncCounters'])->name('maintenance.sync-counters');
        Route::post('/maintenance/find-inconsistencies', [MaintenanceController::class, 'findInconsistencies'])->name('maintenance.find-inconsistencies');
        Route::post('/maintenance/recover-stuck', [MaintenanceController::class, 'recoverStuck'])->name('maintenance.recover-stuck');
        Route::post('/maintenance/cleanup', [MaintenanceController::class, 'cleanup'])->name('maintenance.cleanup');
        Route::post('/maintenance/apply-retention', [MaintenanceController::class, 'applyRetention'])->name('maintenance.apply-retention');

        Route::get('/whatsapp/meta', [MetaSettingsController::class, 'edit'])->name('whatsapp.meta-settings');
        Route::put('/whatsapp/meta', [MetaSettingsController::class, 'update'])->name('whatsapp.meta-settings.update');
        Route::get('/whatsapp/connection', [WhatsAppConnectionController::class, 'show'])->name('whatsapp.connection');
        Route::post('/whatsapp/connection/connect', [WhatsAppConnectionController::class, 'connect'])->name('whatsapp.connect');
        Route::post('/whatsapp/connection/qrcode', [WhatsAppConnectionController::class, 'qrCode'])->name('whatsapp.qrcode');
        Route::post('/whatsapp/connection/status', [WhatsAppConnectionController::class, 'refreshStatus'])->name('whatsapp.status');
        Route::post('/whatsapp/connection/reconnect', [WhatsAppConnectionController::class, 'reconnect'])->name('whatsapp.reconnect');
        Route::post('/whatsapp/connection/disconnect', [WhatsAppConnectionController::class, 'disconnect'])->name('whatsapp.disconnect');
        Route::delete('/whatsapp/connection/session', [WhatsAppConnectionController::class, 'clearSession'])->name('whatsapp.session.clear');
        Route::post('/whatsapp/test-message', [WhatsAppConnectionController::class, 'sendTestMessage'])->name('whatsapp.test-message');
        Route::get('/whatsapp/events', [WhatsAppEventController::class, 'index'])->name('whatsapp.events');

        Route::post('/message-templates/preview', [MessageTemplateController::class, 'preview'])->name('message-templates.preview');
        Route::post('/message-templates/{message_template}/duplicate', [MessageTemplateController::class, 'duplicate'])->name('message-templates.duplicate');
        Route::patch('/message-templates/{message_template}/status', [MessageTemplateController::class, 'status'])->name('message-templates.status');
        Route::patch('/message-templates/{message_template}/restore', [MessageTemplateController::class, 'restore'])->name('message-templates.restore');
        Route::resource('message-templates', MessageTemplateController::class);

        Route::post('/message-batches/{message_batch}/message', [MessageBatchController::class, 'update'])->name('message-batches.message');
        Route::post('/message-batches/{message_batch}/contacts', [MessageBatchController::class, 'update'])->name('message-batches.contacts');
        Route::post('/message-batches/{message_batch}/validate', [MessageBatchController::class, 'validateBatch'])->name('message-batches.validate');
        Route::post('/message-batches/{message_batch}/randomize', [MessageBatchController::class, 'randomize'])->name('message-batches.randomize');
        Route::post('/message-batches/{message_batch}/revalidate', [MessageBatchController::class, 'revalidate'])->name('message-batches.revalidate');
        Route::get('/message-batches/{message_batch}/preview', [MessageBatchController::class, 'show'])->name('message-batches.preview');
        Route::post('/message-batches/{message_batch}/prepare', [MessageBatchController::class, 'prepare'])->name('message-batches.prepare');
        Route::post('/message-batches/{message_batch}/duplicate', [MessageBatchController::class, 'duplicate'])->name('message-batches.duplicate');
        Route::post('/message-batches/{message_batch}/cancel', [MessageBatchController::class, 'cancel'])->name('message-batches.cancel');
        Route::get('/message-batches/{message_batch}/processing', [MessageProcessingController::class, 'show'])->name('message-batches.processing');
        Route::post('/message-batches/{message_batch}/start', [MessageProcessingController::class, 'start'])->name('message-batches.start');
        Route::post('/message-batches/{message_batch}/pause', [MessageProcessingController::class, 'pause'])->name('message-batches.pause');
        Route::post('/message-batches/{message_batch}/resume', [MessageProcessingController::class, 'resume'])->name('message-batches.resume');
        Route::post('/message-batches/{message_batch}/stop', [MessageProcessingController::class, 'stop'])->name('message-batches.stop');
        Route::post('/message-batches/{message_batch}/resume-stopped', [MessageProcessingController::class, 'resumeStopped'])->name('message-batches.resume-stopped');
        Route::post('/message-batches/{message_batch}/recipients/{recipient}/cancel', [MessageProcessingController::class, 'cancelRecipient'])->name('message-batches.recipients.cancel');
        Route::post('/message-batches/{message_batch}/recipients/{recipient}/uncancel', [MessageProcessingController::class, 'uncancelRecipient'])->name('message-batches.recipients.uncancel');
        Route::post('/message-batches/{message_batch}/recipients/{recipient}/retry', [MessageProcessingController::class, 'retryRecipient'])->name('message-batches.recipients.retry');
        Route::get('/message-batches/{message_batch}/recipients/{recipient}/attempts', [MessageProcessingController::class, 'attempts'])->name('message-batches.recipients.attempts');
        Route::get('/message-batches/{message_batch}/recipients', [MessageBatchController::class, 'recipients'])->name('message-batches.recipients');
        Route::get('/message-batches/{message_batch}/ineligible/export', [MessageBatchController::class, 'exportPreview'])->name('message-batches.ineligible.export');
        Route::resource('message-batches', MessageBatchController::class);

        Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
        Route::get('/contacts/{contact}/message-history', [MessageHistoryController::class, 'contact'])->name('contacts.message-history');
        Route::get('/contacts/import', [ContactImportController::class, 'create'])->name('contacts.import');
        Route::post('/contacts/import/upload', [ContactImportController::class, 'upload'])->name('contacts.import.upload');
        Route::get('/contacts/import/template', [ContactImportController::class, 'template'])->name('contacts.import.template');
        Route::post('/contacts/imports/{contactImport}/validate', [ContactImportController::class, 'validateImport'])->name('contacts.imports.validate');
        Route::post('/contacts/imports/{contactImport}/confirm', [ContactImportController::class, 'confirm'])->name('contacts.imports.confirm');
        Route::get('/contacts/imports', [ContactImportController::class, 'index'])->name('contacts.imports.index');
        Route::get('/contacts/imports/{contactImport}', [ContactImportController::class, 'show'])->name('contacts.imports.show');
        Route::post('/contacts/bulk/tags', [ContactBulkController::class, 'tags'])->name('contacts.bulk.tags');
        Route::post('/contacts/bulk/status', [ContactBulkController::class, 'status'])->name('contacts.bulk.status');
        Route::post('/contacts/bulk/do-not-contact', [ContactBulkController::class, 'doNotContact'])->name('contacts.bulk.do-not-contact');
        Route::delete('/contacts/bulk', [ContactBulkController::class, 'destroy'])->name('contacts.bulk.destroy');
        Route::patch('/contacts/{contact}/restore', [ContactController::class, 'restore'])->name('contacts.restore');
        Route::patch('/contacts/{contact}/status', [ContactController::class, 'status'])->name('contacts.status');
        Route::patch('/contacts/{contact}/do-not-contact', [ContactController::class, 'doNotContact'])->name('contacts.do-not-contact');
        Route::resource('contacts', ContactController::class);
        Route::resource('tags', TagController::class)->except(['show']);
    });
});
