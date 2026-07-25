<?php

namespace App\Http\Controllers\Admin\Inbox;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationTag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ManualReplyService;
use App\Services\Conversations\ReplyInterruptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('inbox.view'), 403);
        $audit->log('inbox.viewed', 'Caixa de entrada visualizada.');

        $query = Conversation::with(['contact', 'assignee', 'messages', 'tags'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->boolean('unread'), fn ($query) => $query->where('unread_count', '>', 0))
            ->when($request->filled('assigned'), fn ($query) => $request->string('assigned')->toString() === 'me' ? $query->where('assigned_user_id', $request->user()->id) : $query->whereNull('assigned_user_id'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = trim((string) $request->query('q'));
                $digits = preg_replace('/\D+/', '', $q);
                $query->where(function ($query) use ($q, $digits): void {
                    $query->whereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%"));
                    if ($digits !== '') {
                        $query->orWhereHas('contact', fn ($contact) => $contact->where('phone_normalized', 'like', "%{$digits}%"));
                    }
                    $query->orWhereHas('messages', fn ($message) => $message->where('body', 'like', "%{$q}%"));
                });
            })
            ->latest('last_message_at');

        if (! $request->user()->can('inbox.view_all')) {
            $query->where(function ($query) use ($request): void {
                $query->where('assigned_user_id', $request->user()->id)->orWhereNull('assigned_user_id');
            });
        }

        return view('admin.inbox.index', [
            'conversations' => $query->paginate(20)->withQueryString(),
            'statuses' => ConversationStatus::cases(),
            'priorities' => ConversationPriority::cases(),
        ]);
    }

    public function show(Request $request, Conversation $conversation, ConversationEventService $events, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('inbox.view'), 403);
        $this->scope($request, $conversation);

        $conversation->messages()->where('direction', 'incoming')->whereNull('read_at')->update(['read_at' => now()]);
        $conversation->update(['unread_count' => 0]);
        $events->record($conversation, 'read', 'Conversa marcada como lida.', null, $request->user());
        $audit->log('conversation.read', 'Conversa marcada como lida.', $conversation, null, null, $request->user());

        return view('admin.inbox.show', [
            'conversation' => $conversation->load(['contact', 'assignee', 'messages.creator', 'events', 'notes.user', 'tags']),
            'users' => User::where('status', 'active')->orderBy('name')->get(),
            'tags' => ConversationTag::where('is_active', true)->orderBy('name')->get(),
            'contacts' => Contact::orderBy('name')->limit(100)->get(),
            'statuses' => ConversationStatus::cases(),
            'priorities' => ConversationPriority::cases(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, ManualReplyService $replies): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.reply'), 403);
        $this->scope($request, $conversation);
        $validated = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $replies->request($conversation->load('contact'), $request->user(), $validated['body']);

        return back()->with('success', 'Resposta manual enfileirada.');
    }

    public function assign(Request $request, Conversation $conversation, ConversationEventService $events, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.assign'), 403);
        $userId = $request->integer('assigned_user_id') ?: $request->user()->id;
        $conversation->update(['assigned_user_id' => $userId]);
        $conversation->assignments()->create(['assigned_user_id' => $userId, 'assigned_by' => $request->user()->id, 'assigned_at' => now(), 'reason' => $request->input('reason')]);
        $events->record($conversation, 'assigned', 'Conversa atribuida.', null, $request->user(), ['assigned_user_id' => $userId]);
        $audit->log('conversation.assigned', 'Conversa atribuida.', $conversation, null, ['assigned_user_id' => $userId], $request->user());

        return back()->with('success', 'Conversa atribuida.');
    }

    public function unassign(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.assign'), 403);
        $conversation->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now(), 'unassigned_by' => $request->user()->id]);
        $conversation->update(['assigned_user_id' => null]);
        $events->record($conversation, 'unassigned', 'Atribuicao removida.', null, $request->user());

        return back()->with('success', 'Atribuicao removida.');
    }

    public function status(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.change_status'), 403);
        $validated = $request->validate(['status' => ['required', 'string']]);
        $conversation->update(['status' => ConversationStatus::from($validated['status'])]);
        $events->record($conversation, 'status_changed', 'Status alterado.', null, $request->user(), ['status' => $validated['status']]);

        return back()->with('success', 'Status atualizado.');
    }

    public function priority(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.change_priority'), 403);
        $validated = $request->validate(['priority' => ['required', 'string']]);
        $conversation->update(['priority' => ConversationPriority::from($validated['priority'])]);
        $events->record($conversation, 'priority_changed', 'Prioridade alterada.', null, $request->user(), ['priority' => $validated['priority']]);

        return back()->with('success', 'Prioridade atualizada.');
    }

    public function archive(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.archive'), 403);
        $conversation->update(['status' => ConversationStatus::Archived, 'is_archived' => true, 'archived_at' => now(), 'archived_by' => $request->user()->id]);
        $events->record($conversation, 'archived', 'Conversa arquivada.', null, $request->user());

        return back()->with('success', 'Conversa arquivada.');
    }

    public function unarchive(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.archive'), 403);
        $conversation->update(['status' => ConversationStatus::Open, 'is_archived' => false, 'archived_at' => null, 'archived_by' => null]);
        $events->record($conversation, 'unarchived', 'Conversa desarquivada.', null, $request->user());

        return back()->with('success', 'Conversa desarquivada.');
    }

    public function note(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.add_notes'), 403);
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $note = $conversation->notes()->create(['user_id' => $request->user()->id, 'body' => $validated['body']]);
        $events->record($conversation, 'note_added', 'Nota interna adicionada.', null, $request->user(), ['note_id' => $note->id]);

        return back()->with('success', 'Nota adicionada.');
    }

    public function updateNote(Request $request, Conversation $conversation, ConversationNote $note): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.edit_notes') || $note->user_id === $request->user()->id, 403);
        abort_unless($note->conversation_id === $conversation->id, 404);
        $note->update($request->validate(['body' => ['required', 'string', 'max:2000']]));

        return back()->with('success', 'Nota atualizada.');
    }

    public function tag(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.manage_tags'), 403);
        $tag = ConversationTag::firstOrCreate(
            ['slug' => Str::slug((string) $request->input('name'))],
            ['name' => $request->input('name'), 'color' => $request->input('color', '#176b4d'), 'created_by' => $request->user()->id]
        );
        $conversation->tags()->syncWithoutDetaching([$tag->id => ['created_by' => $request->user()->id]]);
        $events->record($conversation, 'tag_added', 'Etiqueta adicionada.', null, $request->user(), ['tag_id' => $tag->id]);

        return back()->with('success', 'Etiqueta adicionada.');
    }

    public function removeTag(Request $request, Conversation $conversation, ConversationTag $tag, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.manage_tags'), 403);
        $conversation->tags()->detach($tag->id);
        $events->record($conversation, 'tag_removed', 'Etiqueta removida.', null, $request->user(), ['tag_id' => $tag->id]);

        return back()->with('success', 'Etiqueta removida.');
    }

    public function associateContact(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.associate_contact'), 403);
        $validated = $request->validate(['contact_id' => ['required', 'exists:contacts,id']]);
        $conversation->update(['contact_id' => $validated['contact_id']]);
        $conversation->messages()->whereNull('contact_id')->update(['contact_id' => $validated['contact_id']]);
        $events->record($conversation, 'contact_associated', 'Contato associado.', null, $request->user(), ['contact_id' => $validated['contact_id']]);

        return back()->with('success', 'Contato associado.');
    }

    public function doNotContact(Request $request, Conversation $conversation, ReplyInterruptionService $interruption, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.mark_do_not_contact'), 403);
        abort_unless($conversation->contact, 422);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $conversation->contact->update(['do_not_contact' => true, 'do_not_contact_at' => now(), 'do_not_contact_reason' => $validated['reason']]);
        $interruption->interrupt($conversation->contact, $conversation->contact->phone_normalized);
        $events->record($conversation, 'blocked', 'Contato marcado como nao contatar.', null, $request->user());

        return back()->with('success', 'Contato marcado como nao contatar.');
    }

    private function scope(Request $request, Conversation $conversation): void
    {
        if (! $request->user()->can('inbox.view_all')) {
            abort_unless($conversation->assigned_user_id === null || $conversation->assigned_user_id === $request->user()->id, 403);
        }
    }
}
