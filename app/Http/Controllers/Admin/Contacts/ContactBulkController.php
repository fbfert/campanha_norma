<?php

namespace App\Http\Controllers\Admin\Contacts;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Services\Contacts\ContactDataService;
use App\Services\Contacts\ContactQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactBulkController extends Controller
{
    private function contacts(Request $request, ContactQueryService $queryService)
    {
        $ids = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->filter();
        $query = $queryService->query($request->input('filters', []));

        return $request->boolean('all_filtered') ? $query->get() : Contact::query()->whereIn('id', $ids)->get();
    }

    public function tags(Request $request, ContactQueryService $queryService, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.manage_tags'), 403);
        $data = $request->validate(['tag_id' => ['required', 'exists:tags,id'], 'mode' => ['required', Rule::in(['add', 'remove'])], 'ids' => ['array'], 'all_filtered' => ['nullable', 'boolean']]);
        $tag = Tag::query()->where('is_active', true)->findOrFail($data['tag_id']);
        $count = 0;
        foreach ($this->contacts($request, $queryService) as $contact) {
            $data['mode'] === 'add'
                ? $contact->tags()->syncWithoutDetaching([$tag->id => ['created_by' => $request->user()->id]])
                : $contact->tags()->detach($tag->id);
            $count++;
        }
        $audit->log('contacts.bulk_tags', 'Etiquetas aplicadas em massa.', null, null, ['tag' => $tag->name, 'mode' => $data['mode'], 'count' => $count]);

        return back()->with('success', 'Acao em massa concluida.');
    }

    public function status(Request $request, ContactQueryService $queryService, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.update'), 403);
        $data = $request->validate(['status' => ['required', Rule::enum(ContactStatus::class)], 'ids' => ['array'], 'all_filtered' => ['nullable', 'boolean']]);
        foreach ($this->contacts($request, $queryService) as $contact) {
            $service->setStatus($contact, ContactStatus::from($data['status']));
        }

        return back()->with('success', 'Status atualizado em massa.');
    }

    public function doNotContact(Request $request, ContactQueryService $queryService, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.mark_do_not_contact'), 403);
        $data = $request->validate(['do_not_contact' => ['required', 'boolean'], 'do_not_contact_reason' => ['nullable', 'string', 'max:1000'], 'ids' => ['array'], 'all_filtered' => ['nullable', 'boolean']]);
        foreach ($this->contacts($request, $queryService) as $contact) {
            $service->setDoNotContact($contact, (bool) $data['do_not_contact'], $data['do_not_contact_reason'] ?? null);
        }

        return back()->with('success', 'Restricao atualizada em massa.');
    }

    public function destroy(Request $request, ContactQueryService $queryService, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.delete'), 403);
        foreach ($this->contacts($request, $queryService) as $contact) {
            $service->delete($contact);
        }

        return back()->with('success', 'Contatos excluidos logicamente.');
    }
}
