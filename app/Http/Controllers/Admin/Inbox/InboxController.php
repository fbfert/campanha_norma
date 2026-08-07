<?php

namespace App\Http\Controllers\Admin\Inbox;

use App\Enums\ContactStatus;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\ConversationSyncStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncWhatsAppConversationsJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationSyncRun;
use App\Models\ConversationTag;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\ContactDataService;
use App\Services\Contacts\ContactDuplicateService;
use App\Services\Contacts\PhoneNormalizerService;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationResolverService;
use App\Services\Conversations\ManualReplyService;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('inbox.view'), 403);
        $audit->log('inbox.viewed', 'Conversas visualizadas.');

        $query = Conversation::with(['contact', 'assignee', 'latestMessage', 'tags'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->boolean('awaiting_operator'), fn ($query) => $query->where('status', ConversationStatus::WaitingOperator))
            ->when($request->boolean('unread'), fn ($query) => $query->where('unread_count', '>', 0))
            ->when($request->boolean('no_contact'), fn ($query) => $query->whereNull('contact_id'))
            ->when($request->boolean('archived'), fn ($query) => $query->where('is_archived', true))
            ->when($request->boolean('not_archived'), fn ($query) => $query->where('is_archived', false))
            ->when($request->boolean('do_not_contact'), fn ($query) => $query->whereHas('contact', fn ($contact) => $contact->where('do_not_contact', true)))
            ->when($request->filled('tag_id'), fn ($query) => $query->whereHas('tags', fn ($tag) => $tag->where('conversation_tags.id', $request->integer('tag_id'))))
            ->when($request->filled('assigned'), fn ($query) => $request->string('assigned')->toString() === 'me' ? $query->where('assigned_user_id', $request->user()->id) : $query->whereNull('assigned_user_id'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = trim((string) $request->query('q'));
                $digits = preg_replace('/\D+/', '', $q);
                $query->where(function ($query) use ($request, $q, $digits): void {
                    $query->whereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%"));
                    if ($digits !== '') {
                        $query->orWhereHas('contact', fn ($contact) => $contact->where('phone_normalized', 'like', "%{$digits}%"));
                        $query->orWhere('external_chat_id', 'like', "%{$digits}%");
                    }
                    if ($request->user()?->can('inbox.view_message_content')) {
                        $query->orWhereHas('messages', fn ($message) => $message->where('body', 'like', "%{$q}%"));
                    }
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
            'tags' => ConversationTag::where('is_active', true)->orderBy('name')->get(),
            'latestSync' => ConversationSyncRun::latest()->first(),
            'syncActive' => ConversationSyncRun::whereIn('status', [ConversationSyncStatus::Pending->value, ConversationSyncStatus::Running->value])->exists(),
        ]);
    }

    /**
     * Escolher um contato para iniciar uma conversa.
     *
     * A tela mostra também quem não pode ser contatado, marcado e com o motivo,
     * em vez de sumir com essas pessoas da lista. Sumir esconde o motivo: quem
     * procura um contato e não o encontra tende a cadastra-lo de novo, e ai o
     * pedido de não contatar se perde num registro duplicado.
     */
    public function create(Request $request): View
    {
        abort_unless($request->user()->can('inbox.reply') && $request->user()->can('contacts.view'), 403);

        $contacts = Contact::query()
            ->with('tags')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = trim((string) $request->query('q'));
                $digits = preg_replace('/\D+/', '', $q);
                $query->where(function ($query) use ($q, $digits): void {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                    if ($digits !== '') {
                        $query->orWhere('phone_normalized', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($request->filled('city'), fn ($query) => $query->where('city', 'like', '%'.trim((string) $request->query('city')).'%'))
            ->when($request->filled('state'), fn ($query) => $query->where('state', 'like', '%'.trim((string) $request->query('state')).'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('tag_id'), fn ($query) => $query->whereHas('tags', fn ($tag) => $tag->where('tags.id', $request->integer('tag_id'))))
            // Padrão ligado: quem abre esta tela quer falar com alguém, e o
            // caminho comum não deveria começar por uma lista cheia de gente
            // com quem não se pode falar.
            ->when($request->boolean('only_eligible', true), function ($query): void {
                $query->where('do_not_contact', false)
                    ->where('status', ContactStatus::Active->value)
                    ->whereNotNull('phone_normalized');
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.inbox.create', [
            'contacts' => $contacts,
            'tags' => Tag::orderBy('name')->get(),
            'statuses' => ContactStatus::cases(),
            'filters' => $request->only(['q', 'city', 'state', 'status', 'tag_id', 'only_eligible']),
        ]);
    }

    /**
     * Abrir a conversa com o contato escolhido.
     *
     * Isto não envia nada. Cria (ou reencontra) a conversa e leva para a tela
     * dela, onde a primeira mensagem e escrita e revisada como qualquer outra.
     * A conversa já nasce atribuída a quem a iniciou, senão a primeira tentativa
     * de responder esbarraria em "assuma a conversa antes de responder".
     */
    public function store(Request $request, ConversationResolverService $resolver, ConversationEventService $events, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.reply') && $request->user()->can('contacts.view'), 403);

        $validated = $request->validate(['contact_id' => ['required', 'integer', 'exists:contacts,id']]);
        $contact = Contact::findOrFail($validated['contact_id']);

        if ($contact->do_not_contact) {
            throw ValidationException::withMessages(['contact_id' => 'Este contato esta marcado como não contatar.']);
        }

        if ($contact->status !== ContactStatus::Active) {
            throw ValidationException::withMessages(['contact_id' => 'Somente contatos ativos podem receber uma conversa.']);
        }

        if (blank($contact->phone_normalized)) {
            throw ValidationException::withMessages(['contact_id' => 'Este contato não tem telefone valido.']);
        }

        $conversation = $resolver->resolve($contact, 'principal', false, $contact->phone_normalized);
        $existed = ! $conversation->wasRecentlyCreated;

        if ($conversation->assigned_user_id === null) {
            $conversation->update(['assigned_user_id' => $request->user()->id]);
            $conversation->assignments()->create([
                'assigned_user_id' => $request->user()->id,
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
                'reason' => 'Conversa iniciada pelo operador.',
            ]);
        }

        if (! $existed) {
            $events->record($conversation, 'created', 'Conversa iniciada pelo operador.', null, $request->user());
            $audit->log('conversation.started', 'Conversa iniciada pelo operador.', $conversation, null, ['contact_id' => $contact->id], $request->user());
        }

        return redirect()
            ->route('admin.conversations.show', $conversation)
            ->with('success', $existed
                ? 'Já havia uma conversa aberta com este contato.'
                : 'Conversa iniciada. Escreva a primeira mensagem abaixo.');
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

    public function messages(Request $request, Conversation $conversation, SystemSettingService $settings): JsonResponse
    {
        abort_unless($request->user()->can('inbox.view'), 403);
        $this->scope($request, $conversation);

        $afterId = $request->integer('after_id');

        $messages = $conversation->messages()
            ->with('creator')
            ->where('id', '>', $afterId)
            ->latest('id')
            ->get();

        if ($messages->isNotEmpty()) {
            $conversation->messages()->where('direction', 'incoming')->whereNull('read_at')->update(['read_at' => now()]);
            $conversation->update(['unread_count' => 0]);
        }

        $dateTimeFormat = $settings->get('system.datetime_format', 'd/m/Y H:i');

        $html = $messages->map(fn ($message) => view('admin.inbox._message', [
            'message' => $message,
            'dateTimeFormat' => $dateTimeFormat,
        ])->render())->implode('');

        return response()->json([
            'html' => $html,
            'last_id' => $messages->max('id') ?? $afterId,
            'count' => $messages->count(),
        ]);
    }

    public function sync(Request $request, AuditLogger $audit, SystemSettingService $settings, WhatsAppProviderManager $providers): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.sync'), 403);

        if (! (bool) $settings->get('conversations.sync_enabled', true)) {
            return back()->with('error', 'Sincronização de conversas desativada.');
        }

        try {
            if ($providers->provider()->getStatus()->status !== WhatsAppConnectionStatus::Connected) {
                return back()->with('error', 'Conecte o WhatsApp antes de sincronizar conversas.');
            }
        } catch (\Throwable) {
            return back()->with('error', 'Não foi possível verificar a conexão do WhatsApp.');
        }

        if (ConversationSyncRun::whereIn('status', [ConversationSyncStatus::Pending->value, ConversationSyncStatus::Running->value])->exists()) {
            return back()->with('error', 'Já existe uma sincronização em andamento.');
        }

        $lock = Cache::lock('conversations:sync:active', 1);
        if (! $lock->get()) {
            return back()->with('error', 'Já existe uma sincronização em andamento.');
        }

        $lock->release();

        $run = ConversationSyncRun::create([
            'status' => ConversationSyncStatus::Pending,
            'requested_by' => $request->user()->id,
            'options' => [
                'limit_chats' => (int) $settings->get('conversations.sync_max_chats', 100),
                'messages_per_chat' => (int) $settings->get('conversations.sync_messages_per_chat', 50),
                'days' => (int) $settings->get('conversations.sync_days_back', 30),
                'include_archived' => (bool) $settings->get('conversations.sync_include_archived', false),
            ],
        ]);

        SyncWhatsAppConversationsJob::dispatch($run->id)->onQueue('whatsapp-conversation-sync');
        $audit->log('conversation.sync_requested', 'Sincronização de conversas solicitada.', $run, null, ['run_id' => $run->id], $request->user());

        return back()->with('success', 'Sincronização de conversas iniciada.');
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
        $events->record($conversation, 'assigned', 'Conversa atribuída.', null, $request->user(), ['assigned_user_id' => $userId]);
        $audit->log('conversation.assigned', 'Conversa atribuída.', $conversation, null, ['assigned_user_id' => $userId], $request->user());

        return back()->with('success', 'Conversa atribuída.');
    }

    public function unassign(Request $request, Conversation $conversation, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.assign'), 403);
        $conversation->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now(), 'unassigned_by' => $request->user()->id]);
        $conversation->update(['assigned_user_id' => null]);
        $events->record($conversation, 'unassigned', 'Atribuição removida.', null, $request->user());

        return back()->with('success', 'Atribuição removida.');
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

    public function createAndAssociateContact(
        Request $request,
        Conversation $conversation,
        ConversationEventService $events,
        PhoneNormalizerService $phones,
        ContactDuplicateService $duplicates,
        ContactDataService $contactsService,
    ): RedirectResponse {
        abort_unless($request->user()->can('inbox.associate_contact') && $request->user()->can('contacts.create'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $phoneResult = $phones->normalize($validated['phone']);
        if (! $phoneResult->valid()) {
            return back()->withErrors(['phone' => $phoneResult->error])->withInput();
        }

        $contact = $duplicates->exactPhone($phoneResult->normalized);

        if (! $contact) {
            try {
                $contact = $contactsService->create([
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'source' => 'outro',
                ]);
            } catch (ValidationException $exception) {
                return back()->withErrors($exception->errors())->withInput();
            }
        }

        $conversation->update(['contact_id' => $contact->id]);
        $conversation->messages()->whereNull('contact_id')->update(['contact_id' => $contact->id]);
        $events->record($conversation, 'contact_associated', 'Contato criado e associado.', null, $request->user(), ['contact_id' => $contact->id]);

        return back()->with('success', 'Contato criado e associado.');
    }

    public function doNotContact(Request $request, Conversation $conversation, ReplyInterruptionService $interruption, ConversationEventService $events): RedirectResponse
    {
        abort_unless($request->user()->can('inbox.mark_do_not_contact'), 403);
        abort_unless($conversation->contact, 422);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $conversation->contact->update(['do_not_contact' => true, 'do_not_contact_at' => now(), 'do_not_contact_reason' => $validated['reason']]);
        $interruption->interrupt($conversation->contact, $conversation->contact->phone_normalized);
        $events->record($conversation, 'blocked', 'Contato marcado como não contatar.', null, $request->user());

        return back()->with('success', 'Contato marcado como não contatar.');
    }

    private function scope(Request $request, Conversation $conversation): void
    {
        if (! $request->user()->can('inbox.view_all')) {
            abort_unless($conversation->assigned_user_id === null || $conversation->assigned_user_id === $request->user()->id, 403);
        }
    }
}
