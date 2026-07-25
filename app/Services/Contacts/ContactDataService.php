<?php

namespace App\Services\Contacts;

use App\Enums\ConsentStatus;
use App\Enums\ContactHistoryAction;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactDataService
{
    public function __construct(
        private readonly PhoneNormalizerService $phones,
        private readonly ContactDuplicateService $duplicates,
        private readonly ContactHistoryService $history,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function prepare(array $data, ?Contact $contact = null): array
    {
        $data['name'] = preg_replace('/\s+/', ' ', trim((string) ($data['name'] ?? '')));
        $data['first_name'] = trim((string) ($data['first_name'] ?? '')) ?: $this->firstName($data['name']);
        $data['email'] = filled($data['email'] ?? null) ? Str::lower(trim((string) $data['email'])) : null;
        $data['state'] = filled($data['state'] ?? null) ? Str::upper(trim((string) $data['state'])) : null;
        $data['country'] = filled($data['country'] ?? null) ? Str::upper(trim((string) $data['country'])) : (string) $this->settings->get('contacts.default_country', 'BR');
        $data['source'] = $data['source'] ?? ContactSource::Manual->value;
        $data['status'] = $data['status'] ?? ContactStatus::Active->value;
        $data['consent_status'] = $data['consent_status'] ?? ConsentStatus::NotInformed->value;
        $data['do_not_contact'] = (bool) ($data['do_not_contact'] ?? false);

        $phoneResult = $this->phones->normalize($data['phone'] ?? null);
        if (! $phoneResult->valid()) {
            throw ValidationException::withMessages(['phone' => $phoneResult->error]);
        }
        $data['phone_normalized'] = $phoneResult->normalized;

        if ($this->settings->get('contacts.prevent_duplicate_phone', '1') === '1') {
            $duplicate = $this->duplicates->exactPhone($data['phone_normalized'], $contact?->id);
            if ($duplicate) {
                throw ValidationException::withMessages(['phone' => 'Telefone ja cadastrado no contato #'.$duplicate->id.' - '.$duplicate->name.'.']);
            }
        }

        if ($data['do_not_contact']) {
            if (($this->settings->get('contacts.require_do_not_contact_reason', '1') === '1') && blank($data['do_not_contact_reason'] ?? null)) {
                throw ValidationException::withMessages(['do_not_contact_reason' => 'Informe o motivo para nao contatar.']);
            }
            $data['do_not_contact_at'] ??= now();
        } else {
            $data['do_not_contact_at'] = null;
            $data['do_not_contact_reason'] = null;
        }

        return $data;
    }

    public function create(array $data, array $tagIds = []): Contact
    {
        return DB::transaction(function () use ($data, $tagIds): Contact {
            $data = $this->prepare($data);
            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();

            $contact = Contact::create($data);
            $this->syncTags($contact, $tagIds);
            $this->history->record($contact, ContactHistoryAction::Created, 'Contato criado.', null, $contact->only(['name', 'phone_normalized', 'email', 'status']));
            $this->audit->log('contact.created', 'Contato criado.', $contact, null, $contact->only(['name', 'phone_normalized', 'email', 'status']));

            return $contact;
        });
    }

    public function update(Contact $contact, array $data, array $tagIds = []): Contact
    {
        return DB::transaction(function () use ($contact, $data, $tagIds): Contact {
            $old = $contact->only(['name', 'first_name', 'phone_normalized', 'email', 'city', 'status', 'do_not_contact']);
            $data = $this->prepare($data, $contact);
            $data['updated_by'] = auth()->id();

            $contact->update($data);
            $this->syncTags($contact, $tagIds);
            $new = $contact->fresh()->only(['name', 'first_name', 'phone_normalized', 'email', 'city', 'status', 'do_not_contact']);
            $this->history->record($contact, ContactHistoryAction::Updated, 'Contato atualizado.', $old, $new);
            $this->audit->log('contact.updated', 'Contato atualizado.', $contact, $old, $new);

            return $contact;
        });
    }

    public function restore(Contact $contact): void
    {
        $duplicate = $this->duplicates->exactPhone($contact->phone_normalized, $contact->id);
        if ($duplicate) {
            throw ValidationException::withMessages(['contact' => 'Nao e possivel restaurar: telefone em uso no contato #'.$duplicate->id.'.']);
        }

        $contact->restore();
        $this->history->record($contact, ContactHistoryAction::Restored, 'Contato restaurado.');
        $this->audit->log('contact.restored', 'Contato restaurado.', $contact);
    }

    public function delete(Contact $contact): void
    {
        $contact->delete();
        $this->history->record($contact, ContactHistoryAction::Deleted, 'Contato excluido logicamente.');
        $this->audit->log('contact.deleted', 'Contato excluido logicamente.', $contact);
    }

    public function setStatus(Contact $contact, ContactStatus $status): void
    {
        $old = ['status' => $contact->status->value];
        $contact->update(['status' => $status, 'updated_by' => auth()->id()]);
        $this->history->record($contact, ContactHistoryAction::StatusChanged, 'Status alterado.', $old, ['status' => $status->value]);
        $this->audit->log('contact.status_changed', 'Status do contato alterado.', $contact, $old, ['status' => $status->value]);
    }

    public function setDoNotContact(Contact $contact, bool $value, ?string $reason = null): void
    {
        if ($value && ($this->settings->get('contacts.require_do_not_contact_reason', '1') === '1') && blank($reason)) {
            throw ValidationException::withMessages(['do_not_contact_reason' => 'Informe o motivo para nao contatar.']);
        }

        $old = $contact->only(['do_not_contact', 'do_not_contact_reason']);
        $contact->update([
            'do_not_contact' => $value,
            'do_not_contact_at' => $value ? now() : null,
            'do_not_contact_reason' => $value ? $reason : null,
            'updated_by' => auth()->id(),
        ]);

        $action = $value ? ContactHistoryAction::MarkedDoNotContact : ContactHistoryAction::UnmarkedDoNotContact;
        $this->history->record($contact, $action, $value ? 'Contato marcado como nao contatar.' : 'Restricao nao contatar removida.', $old, $contact->only(['do_not_contact', 'do_not_contact_reason']));
        $this->audit->log($value ? 'contact.marked_do_not_contact' : 'contact.unmarked_do_not_contact', 'Restricao de contato alterada.', $contact, $old, $contact->only(['do_not_contact', 'do_not_contact_reason']));
    }

    public function syncTags(Contact $contact, array $tagIds): void
    {
        $payload = collect($tagIds)->filter()->mapWithKeys(fn ($id) => [(int) $id => ['created_by' => auth()->id()]])->all();
        $contact->tags()->sync($payload);
    }

    public function firstName(string $name): string
    {
        $ignored = ['de', 'da', 'do', 'das', 'dos'];
        foreach (explode(' ', trim($name)) as $part) {
            if ($part !== '' && ! in_array(Str::lower($part), $ignored, true)) {
                return $part;
            }
        }

        return trim($name);
    }
}
