<?php

namespace Database\Factories;

use App\Enums\ConversationSyncStatus;
use App\Models\ConversationSyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationSyncRun> */
class ConversationSyncRunFactory extends Factory
{
    protected $model = ConversationSyncRun::class;

    public function definition(): array
    {
        return [
            'status' => ConversationSyncStatus::Completed,
            'requested_by' => User::factory(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'chats_found' => 1,
            'chats_processed' => 1,
            'chats_failed' => 0,
            'messages_found' => 1,
            'messages_imported' => 1,
            'messages_skipped' => 0,
            'messages_failed' => 0,
            'options' => [],
        ];
    }
}
