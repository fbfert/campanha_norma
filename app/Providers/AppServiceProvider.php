<?php

namespace App\Providers;

use App\Contracts\AnswerGroundingValidator;
use App\Contracts\AudioTranscriber;
use App\Contracts\ConversationResponseGenerator;
use App\Contracts\EmbeddingProvider;
use App\Contracts\KnowledgeBaseProvider;
use App\Contracts\KnowledgeRetriever;
use App\Events\ConversationMessageEvaluated;
use App\Listeners\DispatchConversationInterpretation;
use App\Listeners\DispatchConversationReplyGeneration;
use App\Models\Conversation;
use App\Models\User;
use App\Observers\ConversationObserver;
use App\Services\Ai\AiProviderSettings;
use App\Services\Ai\Providers\OpenAiCompatibleTranscriber;
use App\Services\Knowledge\GroundingValidator;
use App\Services\Knowledge\KnowledgeProviderManager;
use App\Services\Knowledge\LocalKnowledgeRetriever;
use App\Services\ResponseGeneration\AiConversationResponseGenerator;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Etapa 9C: o gerador de resposta e resolvido por contrato, para que a
        // troca de implementação não exija tocar em nenhum serviço.
        $this->app->bind(ConversationResponseGenerator::class, AiConversationResponseGenerator::class);

        // Etapa 9D: os quatro contratos de conhecimento são resolvidos aqui. Os
        // provedores passam pelo manager para que a troca seja uma mudança de
        // configuração, e não de código.
        $this->app->bind(KnowledgeRetriever::class, LocalKnowledgeRetriever::class);
        $this->app->bind(AnswerGroundingValidator::class, GroundingValidator::class);
        $this->app->bind(KnowledgeBaseProvider::class, fn ($app) => $app->make(KnowledgeProviderManager::class)->provider());
        $this->app->bind(EmbeddingProvider::class, fn ($app) => $app->make(KnowledgeProviderManager::class)->embeddings());
        $this->app->bind(AudioTranscriber::class, OpenAiCompatibleTranscriber::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Conversation::observe(ConversationObserver::class);

        Gate::before(function (User $user): ?bool {
            return $user->hasRole('administrador') ? true : null;
        });

        Gate::define('view-dashboard', fn (User $user): bool => $user->hasPermission('dashboard.view'));
        Gate::define('view-users', fn (User $user): bool => $user->hasPermission('users.view'));
        Gate::define('manage-users', fn (User $user): bool => $user->hasPermission('users.manage'));
        Gate::define('view-settings', fn (User $user): bool => $user->hasPermission('settings.view'));
        Gate::define('manage-settings', fn (User $user): bool => $user->hasPermission('settings.manage'));
        Gate::define('view-audit', fn (User $user): bool => $user->hasPermission('audit.view'));
        Gate::define('manage-profile', fn (User $user): bool => $user->hasPermission('profile.manage'));
        Gate::define('contacts.view', fn (User $user): bool => $user->hasPermission('contacts.view'));
        Gate::define('contacts.create', fn (User $user): bool => $user->hasPermission('contacts.create'));
        Gate::define('contacts.update', fn (User $user): bool => $user->hasPermission('contacts.update'));
        Gate::define('contacts.delete', fn (User $user): bool => $user->hasPermission('contacts.delete'));
        Gate::define('contacts.restore', fn (User $user): bool => $user->hasPermission('contacts.restore'));
        Gate::define('contacts.export', fn (User $user): bool => $user->hasPermission('contacts.export'));
        Gate::define('contacts.import', fn (User $user): bool => $user->hasPermission('contacts.import'));
        Gate::define('contacts.manage_tags', fn (User $user): bool => $user->hasPermission('contacts.manage_tags'));
        Gate::define('contacts.mark_do_not_contact', fn (User $user): bool => $user->hasPermission('contacts.mark_do_not_contact'));
        Gate::define('contacts.view_sensitive_data', fn (User $user): bool => $user->hasPermission('contacts.view_sensitive_data'));
        Gate::define('whatsapp.connection.view', fn (User $user): bool => $user->hasPermission('whatsapp.connection.view'));
        Gate::define('whatsapp.connection.manage', fn (User $user): bool => $user->hasPermission('whatsapp.connection.manage'));
        Gate::define('whatsapp.connection.disconnect', fn (User $user): bool => $user->hasPermission('whatsapp.connection.disconnect'));
        Gate::define('whatsapp.connection.clear_session', fn (User $user): bool => $user->hasPermission('whatsapp.connection.clear_session'));
        Gate::define('whatsapp.test_message.send', fn (User $user): bool => $user->hasPermission('whatsapp.test_message.send'));
        Gate::define('whatsapp.events.view', fn (User $user): bool => $user->hasPermission('whatsapp.events.view'));
        Gate::define('message_templates.view', fn (User $user): bool => $user->hasPermission('message_templates.view'));
        Gate::define('message_templates.create', fn (User $user): bool => $user->hasPermission('message_templates.create'));
        Gate::define('message_templates.update', fn (User $user): bool => $user->hasPermission('message_templates.update'));
        Gate::define('message_templates.delete', fn (User $user): bool => $user->hasPermission('message_templates.delete'));
        Gate::define('message_templates.restore', fn (User $user): bool => $user->hasPermission('message_templates.restore'));
        Gate::define('message_templates.duplicate', fn (User $user): bool => $user->hasPermission('message_templates.duplicate'));
        Gate::define('message_batches.view', fn (User $user): bool => $user->hasPermission('message_batches.view'));
        Gate::define('message_batches.create', fn (User $user): bool => $user->hasPermission('message_batches.create'));
        Gate::define('message_batches.update', fn (User $user): bool => $user->hasPermission('message_batches.update'));
        Gate::define('message_batches.cancel', fn (User $user): bool => $user->hasPermission('message_batches.cancel'));
        Gate::define('message_batches.duplicate', fn (User $user): bool => $user->hasPermission('message_batches.duplicate'));
        Gate::define('message_batches.view_recipients', fn (User $user): bool => $user->hasPermission('message_batches.view_recipients'));
        Gate::define('message_batches.export_preview', fn (User $user): bool => $user->hasPermission('message_batches.export_preview'));
        Gate::define('message_processing.view', fn (User $user): bool => $user->hasPermission('message_processing.view'));
        Gate::define('message_processing.start', fn (User $user): bool => $user->hasPermission('message_processing.start'));
        Gate::define('message_processing.pause', fn (User $user): bool => $user->hasPermission('message_processing.pause'));
        Gate::define('message_processing.resume', fn (User $user): bool => $user->hasPermission('message_processing.resume'));
        Gate::define('message_processing.stop', fn (User $user): bool => $user->hasPermission('message_processing.stop'));
        Gate::define('message_processing.cancel_recipient', fn (User $user): bool => $user->hasPermission('message_processing.cancel_recipient'));
        Gate::define('message_processing.retry', fn (User $user): bool => $user->hasPermission('message_processing.retry'));
        Gate::define('message_processing.view_attempts', fn (User $user): bool => $user->hasPermission('message_processing.view_attempts'));
        Gate::define('message_processing.manage_settings', fn (User $user): bool => $user->hasPermission('message_processing.manage_settings'));
        Gate::define('message_processing.run_maintenance', fn (User $user): bool => $user->hasPermission('message_processing.run_maintenance'));
        Gate::define('histories.view', fn (User $user): bool => $user->hasPermission('histories.view'));
        Gate::define('histories.view_message_content', fn (User $user): bool => $user->hasPermission('histories.view_message_content'));
        Gate::define('histories.view_attempts', fn (User $user): bool => $user->hasPermission('histories.view_attempts'));
        Gate::define('histories.view_technical_details', fn (User $user): bool => $user->hasPermission('histories.view_technical_details'));
        Gate::define('histories.export', fn (User $user): bool => $user->hasPermission('histories.export'));
        Gate::define('reports.view', fn (User $user): bool => $user->hasPermission('reports.view'));
        Gate::define('reports.export', fn (User $user): bool => $user->hasPermission('reports.export'));
        Gate::define('reports.view_contact_data', fn (User $user): bool => $user->hasPermission('reports.view_contact_data'));
        Gate::define('reports.view_operational_metrics', fn (User $user): bool => $user->hasPermission('reports.view_operational_metrics'));
        Gate::define('monitoring.view', fn (User $user): bool => $user->hasPermission('monitoring.view'));
        Gate::define('monitoring.view_sensitive_details', fn (User $user): bool => $user->hasPermission('monitoring.view_sensitive_details'));
        Gate::define('monitoring.run_diagnostics', fn (User $user): bool => $user->hasPermission('monitoring.run_diagnostics'));
        Gate::define('maintenance.view', fn (User $user): bool => $user->hasPermission('maintenance.view'));
        Gate::define('maintenance.sync_counters', fn (User $user): bool => $user->hasPermission('maintenance.sync_counters'));
        Gate::define('maintenance.recover_stuck', fn (User $user): bool => $user->hasPermission('maintenance.recover_stuck'));
        Gate::define('maintenance.retry_eligible', fn (User $user): bool => $user->hasPermission('maintenance.retry_eligible'));
        Gate::define('maintenance.cleanup_logs', fn (User $user): bool => $user->hasPermission('maintenance.cleanup_logs'));
        Gate::define('maintenance.apply_retention', fn (User $user): bool => $user->hasPermission('maintenance.apply_retention'));
        Gate::define('maintenance.run_commands', fn (User $user): bool => $user->hasPermission('maintenance.run_commands'));
        Gate::define('inbox.view', fn (User $user): bool => $user->hasPermission('inbox.view'));
        Gate::define('inbox.view_all', fn (User $user): bool => $user->hasPermission('inbox.view_all'));
        Gate::define('inbox.view_message_content', fn (User $user): bool => $user->hasPermission('inbox.view_message_content'));
        Gate::define('inbox.reply', fn (User $user): bool => $user->hasPermission('inbox.reply'));
        Gate::define('inbox.assign', fn (User $user): bool => $user->hasPermission('inbox.assign'));
        Gate::define('inbox.change_status', fn (User $user): bool => $user->hasPermission('inbox.change_status'));
        Gate::define('inbox.change_priority', fn (User $user): bool => $user->hasPermission('inbox.change_priority'));
        Gate::define('inbox.manage_tags', fn (User $user): bool => $user->hasPermission('inbox.manage_tags'));
        Gate::define('inbox.add_notes', fn (User $user): bool => $user->hasPermission('inbox.add_notes'));
        Gate::define('inbox.edit_notes', fn (User $user): bool => $user->hasPermission('inbox.edit_notes'));
        Gate::define('inbox.archive', fn (User $user): bool => $user->hasPermission('inbox.archive'));
        Gate::define('inbox.block', fn (User $user): bool => $user->hasPermission('inbox.block'));
        Gate::define('inbox.mark_do_not_contact', fn (User $user): bool => $user->hasPermission('inbox.mark_do_not_contact'));
        Gate::define('inbox.associate_contact', fn (User $user): bool => $user->hasPermission('inbox.associate_contact'));
        Gate::define('inbox.view_metrics', fn (User $user): bool => $user->hasPermission('inbox.view_metrics'));
        Gate::define('inbox.sync', fn (User $user): bool => $user->hasPermission('inbox.sync'));
        Gate::define('conversation_automation.view', fn (User $user): bool => $user->hasPermission('conversation_automation.view'));
        Gate::define('conversation_automation.manage_flows', fn (User $user): bool => $user->hasPermission('conversation_automation.manage_flows'));
        Gate::define('conversation_automation.manage_questions', fn (User $user): bool => $user->hasPermission('conversation_automation.manage_questions'));
        Gate::define('conversation_automation.control', fn (User $user): bool => $user->hasPermission('conversation_automation.control'));
        Gate::define('ai_insights.view', fn (User $user): bool => $user->hasPermission('ai_insights.view'));
        Gate::define('ai_insights.view_contact_data', fn (User $user): bool => $user->hasPermission('ai_insights.view_contact_data'));
        Gate::define('ai_insights.correct', fn (User $user): bool => $user->hasPermission('ai_insights.correct'));
        Gate::define('ai_insights.reprocess', fn (User $user): bool => $user->hasPermission('ai_insights.reprocess'));
        Gate::define('ai_insights.manage_taxonomy', fn (User $user): bool => $user->hasPermission('ai_insights.manage_taxonomy'));
        Gate::define('ai_insights.view_monitoring', fn (User $user): bool => $user->hasPermission('ai_insights.view_monitoring'));
        Gate::define('reply_suggestions.view', fn (User $user): bool => $user->hasPermission('reply_suggestions.view'));
        Gate::define('reply_suggestions.approve', fn (User $user): bool => $user->hasPermission('reply_suggestions.approve'));
        Gate::define('reply_suggestions.reject', fn (User $user): bool => $user->hasPermission('reply_suggestions.reject'));
        Gate::define('reply_suggestions.regenerate', fn (User $user): bool => $user->hasPermission('reply_suggestions.regenerate'));
        Gate::define('reply_suggestions.feedback', fn (User $user): bool => $user->hasPermission('reply_suggestions.feedback'));
        Gate::define('reply_suggestions.manage_settings', fn (User $user): bool => $user->hasPermission('reply_suggestions.manage_settings'));
        Gate::define('knowledge.view', fn (User $user): bool => $user->hasPermission('knowledge.view'));
        Gate::define('knowledge.manage_bases', fn (User $user): bool => $user->hasPermission('knowledge.manage_bases'));
        Gate::define('knowledge.upload_documents', fn (User $user): bool => $user->hasPermission('knowledge.upload_documents'));
        Gate::define('knowledge.approve_documents', fn (User $user): bool => $user->hasPermission('knowledge.approve_documents'));
        Gate::define('knowledge.delete_documents', fn (User $user): bool => $user->hasPermission('knowledge.delete_documents'));
        Gate::define('knowledge.download_documents', fn (User $user): bool => $user->hasPermission('knowledge.download_documents'));
        Gate::define('knowledge.test_retrieval', fn (User $user): bool => $user->hasPermission('knowledge.test_retrieval'));
        Gate::define('knowledge.manage_settings', fn (User $user): bool => $user->hasPermission('knowledge.manage_settings'));
        Gate::define('ai.provider.manage', fn (User $user): bool => $user->hasPermission('ai.provider.manage'));
        Gate::define('analytics.view_aggregates', fn (User $user): bool => $user->hasPermission('analytics.view_aggregates'));
        Gate::define('analytics.view_content', fn (User $user): bool => $user->hasPermission('analytics.view_content'));
        Gate::define('analytics.view_identification', fn (User $user): bool => $user->hasPermission('analytics.view_identification'));
        Gate::define('analytics.export_aggregates', fn (User $user): bool => $user->hasPermission('analytics.export_aggregates'));
        Gate::define('analytics.export_detailed', fn (User $user): bool => $user->hasPermission('analytics.export_detailed'));
        Gate::define('analytics.view_costs', fn (User $user): bool => $user->hasPermission('analytics.view_costs'));
        Gate::define('analytics.view_governance', fn (User $user): bool => $user->hasPermission('analytics.view_governance'));

        // O provedor configurado pela tela sobrescreve o do arquivo de
        // ambiente. Fica dentro de try porque este boot também roda antes de a
        // tabela existir, na primeira migração: falhar ali impediria instalar o
        // sistema por causa de uma configuração que ainda não pode existir.
        try {
            app(AiProviderSettings::class)->applyToConfig();
        } catch (\Throwable) {
            // Sem banco disponível, vale o que estiver no `.env`.
        }

        // Etapa 9B: a interpretação observa o ponto de extensão da 9A sem que a
        // 9A precise conhecer a camada de IA.
        Event::listen(ConversationMessageEvaluated::class, DispatchConversationInterpretation::class);

        // Etapa 9C: geração de resposta observa o mesmo ponto de extensão.
        Event::listen(ConversationMessageEvaluated::class, DispatchConversationReplyGeneration::class);

        view()->composer('*', function ($view): void {
            $settings = app(SystemSettingService::class);
            $user = auth()->user();
            $unreadConversationsCount = 0;

            if ($user && $user->can('inbox.view')) {
                $unreadConversationsCount = Cache::remember("inbox:unread-menu-count:user:{$user->id}", 30, function () use ($user): int {
                    return Conversation::query()
                        ->where('unread_count', '>', 0)
                        ->when(! $user->can('inbox.view_all'), function ($query) use ($user): void {
                            $query->where(function ($query) use ($user): void {
                                $query->where('assigned_user_id', $user->id)->orWhereNull('assigned_user_id');
                            });
                        })
                        ->count();
                });
            }

            $view->with('systemName', $settings->get('system.name', config('app.name')));
            $view->with('dateFormat', $settings->get('system.date_format', 'd/m/Y'));
            $view->with('dateTimeFormat', $settings->get('system.datetime_format', 'd/m/Y H:i'));
            $view->with('unreadConversationsCount', $unreadConversationsCount);
        });
    }
}
