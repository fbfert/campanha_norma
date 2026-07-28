<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class ContactDuplicateService
{
    public function __construct(private readonly PhoneNormalizerService $phones) {}

    public function exactPhone(?string $normalizedPhone, ?int $ignoreId = null, bool $withTrashed = false): ?Contact
    {
        if (! $normalizedPhone) {
            return null;
        }

        $candidates = array_values(array_filter([
            $normalizedPhone,
            $this->phones->alternateBrazilianMobileDigits($normalizedPhone),
        ]));

        $query = Contact::query()->when($withTrashed, fn ($query) => $query->withTrashed())
            ->whereIn('phone_normalized', $candidates);

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
