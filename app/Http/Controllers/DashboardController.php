<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Conversations\ConversationMetricsService;
use App\Services\MessageProcessing\SendingRateLimiterService;
use App\Services\MessageProcessing\SendingSettingsService;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MonitoringService $monitoring, SendingSettingsService $settings, SendingRateLimiterService $limits, ConversationMetricsService $conversationMetrics): View
    {
        abort_unless($request->user()->can('view-dashboard'), 403);

        $latestBatch = class_exists(MessageBatch::class) ? MessageBatch::query()->latest()->first() : null;
        $sendingSettings = $settings->current();
        $limitState = $limits->check($sendingSettings);
        $diagnostics = $request->user()->can('monitoring.view') ? $monitoring->diagnostics() : [];
        $inbox = $request->user()->can('inbox.view_metrics') ? $conversationMetrics->summary() : [];

        return view('dashboard.index', [
            'activeUsers' => User::query()->where('status', UserStatus::Active)->count(),
            'blockedUsers' => User::query()->where('status', UserStatus::Blocked)->count(),
            'administratorCount' => Role::query()->where('slug', 'administrador')->first()?->users()->where('status', UserStatus::Active->value)->count() ?? 0,
            'currentUser' => $request->user(),
            'environment' => app()->environment(),
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'totalContacts' => class_exists(Contact::class) ? Contact::query()->count() : 0,
            'activeContacts' => class_exists(Contact::class) ? Contact::query()->where('status', 'active')->count() : 0,
            'blockedContacts' => class_exists(Contact::class) ? Contact::query()->where('status', 'blocked')->count() : 0,
            'doNotContactContacts' => class_exists(Contact::class) ? Contact::query()->where('do_not_contact', true)->count() : 0,
            'contactsToday' => class_exists(Contact::class) ? Contact::query()->whereDate('created_at', today())->count() : 0,
            'contactsImportedMonth' => class_exists(Contact::class) ? Contact::query()->where('source', 'importacao')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count() : 0,
            'contactsWithoutCity' => class_exists(Contact::class) ? Contact::query()->where(fn ($query) => $query->whereNull('city')->orWhere('city', ''))->count() : 0,
            'contactsWithoutEmail' => class_exists(Contact::class) ? Contact::query()->where(fn ($query) => $query->whereNull('email')->orWhere('email', ''))->count() : 0,
            'whatsappConnection' => class_exists(WhatsAppConnection::class) ? WhatsAppConnection::query()->first() : null,
            'activeTemplates' => class_exists(MessageTemplate::class) ? MessageTemplate::query()->where('status', 'active')->count() : 0,
            'inactiveTemplates' => class_exists(MessageTemplate::class) ? MessageTemplate::query()->where('status', 'inactive')->count() : 0,
            'draftBatches' => class_exists(MessageBatch::class) ? MessageBatch::query()->where('status', 'draft')->count() : 0,
            'readyBatches' => class_exists(MessageBatch::class) ? MessageBatch::query()->where('status', 'ready')->count() : 0,
            'cancelledBatches' => class_exists(MessageBatch::class) ? MessageBatch::query()->where('status', 'cancelled')->count() : 0,
            'latestBatchEligible' => $latestBatch?->eligible_total ?? 0,
            'latestBatchExcluded' => $latestBatch?->ineligible_total ?? 0,
            'processingBatches' => class_exists(MessageBatch::class) ? MessageBatch::query()->where('status', 'processing')->count() : 0,
            'pausedBatches' => class_exists(MessageBatch::class) ? MessageBatch::query()->where('status', 'paused')->count() : 0,
            'pendingMessages' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->whereIn('processing_status', ['pending', 'queued', 'retry_wait', 'waiting_schedule', 'waiting_minute_limit', 'waiting_hour_limit', 'waiting_day_limit'])->count() : 0,
            'messagesSentToday' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->where('processing_status', 'sent')->whereDate('sent_at', today())->count() : 0,
            'messagesSentMonth' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->where('processing_status', 'sent')->whereMonth('sent_at', now()->month)->whereYear('sent_at', now()->year)->count() : 0,
            'sendFailuresToday' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->whereIn('processing_status', ['failed_temporary', 'failed_permanent'])->whereDate('failed_at', today())->count() : 0,
            'sendFailuresMonth' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->whereIn('processing_status', ['failed_temporary', 'failed_permanent'])->whereMonth('failed_at', now()->month)->whereYear('failed_at', now()->year)->count() : 0,
            'completedBatchesToday' => class_exists(MessageBatch::class) ? MessageBatch::query()->whereIn('status', ['completed', 'completed_with_errors'])->whereDate('completed_at', today())->count() : 0,
            'retryingMessages' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->where('processing_status', 'retry_wait')->count() : 0,
            'uncertainResults' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->where('error_code', 'SEND_RESULT_UNKNOWN')->count() : 0,
            'dailyLimitUsage' => ($limitState['counters']['day'] ?? 0).' / '.$sendingSettings->max_per_day,
            'workerStatus' => $diagnostics['workers']['status'] ?? null,
            'redisStatus' => $diagnostics['redis']['status'] ?? null,
            'schedulerStatus' => $diagnostics['scheduler']['status'] ?? null,
            'latestProcessingActivity' => class_exists(MessageSendAttempt::class) ? MessageSendAttempt::query()->latest('updated_at')->first() : null,
            'nextSendAt' => class_exists(MessageBatchRecipient::class) ? MessageBatchRecipient::query()->whereNotNull('retry_at')->whereIn('processing_status', ['retry_wait', 'waiting_schedule', 'waiting_minute_limit', 'waiting_hour_limit', 'waiting_day_limit'])->orderBy('retry_at')->value('retry_at') : null,
            'inboxMetrics' => $inbox,
        ]);
    }
}
