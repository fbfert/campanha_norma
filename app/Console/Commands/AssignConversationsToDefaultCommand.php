<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

/**
 * Aplica o responsável padrão às conversas que já existem.
 *
 * O observador cuida das conversas novas. Este comando cuida do passado, que
 * o observador nunca veria.
 */
class AssignConversationsToDefaultCommand extends Command
{
    protected $signature = 'conversations:assign-default
        {--user= : Id do responsável; sem isso usa conversations.default_assignee_id}
        {--force : Reatribui também conversas que já têm outro responsável}';

    protected $description = 'Atribui as conversas existentes ao responsável padrão.';

    public function handle(SystemSettingService $settings, AuditLogger $audit): int
    {
        $id = (int) ($this->option('user') ?: $settings->get('conversations.default_assignee_id', 0));

        if ($id < 1) {
            $this->error('Nenhum responsável padrão definido. Preencha conversations.default_assignee_id ou use --user.');

            return self::FAILURE;
        }

        $usuario = User::query()->whereKey($id)->where('status', UserStatus::Active)->first();

        if (! $usuario) {
            $this->error("Usuário {$id} não existe ou não esta ativo.");

            return self::FAILURE;
        }

        $consulta = Conversation::query()->where(fn ($query) => $query->whereNull('assigned_user_id')->orWhere('assigned_user_id', '!=', $id));

        if (! $this->option('force')) {
            $consulta = Conversation::query()->whereNull('assigned_user_id');
        }

        $total = 0;

        $consulta->chunkById(200, function ($conversas) use ($id, &$total): void {
            foreach ($conversas as $conversa) {
                $conversa->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
                $conversa->forceFill(['assigned_user_id' => $id])->save();
                $conversa->assignments()->create([
                    'assigned_user_id' => $id,
                    'assigned_by' => null,
                    'assigned_at' => now(),
                    'reason' => 'Atribuição padrão aplicada em lote.',
                ]);
                $total++;
            }
        });

        $audit->log('conversation.bulk_assigned', 'Conversas atribuídas ao responsável padrão.', null, null, [
            'assigned_user_id' => $id,
            'total' => $total,
            'force' => (bool) $this->option('force'),
        ]);

        $this->info("{$total} conversa(s) atribuída(s) a {$usuario->name}.");

        return self::SUCCESS;
    }
}
