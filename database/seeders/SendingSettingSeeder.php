<?php

namespace Database\Seeders;

use App\Models\SendingSetting;
use Illuminate\Database\Seeder;

class SendingSettingSeeder extends Seeder
{
    public function run(): void
    {
        SendingSetting::query()->firstOrCreate([], [
            'max_per_minute' => 1,
            'max_per_hour' => 15,
            'max_per_day' => 40,
            'minimum_interval_seconds' => 60,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5],
            'timezone' => 'America/Sao_Paulo',
            'max_attempts' => 3,
            'retry_interval_minutes' => 15,
            'retry_backoff_type' => 'fixed',
            'pause_when_disconnected' => true,
        ]);
    }
}
