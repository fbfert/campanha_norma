<?php

namespace App\Http\Controllers\Admin\Contacts;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ContactRequest;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\ContactDataService;
use App\Services\Contacts\ContactExportService;
use App\Services\Contacts\ContactQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactController extends Controller
{
    public function index(Request $request, ContactQueryService $queryService): View
    {
        abort_unless($request->user()->can('contacts.view'), 403);

        $filters = $request->all();
        $contacts = $queryService->query($filters)->paginate(20)->withQueryString();

        return view('admin.contacts.index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'tags' => Tag::query()->where('is_active', true)->orderBy('name')->get(),
            'statuses' => ContactStatus::cases(),
            'sources' => ContactSource::cases(),
            'consents' => ConsentStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('contacts.create'), 403);

        return view('admin.contacts.create', $this->formData());
    }

    public function store(ContactRequest $request, ContactDataService $service): RedirectResponse
    {
        $contact = $service->create($request->validated(), $request->input('tags', []));

        return redirect()->route('admin.contacts.show', $contact)->with('success', 'Contato criado com sucesso.');
    }

    public function show(Request $request, Contact $contact): View
    {
        abort_unless($request->user()->can('contacts.view'), 403);

        return view('admin.contacts.show', ['contact' => $contact->load(['tags', 'history.user', 'creator', 'updater'])]);
    }

    public function edit(Request $request, Contact $contact): View
    {
        abort_unless($request->user()->can('contacts.update'), 403);

        return view('admin.contacts.edit', array_merge($this->formData(), ['contact' => $contact->load('tags', 'history.user')]));
    }

    public function update(ContactRequest $request, Contact $contact, ContactDataService $service): RedirectResponse
    {
        $service->update($contact, $request->validated(), $request->input('tags', []));

        return redirect()->route('admin.contacts.show', $contact)->with('success', 'Contato atualizado com sucesso.');
    }

    public function destroy(Request $request, Contact $contact, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.delete'), 403);
        $service->delete($contact);

        return redirect()->route('admin.contacts.index')->with('success', 'Contato excluido logicamente.');
    }

    public function restore(Request $request, int $contact, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.restore'), 403);
        $contact = Contact::withTrashed()->findOrFail($contact);
        $service->restore($contact);

        return redirect()->route('admin.contacts.show', $contact)->with('success', 'Contato restaurado.');
    }

    public function status(Request $request, Contact $contact, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.update'), 403);
        $data = $request->validate(['status' => ['required', Rule::enum(ContactStatus::class)]]);
        $service->setStatus($contact, ContactStatus::from($data['status']));

        return back()->with('success', 'Status alterado.');
    }

    public function doNotContact(Request $request, Contact $contact, ContactDataService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.mark_do_not_contact'), 403);
        $data = $request->validate([
            'do_not_contact' => ['required', 'boolean'],
            'do_not_contact_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->setDoNotContact($contact, (bool) $data['do_not_contact'], $data['do_not_contact_reason'] ?? null);

        return back()->with('success', 'Restricao atualizada.');
    }

    public function export(Request $request, ContactQueryService $queryService, ContactExportService $export): BinaryFileResponse
    {
        abort_unless($request->user()->can('contacts.export'), 403);
        $ids = collect(explode(',', (string) $request->input('ids')))->filter()->map(fn ($id) => (int) $id)->all();
        $query = $queryService->query($request->all());
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $export->export($query->cursor(), $request->input('format', 'csv'));
    }

    private function formData(): array
    {
        return [
            'tags' => Tag::query()->where('is_active', true)->orderBy('name')->get(),
            'statuses' => ContactStatus::cases(),
            'sources' => ContactSource::cases(),
            'consents' => ConsentStatus::cases(),
        ];
    }
}
