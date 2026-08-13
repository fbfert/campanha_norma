<?php

namespace Database\Seeders;

use App\Enums\PermissionSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(PermissionSlug::cases())->mapWithKeys(function (PermissionSlug $permission): array {
            return [
                $permission->value => Permission::query()->updateOrCreate(
                    ['slug' => $permission->value],
                    ['name' => $permission->label(), 'description' => null]
                ),
            ];
        });

        $roles = [
            'administrador' => ['Administrador', 'Acesso completo ao sistema.', PermissionSlug::cases()],
            'operador' => ['Operador', 'Operação administrativa sem gestão sensível.', [
                PermissionSlug::DashboardView,
                PermissionSlug::ProfileManage,
                PermissionSlug::ContactsView,
                PermissionSlug::ContactsCreate,
                PermissionSlug::ContactsUpdate,
                PermissionSlug::ContactsDelete,
                PermissionSlug::ContactsExport,
                PermissionSlug::ContactsImport,
                PermissionSlug::ContactsManageTags,
                PermissionSlug::ContactsMarkDoNotContact,
                PermissionSlug::ContactsViewSensitiveData,
                PermissionSlug::WhatsAppConnectionView,
                PermissionSlug::MessageTemplatesView,
                PermissionSlug::MessageTemplatesCreate,
                PermissionSlug::MessageTemplatesUpdate,
                PermissionSlug::MessageBatchesView,
                PermissionSlug::MessageBatchesCreate,
                PermissionSlug::MessageBatchesUpdate,
                PermissionSlug::MessageBatchesCancel,
                PermissionSlug::MessageBatchesViewRecipients,
                PermissionSlug::MessageBatchesExportPreview,
                PermissionSlug::MessageProcessingView,
                PermissionSlug::MessageProcessingStart,
                PermissionSlug::MessageProcessingPause,
                PermissionSlug::MessageProcessingResume,
                PermissionSlug::MessageProcessingStop,
                PermissionSlug::MessageProcessingCancelRecipient,
                PermissionSlug::MessageProcessingRetry,
                PermissionSlug::MessageProcessingViewAttempts,
                PermissionSlug::HistoriesView,
                PermissionSlug::HistoriesViewAttempts,
                PermissionSlug::HistoriesExport,
                PermissionSlug::ReportsView,
                PermissionSlug::ReportsExport,
                PermissionSlug::ReportsViewContactData,
                PermissionSlug::ReportsViewOperationalMetrics,
                PermissionSlug::MonitoringView,
                PermissionSlug::MaintenanceRetryEligible,
                PermissionSlug::InboxView,
                PermissionSlug::InboxViewMessageContent,
                PermissionSlug::InboxReply,
                PermissionSlug::InboxAssign,
                PermissionSlug::InboxChangeStatus,
                PermissionSlug::InboxChangePriority,
                PermissionSlug::InboxManageTags,
                PermissionSlug::InboxAddNotes,
                PermissionSlug::InboxArchive,
                PermissionSlug::InboxAssociateContact,
                PermissionSlug::InboxViewMetrics,
                PermissionSlug::InboxSync,
                PermissionSlug::ConversationAutomationView,
                PermissionSlug::ConversationAutomationControl,
                // Operador vê a fila e inicia conversa, que é operação. Editar
                // o perfil decide o texto que sai para todo mundo sem ninguém
                // ler antes, e isso fica com quem responde por ele.
                PermissionSlug::InboundAttendanceView,
                PermissionSlug::InboundAttendanceStart,
                PermissionSlug::AiInsightsView,
                PermissionSlug::AiInsightsCorrect,
                PermissionSlug::ReplySuggestionsView,
                PermissionSlug::ReplySuggestionsReject,
                PermissionSlug::ReplySuggestionsFeedback,
                // Operador prepara a base, mas não aprova: publicar conteúdo
                // oficial e ato de responsabilidade, não de operação.
                PermissionSlug::KnowledgeView,
                PermissionSlug::KnowledgeUploadDocuments,
                PermissionSlug::KnowledgeTestRetrieval,
                // Etapa 9E. Operador le agregado e conteúdo, porque atende e
                // precisa entender o que foi dito. Não recebe identificação
                // nem exportação detalhada: ler no sistema e levar uma
                // planilha com o que as pessoas escreveram são coisas
                // diferentes.
                PermissionSlug::AnalyticsViewAggregates,
                PermissionSlug::AnalyticsViewContent,
                PermissionSlug::AnalyticsExportAggregates,
            ]],
            'consulta' => ['Consulta', 'Acesso somente para consulta.', [
                PermissionSlug::DashboardView,
                PermissionSlug::ProfileManage,
                PermissionSlug::ContactsView,
                PermissionSlug::ContactsExport,
                PermissionSlug::MessageTemplatesView,
                PermissionSlug::MessageBatchesView,
                PermissionSlug::MessageBatchesViewRecipients,
                PermissionSlug::MessageProcessingView,
                PermissionSlug::HistoriesView,
                PermissionSlug::ReportsView,
                PermissionSlug::InboxView,
                PermissionSlug::ConversationAutomationView,
                PermissionSlug::InboundAttendanceView,
                PermissionSlug::AiInsightsView,
                PermissionSlug::ReplySuggestionsView,
                PermissionSlug::KnowledgeView,
                // Consulta ve número, nunca texto e nunca quem escreveu.
                PermissionSlug::AnalyticsViewAggregates,
            ]],
        ];

        foreach ($roles as $slug => [$name, $description, $rolePermissions]) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description]
            );

            $role->permissions()->sync(collect($rolePermissions)->map(fn (PermissionSlug $permission) => $permissions[$permission->value]->id));
        }
    }
}
