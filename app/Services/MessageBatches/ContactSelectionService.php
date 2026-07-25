<?php

namespace App\Services\MessageBatches;

use App\Models\Contact;
use App\Services\Contacts\ContactQueryService;
use App\Services\SystemSettingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ContactSelectionService
{
    public function __construct(
        private readonly ContactQueryService $queryService,
        private readonly RandomSelectionService $random,
        private readonly SystemSettingService $settings,
    ) {}

    public function select(array $data): Collection
    {
        $type = $data['selection_type'] ?? 'manual';
        $max = (int) $this->settings->get('messages.maximum_batch_size', 1000);

        if ($type === 'manual') {
            $ids = collect($data['contact_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['contact_ids' => 'Selecione pelo menos um contato.']);
            }

            return Contact::query()->with('tags')->whereIn('id', $ids)->limit($max)->get();
        }

        $query = $this->queryService->query($data['filters'] ?? [])->limit($max);
        $contacts = $query->get();

        if ($type === 'random_sample') {
            $quantity = max(1, (int) ($data['random_quantity'] ?? 0));
            if ($contacts->count() < $quantity) {
                throw ValidationException::withMessages(['random_quantity' => "Foram encontrados somente {$contacts->count()} contatos nos filtros."]);
            }
            $ids = $this->random->sample($contacts->pluck('id')->all(), $quantity, $data['random_seed'] ?? null);

            return Contact::query()->with('tags')->whereIn('id', $ids)->get();
        }

        return $contacts;
    }
}
