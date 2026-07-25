<?php

namespace App\Services\IncomingMessages;

use App\Enums\ContactMatchStatus;
use App\Models\Contact;
use App\Services\Contacts\PhoneNormalizerService;

class ContactMatcherService
{
    public function __construct(private readonly PhoneNormalizerService $normalizer) {}

    /**
     * @return array{status: ContactMatchStatus, phone:?string, contact:?Contact, matches:mixed}
     */
    public function match(string $phone): array
    {
        $result = $this->normalizer->normalize($phone);
        if (! $result->valid()) {
            return ['status' => ContactMatchStatus::InvalidPhone, 'phone' => null, 'contact' => null, 'matches' => collect()];
        }

        $matches = Contact::withTrashed()->where('phone_normalized', $result->normalized)->get();

        if ($matches->count() === 0) {
            return ['status' => ContactMatchStatus::NotFound, 'phone' => $result->normalized, 'contact' => null, 'matches' => $matches];
        }

        if ($matches->whereNull('deleted_at')->count() !== 1) {
            return ['status' => ContactMatchStatus::MultipleMatches, 'phone' => $result->normalized, 'contact' => null, 'matches' => $matches];
        }

        return ['status' => ContactMatchStatus::Matched, 'phone' => $result->normalized, 'contact' => $matches->whereNull('deleted_at')->first(), 'matches' => $matches];
    }
}
