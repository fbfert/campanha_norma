<?php

namespace App\Http\Controllers\Admin\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\TagRequest;
use App\Models\Tag;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('contacts.manage_tags'), 403);

        return view('admin.tags.index', ['tags' => Tag::withCount('contacts')->orderBy('name')->paginate(20)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('contacts.manage_tags'), 403);

        return view('admin.tags.create');
    }

    public function store(TagRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['created_by'] = $request->user()->id;
        $tag = Tag::create($data);
        $audit->log('tag.created', 'Etiqueta criada.', $tag, null, $tag->only(['name', 'slug', 'color', 'is_active']));

        return redirect()->route('admin.tags.index')->with('success', 'Etiqueta criada.');
    }

    public function edit(Request $request, Tag $tag): View
    {
        abort_unless($request->user()->can('contacts.manage_tags'), 403);

        return view('admin.tags.edit', ['tag' => $tag]);
    }

    public function update(TagRequest $request, Tag $tag, AuditLogger $audit): RedirectResponse
    {
        $old = $tag->only(['name', 'slug', 'color', 'is_active']);
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $tag->update($data);
        $audit->log('tag.updated', 'Etiqueta atualizada.', $tag, $old, $tag->only(['name', 'slug', 'color', 'is_active']));

        return redirect()->route('admin.tags.index')->with('success', 'Etiqueta atualizada.');
    }

    public function destroy(Request $request, Tag $tag, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.manage_tags'), 403);
        $tag->delete();
        $audit->log('tag.deleted', 'Etiqueta excluída logicamente.', $tag);

        return back()->with('success', 'Etiqueta excluída logicamente.');
    }
}
