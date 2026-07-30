<?php

namespace App\Services\MessageProcessing;

use App\Models\SendingSetting;
use Carbon\CarbonImmutable;

class SendingWindowService
{
    /** @return array{allowed: bool, next_at: ?CarbonImmutable, reason: ?string} */
    public function check(SendingSetting $settings, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now($settings->timezone))->setTimezone($settings->timezone);

        $start = $this->timeOnDate($now, (string) $settings->start_time);
        $end = $this->timeOnDate($now, (string) $settings->end_time);
        $crossesMidnight = $end->lessThanOrEqualTo($start);

        if (! $crossesMidnight) {
            if (! in_array($now->dayOfWeekIso, $settings->allowed_weekdays ?? [], true)) {
                return $this->nextAllowedDay($settings, $now, 'Dia não permitido para envio.');
            }

            if ($now->betweenIncluded($start, $end)) {
                return ['allowed' => true, 'next_at' => null, 'reason' => null];
            }

            return $now->lessThan($start)
                ? ['allowed' => false, 'next_at' => $start, 'reason' => 'Fora do horário permitido.']
                : $this->nextAllowedDay($settings, $now->addDay()->startOfDay(), 'Fora do horário permitido.');
        }

        $todayAllowed = in_array($now->dayOfWeekIso, $settings->allowed_weekdays ?? [], true);
        $yesterdayAllowed = in_array($now->subDay()->dayOfWeekIso, $settings->allowed_weekdays ?? [], true);

        if (($todayAllowed && $now->greaterThanOrEqualTo($start)) || ($yesterdayAllowed && $now->lessThanOrEqualTo($end))) {
            return ['allowed' => true, 'next_at' => null, 'reason' => null];
        }

        if ($todayAllowed && $now->betweenExcluded($end, $start)) {
            return ['allowed' => false, 'next_at' => $start, 'reason' => 'Fora do horário permitido.'];
        }

        return $this->nextAllowedDay($settings, $now, 'Dia ou horário não permitido para envio.');
    }

    private function timeOnDate(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $date->setTime($hour, $minute);
    }

    /** @return array{allowed: bool, next_at: CarbonImmutable, reason: string} */
    private function nextAllowedDay(SendingSetting $settings, CarbonImmutable $from, string $reason): array
    {
        $candidate = $from;

        for ($i = 0; $i < 8; $i++) {
            if (in_array($candidate->dayOfWeekIso, $settings->allowed_weekdays ?? [], true)) {
                return ['allowed' => false, 'next_at' => $this->timeOnDate($candidate, (string) $settings->start_time), 'reason' => $reason];
            }

            $candidate = $candidate->addDay()->startOfDay();
        }

        return ['allowed' => false, 'next_at' => $from->addDay(), 'reason' => $reason];
    }
}
