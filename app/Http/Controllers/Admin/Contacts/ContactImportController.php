<?php

namespace App\Http\Controllers\Admin\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ContactImportUploadRequest;
use App\Models\ContactImport;
use App\Services\Contacts\ContactExportService;
use App\Services\Contacts\ContactImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactImportController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->can('contacts.import'), 403);

        return view('admin.contacts.import');
    }

    public function upload(ContactImportUploadRequest $request, ContactImportService $service): RedirectResponse
    {
        $import = $service->upload($request->file('file'));

        return redirect()->route('admin.contacts.imports.show', $import)->with('success', 'Arquivo enviado. Revise a pre-validação antes de confirmar.');
    }

    public function validateImport(Request $request, ContactImport $contactImport, ContactImportService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.import'), 403);
        $service->validateRows($contactImport);

        return back()->with('success', 'Pre-validação concluída.');
    }

    public function confirm(Request $request, ContactImport $contactImport, ContactImportService $service): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.import'), 403);
        $data = $request->validate(['duplicate_strategy' => ['required', 'in:ignore,update,new_only,interrupt']]);
        $service->process($contactImport, $data['duplicate_strategy']);

        return back()->with('success', 'Importação processada.');
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('contacts.import'), 403);

        return view('admin.contacts.imports.index', [
            'imports' => ContactImport::query()->with('user')->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, ContactImport $contactImport): View
    {
        abort_unless($request->user()->can('contacts.import'), 403);

        return view('admin.contacts.imports.show', [
            'import' => $contactImport->load('user'),
            'rows' => $contactImport->rows()->orderBy('row_number')->paginate(50),
        ]);
    }

    public function template(Request $request, ContactExportService $export): BinaryFileResponse
    {
        abort_unless($request->user()->can('contacts.import'), 403);

        return $export->template();
    }
}
