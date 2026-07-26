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
            'operador' => ['Operador', 'Operacao administrativa sem gestao sensivel.', [
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
