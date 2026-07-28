<?php

namespace App\Http\Livewire;

use App\Enums\ConsentStatus;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\ContactQueryService;
use Livewire\Component;
use Livewire\WithPagination;

class CampaignContactPicker extends Component
{
    use WithPagination;

    public string $q = '';

    public string $status = '';

    public string $city = '';

    public string $state = '';

    public string $tagId = '';

    public string $consentStatus = '';

    public string $doNotContact = '';

    public string $phonePresence = '';

    public string $neverContacted = '';

    public string $createdFrom = '';

    public string $createdTo = '';

    /** @var array<int,int> */
    public array $selectedIds = [];

    public function mount(array $initialSelectedIds = []): void
    {
        $this->selectedIds = $initialSelectedIds;
    }

    public function updating($property): void
    {
        if ($property !== 'selectedIds') {
            $this->resetPage();
        }
    }

    public function toggleContact(int $contactId): void
    {
        if (in_array($contactId, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$contactId]));

            return;
        }

        $this->selectedIds[] = $contactId;
    }

    public function selectAllOnPage(): void
    {
        $ids = $this->contactsQuery()->paginate($this->perPage())->pluck('id')->all();
        $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $ids)));
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function removeSelected(int $contactId): void
    {
        $this->selectedIds = array_values(array_diff($this->selectedIds, [$contactId]));
    }

    private function filters(): array
    {
        return array_filter([
            'q' => $this->q,
            'status' => $this->status,
            'city' => $this->city,
            'state' => $this->state,
            'tag_id' => $this->tagId,
            'consent_status' => $this->consentStatus,
            'do_not_contact' => $this->doNotContact,
            'phone_presence' => $this->phonePresence,
            'never_contacted' => $this->neverContacted,
            'created_from' => $this->createdFrom,
            'created_to' => $this->createdTo,
        ], fn ($value) => $value !== '' && $value !== null);
    }

    private function contactsQuery()
    {
        return app(ContactQueryService::class)->query($this->filters());
    }

    private function perPage(): int
    {
        return 20;
    }

    public function render()
    {
        return view('livewire.campaign-contact-picker', [
            'contacts' => $this->contactsQuery()->paginate($this->perPage()),
            'matchingCount' => $this->contactsQuery()->count(),
            'selectedContacts' => Contact::query()->whereIn('id', $this->selectedIds)->orderBy('name')->get(),
            'statuses' => ContactStatus::cases(),
            'consentStatuses' => ConsentStatus::cases(),
            'tags' => Tag::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
