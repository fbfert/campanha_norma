<?php

namespace App\Services\Contacts;

use App\Enums\ContactHistoryAction;
use App\Models\Contact;
use App\Models\ContactHistory;
use App\Models\User;

class ContactHistoryService
{
    public function record(Contact $contact, ContactHistoryAction $action, ?string $description = null, ?array $old = null, ?array $new = null, ?User $user = null): ContactHistory
    {
        return ContactHistory::create([
            'contact_id' => $contact->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action->value,
            'description' => $description,
            'old_values' => $this->safe($old),
            'new_values' => $this->safe($new),
        ]);
    }

    private function safe(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        unset($values['notes'], $values['consent_text']);

        return $values;
    }
}
