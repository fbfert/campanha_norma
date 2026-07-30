<?php

namespace App\Services\Monitoring;

use App\Enums\MonitoringHealthStatus;
use App\Models\ConversationMessage;
use App\Models\ConversationSyncRun;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\SchedulerHeartbeat;
use App\Models\WorkerHeartbeat;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class MonitoringService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function diagnostics(): array
    {
        return [
            'laravel' => $this->laravel(),
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queues' => $this->queues(),
            'workers' => $this->workers(),
            'scheduler' => $this->scheduler(),
            'node' => $this->node(),
            'storage' => $this->storage(),
            'stuck_messages' => $this->stuckMessages(),
            'conversation_sync' => $this->conversationSync(),
            'inconsistent_batches' => $this->inconsistentBatches(),
        ];
    }

    public function laravel(): array
    {
        return $this->item(MonitoringHealthStatus::Healthy, 'Aplicação Laravel respondendo.', [
            'environment' => app()->environment(),
            'debug' => config('app.debug') ? 'ativo' : 'inativo',
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    }

    public function database(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');

            return $this->item(MonitoringHealthStatus::Healthy, 'Banco de dados acessível.', ['duration_ms' => (int) ((microtime(true) - $start) * 1000)]);
        } catch (Throwable) {
            return $this->item(MonitoringHealthStatus::Critical, 'Banco de dados indisponível.');
        }
    }

    public function redis(): array
    {
        try {
            $start = microtime(true);
            $pong = Redis::connection()->ping();

            return $this->item(MonitoringHealthStatus::Healthy, 'Redis acessível.', ['response' => (string) $pong, 'duration_ms' => (int) ((microtime(true) - $start) * 1000)]);
        } catch (Throwable) {
            return $this->item(MonitoringHealthStatus::Critical, 'Redis indisponível ou extensão ausente.');
        }
    }

    public function queues(): array
    {
        $queues = ['whatsapp-messages', 'whatsapp-incoming', 'whatsapp-manual-replies', 'whatsapp-conversation-sync', 'whatsapp-maintenance'];
        $pending = DB::table('jobs')->whereIn('queue', $queues)->count();
        $byQueue = DB::table('jobs')->selectRaw('queue, count(*) as total')->whereIn('queue', $queues)->groupBy('queue')->pluck('total', 'queue')->all();
        $failed = DB::table('failed_jobs')->count();
        $critical = (int) $this->settings->get('monitoring.failed_jobs_critical', 10);
        $warning = (int) $this->settings->get('monitoring.failed_jobs_warning', 1);
        $status = $failed >= $critical ? MonitoringHealthStatus::Critical : ($failed >= $warning ? MonitoringHealthStatus::Warning : MonitoringHealthStatus::Healthy);

        return $this->item($status, 'Diagnostico das filas de mensagens.', ['pending' => $pending, 'by_queue' => $byQueue, 'failed' => $failed]);
    }

    public function workers(): array
    {
        $last = WorkerHeartbeat::query()->latest('last_heartbeat_at')->first();
        if (! $last) {
            return $this->item(MonitoringHealthStatus::Unknown, 'Nenhum heartbeat de worker registrado.');
        }

        $minutes = $last->last_heartbeat_at?->diffInMinutes(now()) ?? 9999;
        $status = $this->threshold($minutes, 'monitoring.worker_warning_minutes', 'monitoring.worker_critical_minutes');

        return $this->item($status, 'Último heartbeat de worker avaliado.', ['last_heartbeat_at' => $last->last_heartbeat_at?->format('d/m/Y H:i'), 'minutes' => $minutes]);
    }

    public function scheduler(): array
    {
        $last = SchedulerHeartbeat::query()->latest('last_run_at')->first();
        if (! $last) {
            return $this->item(MonitoringHealthStatus::Unknown, 'Nenhum heartbeat do Scheduler registrado.');
        }

        $minutes = $last->last_run_at?->diffInMinutes(now()) ?? 9999;
        $status = $this->threshold($minutes, 'monitoring.scheduler_warning_minutes', 'monitoring.scheduler_critical_minutes');

        return $this->item($status, 'Último heartbeat do Scheduler avaliado.', ['last_run_at' => $last->last_run_at?->format('d/m/Y H:i'), 'minutes' => $minutes]);
    }

    public function node(): array
    {
        try {
            $start = microtime(true);
            $response = Http::baseUrl((string) config('whatsapp.service.url'))->withToken((string) config('whatsapp.service.token'))->timeout(5)->get('/api/health');
            $status = $response->ok() ? MonitoringHealthStatus::Healthy : MonitoringHealthStatus::Critical;

            return $this->item($status, $response->ok() ? 'Serviço Node.js acessível.' : 'Serviço Node.js respondeu com erro.', ['duration_ms' => (int) ((microtime(true) - $start) * 1000)]);
        } catch (Throwable) {
            return $this->item(MonitoringHealthStatus::Critical, 'Serviço Node.js indisponível.');
        }
    }

    public function storage(): array
    {
        $path = storage_path('app/private');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        $usedPercent = $free && $total ? round((1 - ($free / $total)) * 100, 2) : null;
        $status = $usedPercent === null ? MonitoringHealthStatus::Unknown : ($usedPercent >= (int) $this->settings->get('monitoring.disk_critical_percent', 90) ? MonitoringHealthStatus::Critical : ($usedPercent >= (int) $this->settings->get('monitoring.disk_warning_percent', 80) ? MonitoringHealthStatus::Warning : MonitoringHealthStatus::Healthy));

        return $this->item($status, 'Armazenamento privado verificado.', ['path' => $path, 'used_percent' => $usedPercent]);
    }

    public function stuckMessages(): array
    {
        $minutes = (int) $this->settings->get('monitoring.stuck_message_minutes', 10);
        $count = MessageBatchRecipient::query()->where('processing_status', 'processing')->where('processing_started_at', '<=', now()->subMinutes($minutes))->count();
        $manual = ConversationMessage::query()->where('direction', 'outgoing')->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes($minutes))->count();

        return $this->item(($count + $manual) > 0 ? MonitoringHealthStatus::Warning : MonitoringHealthStatus::Healthy, 'Mensagens presas verificadas.', ['batch_count' => $count, 'manual_reply_count' => $manual]);
    }

    public function inconsistentBatches(): array
    {
        $count = MessageBatch::query()
            ->whereIn('status', ['completed', 'completed_with_errors'])
            ->whereHas('recipients', fn ($query) => $query->whereIn('processing_status', ['pending', 'queued', 'processing', 'retry_wait']))
            ->count();

        return $this->item($count > 0 ? MonitoringHealthStatus::Warning : MonitoringHealthStatus::Healthy, 'Lotes inconsistentes verificados.', ['count' => $count]);
    }

    public function conversationSync(): array
    {
        $active = ConversationSyncRun::query()->whereIn('status', ['pending', 'running'])->latest()->first();
        $last = ConversationSyncRun::query()->latest('finished_at')->latest('created_at')->first();

        if (! $active && ! $last) {
            return $this->item(MonitoringHealthStatus::Unknown, 'Nenhuma sincronização de conversas registrada.');
        }

        $stuck = $active && $active->last_heartbeat_at && $active->last_heartbeat_at->lt(now()->subMinutes(30));
        $status = $stuck ? MonitoringHealthStatus::Warning : (($last?->status?->value === 'failed') ? MonitoringHealthStatus::Warning : MonitoringHealthStatus::Healthy);

        return $this->item($status, $active ? 'Sincronização de conversas ativa.' : 'Última sincronização de conversas avaliada.', [
            'active_id' => $active?->id,
            'active_status' => $active?->status?->value,
            'last_id' => $last?->id,
            'last_status' => $last?->status?->value,
            'last_finished_at' => $last?->finished_at?->format('d/m/Y H:i'),
            'messages_imported' => $last?->messages_imported,
            'error_code' => $last?->error_code,
        ]);
    }

    private function threshold(int|float $minutes, string $warningKey, string $criticalKey): MonitoringHealthStatus
    {
        if ($minutes >= (int) $this->settings->get($criticalKey, 15)) {
            return MonitoringHealthStatus::Critical;
        }

        if ($minutes >= (int) $this->settings->get($warningKey, 5)) {
            return MonitoringHealthStatus::Warning;
        }

        return MonitoringHealthStatus::Healthy;
    }

    private function item(MonitoringHealthStatus $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }
}
