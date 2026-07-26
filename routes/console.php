<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('messages:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('monitoring:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reports:expire-exports')->hourly()->withoutOverlapping();
Schedule::command('maintenance:cleanup')->daily()->withoutOverlapping();
Schedule::command('reports:rebuild-metrics')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('inbox:recover-stuck')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:sync-unread-counts')->hourly()->withoutOverlapping();
Schedule::command('inbox:archive-resolved')->daily()->withoutOverlapping();
Schedule::command('conversations:sync --queue')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('conversations:recover-sync')->everyFiveMinutes()->withoutOverlapping();
