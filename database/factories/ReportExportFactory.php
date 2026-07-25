<?php

namespace Database\Factories;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportExport> */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'report_type' => 'messages',
            'format' => 'csv',
            'status' => ReportExportStatus::Pending,
            'filters' => [],
            'columns' => ReportExportService::MESSAGE_COLUMNS,
            'total_rows' => 0,
            'expires_at' => now()->addDay(),
        ];
    }
}
