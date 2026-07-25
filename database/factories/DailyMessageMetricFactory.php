<?php

namespace Database\Factories;

use App\Models\DailyMessageMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyMessageMetric> */
class DailyMessageMetricFactory extends Factory
{
    protected $model = DailyMessageMetric::class;

    public function definition(): array
    {
        return [
            'date' => today(),
            'provider' => 'web',
            'total_prepared' => 0,
            'total_processed' => 0,
            'total_sent' => 0,
            'total_failed' => 0,
            'total_cancelled' => 0,
            'total_skipped' => 0,
            'total_attempts' => 0,
            'total_waiting_minutes' => 0,
        ];
    }
}
