<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class ContactQueryService
{
    public function query(array $filters = []): Builder
    {
        $query = Contact::query()->with('tags');

        if (($filters['deleted'] ?? null) === 'with') {
            $query->withTrashed();
        } elseif (($filters['deleted'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function ($inner) use ($search, $digits): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");

                if ($digits !== '') {
                    $inner->orWhere('phone_normalized', 'like', "%{$digits}%");
                }
            });
        }

        foreach (['status', 'city', 'state', 'country', 'source', 'consent_status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['tag_id'] ?? null)) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('tags.id', $filters['tag_id']));
        }

        if (($filters['do_not_contact'] ?? '') !== '') {
            $query->where('do_not_contact', (bool) $filters['do_not_contact']);
        }

        foreach (['phone', 'email', 'city'] as $field) {
            $presence = $filters[$field.'_presence'] ?? null;
            if ($presence === 'with') {
                $query->whereNotNull($field === 'phone' ? 'phone_normalized' : $field)->where($field === 'phone' ? 'phone_normalized' : $field, '!=', '');
            } elseif ($presence === 'without') {
                $query->where(fn ($inner) => $inner->whereNull($field === 'phone' ? 'phone_normalized' : $field)->orWhere($field === 'phone' ? 'phone_normalized' : $field, ''));
            }
        }

        if (($filters['never_contacted'] ?? null) === '1') {
            $query->whereNull('last_contacted_at');
        }

        if (filled($filters['created_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (filled($filters['created_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        $sort = in_array($filters['sort'] ?? '', ['name', 'city', 'created_at', 'updated_at', 'last_contacted_at'], true) ? $filters['sort'] : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction);
    }
}
