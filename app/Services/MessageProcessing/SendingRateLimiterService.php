<?php

namespace App\Services\MessageProcessing;

use App\Models\SendingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SendingRateLimiterService
{
    /** @return array{allowed: bool, blocked_by: ?string, next_at: ?CarbonImmutable, counters: array<string, int>} */
    public function check(SendingSetting $settings, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now($settings->timezone);
        $counters = $this->counters($settings, $now);
        $nextAt = null;
        $blockedBy = null;

        foreach ([
            'waiting_minute_limit' => [$counters['minute'], $settings->max_per_minute, $now->addMinute()->startOfMinute()],
            'waiting_hour_limit' => [$counters['hour'], $settings->max_per_hour, $now->addHour()->startOfHour()],
            'waiting_day_limit' => [$counters['day'], $settings->max_per_day, $now->addDay()->startOfDay()],
        ] as $reason => [$current, $limit, $releaseAt]) {
            if ($current >= $limit && ($nextAt === null || $releaseAt->greaterThan($nextAt))) {
                $nextAt = $releaseAt;
                $blockedBy = $reason;
            }
        }

        $lastSendAt = Cache::get('whatsapp:last-send-at');
        if ($lastSendAt) {
            $intervalRelease = CarbonImmutable::parse($lastSendAt, $settings->timezone)->addSeconds($settings->minimum_interval_seconds);
            if ($intervalRelease->greaterThan($now) && ($nextAt === null || $intervalRelease->greaterThan($nextAt))) {
                $nextAt = $intervalRelease;
                $blockedBy = 'waiting_minimum_interval';
            }
        }

        return ['allowed' => $blockedBy === null, 'blocked_by' => $blockedBy, 'next_at' => $nextAt, 'counters' => $counters];
    }

    public function consume(SendingSetting $settings, ?CarbonImmutable $now = null): void
    {
        $now = $now ?? CarbonImmutable::now($settings->timezone);

        foreach ($this->keys($now) as $scope => [$key, $ttl]) {
            Cache::add($key, 0, $ttl);
            Cache::increment($key);
        }

        Cache::put('whatsapp:last-send-at', $now->toIso8601String(), now()->addDay());
    }

    /** @return array{minute: int, hour: int, day: int} */
    public function counters(SendingSetting $settings, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now($settings->timezone);

        return collect($this->keys($now))
            ->mapWithKeys(fn (array $item, string $scope): array => [$scope => (int) Cache::get($item[0], 0)])
            ->all();
    }

    /** @return array<string, array{0: string, 1: \DateTimeInterface}> */
    private function keys(CarbonImmutable $now): array
    {
        return [
            'minute' => ['whatsapp:rate:minute:'.$now->format('Y-m-d-H-i'), $now->addMinutes(2)],
            'hour' => ['whatsapp:rate:hour:'.$now->format('Y-m-d-H'), $now->addHours(2)],
            'day' => ['whatsapp:rate:day:'.$now->format('Y-m-d'), $now->addDays(2)],
        ];
    }
}
