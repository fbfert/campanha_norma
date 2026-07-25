<?php

namespace App\Queries\Histories;

use App\Models\MessageBatchRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MessageHistoryQuery
{
    public function build(Request|array $input): Builder
    {
        $filters = $input instanceof Request ? $input->all() : $input;

        return MessageBatchRecipient::query()
            ->with(['batch.template', 'batch.creator', 'contact', 'attempts'])
            ->when($filters['q'] ?? null, function (Builder $query, string $q): void {
                $digits = preg_replace('/\D+/', '', $q);
                $query->where(function (Builder $query) use ($q, $digits): void {
                    $query->where('contact_name_snapshot', 'like', "%{$q}%")
                        ->orWhere('contact_email_snapshot', 'like', "%{$q}%")
                        ->orWhere('contact_city_snapshot', 'like', "%{$q}%")
                        ->orWhere('contact_phone_snapshot', 'like', "%{$q}%")
                        ->orWhere('rendered_message', 'like', "%{$q}%")
                        ->orWhere('error_code', 'like', "%{$q}%")
                        ->orWhere('external_message_id', 'like', "%{$q}%")
                        ->orWhereHas('batch', fn (Builder $batch): Builder => $batch->where('name', 'like', "%{$q}%"));

                    if ($digits) {
                        $query->orWhere('contact_phone_snapshot', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($filters['message_batch_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('message_batch_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('processing_status', $status))
            ->when($filters['error_code'] ?? null, fn (Builder $query, string $code): Builder => $query->where('error_code', $code))
            ->when($filters['city'] ?? null, fn (Builder $query, string $city): Builder => $query->where('contact_city_snapshot', 'like', "%{$city}%"))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state): Builder => $query->where('contact_state_snapshot', $state))
            ->when($filters['contact_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('contact_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByRaw('coalesce(sent_at, failed_at, created_at) desc');
    }
}
