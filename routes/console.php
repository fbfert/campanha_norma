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
Schedule::command('cleanup:purge-expired')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('reports:rebuild-metrics')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('inbox:recover-stuck')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:sync-unread-counts')->hourly()->withoutOverlapping();
Schedule::command('inbox:archive-resolved')->daily()->withoutOverlapping();
// Arquivo de mídia vencido sai do disco; o registro fica. É foto de gente, e
// guardar para sempre não é decisão que se toma por omissão.
Schedule::command('conversations:prune-attachments')->daily()->withoutOverlapping();
Schedule::command('conversations:sync --queue')->everyFifteenMinutes()->withoutOverlapping();

// Rede de segurança das conversas: age so onde a automação já teve tempo e não
// respondeu. Cinco minutos e frequente o bastante para o silêncio não virar
// abandono, e espaçado o bastante para não competir com o debounce da geração.
Schedule::command('conversations:answer-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('conversations:recover-sync')->everyFiveMinutes()->withoutOverlapping();
