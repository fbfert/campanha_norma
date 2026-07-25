<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class ContactDuplicateService
{
    public function exactPhone(?string $normalizedPhone, ?int $ignoreId = null, bool $withTrashed = false): ?Contact
    {
        if (! $normalizedPhone) {
            return null;
        }

        $query = Contact::query()->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('phone_normalized', $normalizedPhone);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->first();
    }

    public function possible(array $data, ?int $ignoreId = null): Collection
    {
        return Contact::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($data): void {
                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
                if (! empty($data['name']) && ! empty($data['city'])) {
                    $query->orWhere(fn ($inner) => $inner->where('name', $data['name'])->where('city', $data['city']));
                }
            })
            ->limit(5)
            ->get();
    }
}
