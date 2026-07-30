<div>
    <div class="grid grid-3">
        <div><label>Busca (nome, telefone, e-mail, cidade)</label><input type="text" wire:model.live.debounce.400ms="q" placeholder="Digite para buscar..."></div>
        <div><label>Status</label><select wire:model.live="status"><option value="">Todos</option>@foreach($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
        <div><label>Etiqueta</label><select wire:model.live="tagId"><option value="">Todas</option>@foreach($tags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></div>
        <div><label>Cidade</label><input type="text" wire:model.live.debounce.400ms="city"></div>
        <div><label>Estado</label><input type="text" maxlength="2" wire:model.live.debounce.400ms="state"></div>
        <div><label>Consentimento</label><select wire:model.live="consentStatus"><option value="">Todos</option>@foreach($consentStatuses as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach</select></div>
        <div><label>Não contatar</label><select wire:model.live="doNotContact"><option value="">Todos</option><option value="0">Não</option><option value="1">Sim</option></select></div>
        <div><label>Telefone</label><select wire:model.live="phonePresence"><option value="">Todos</option><option value="with">Com telefone</option><option value="without">Sem telefone</option></select></div>
        <div><label>Contato anterior</label><select wire:model.live="neverContacted"><option value="">Todos</option><option value="1">Nunca contatados</option></select></div>
        <div><label>Cadastrado de</label><input type="date" wire:model.live="createdFrom"></div>
        <div><label>Cadastrado até</label><input type="date" wire:model.live="createdTo"></div>
    </div>

    <p class="muted" style="margin-top:12px;">
        <strong>{{ $matchingCount }}</strong> contato(s) encontrados com esses filtros
        &middot; <strong>{{ count($selectedIds) }}</strong> selecionado(s) manualmente
    </p>

    @foreach($selectedIds as $id)
        <input type="hidden" name="contact_ids[]" value="{{ $id }}">
    @endforeach
    <input type="hidden" name="filters[q]" value="{{ $q }}">
    <input type="hidden" name="filters[status]" value="{{ $status }}">
    <input type="hidden" name="filters[city]" value="{{ $city }}">
    <input type="hidden" name="filters[state]" value="{{ $state }}">
    <input type="hidden" name="filters[tag_id]" value="{{ $tagId }}">
    <input type="hidden" name="filters[consent_status]" value="{{ $consentStatus }}">
    <input type="hidden" name="filters[do_not_contact]" value="{{ $doNotContact }}">
    <input type="hidden" name="filters[phone_presence]" value="{{ $phonePresence }}">
    <input type="hidden" name="filters[never_contacted]" value="{{ $neverContacted }}">
    <input type="hidden" name="filters[created_from]" value="{{ $createdFrom }}">
    <input type="hidden" name="filters[created_to]" value="{{ $createdTo }}">

    @if(count($selectedIds) > 0)
        <div class="selected-contacts" style="margin-top:12px;">
            <label>Selecionados</label>
            <div class="grid grid-3">
                @foreach($selectedContacts as $contact)
                    <span class="badge" style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        {{ $contact->name }}
                        <button type="button" class="btn ghost" style="padding:0 6px;" wire:click="removeSelected({{ $contact->id }})">&times;</button>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="actions" style="margin-top:12px;">
        <button type="button" class="btn ghost" wire:click="selectAllOnPage">Selecionar todos desta página</button>
        <button type="button" class="btn ghost" wire:click="clearSelection">Limpar seleção</button>
    </div>

    <label style="margin-top:12px;">Seleção manual</label>
    <div class="grid grid-3" wire:loading.class="muted">
        @forelse($contacts as $contact)
            <label style="font-weight:400;display:flex;gap:6px;align-items:center;" wire:key="contact-{{ $contact->id }}">
                <input type="checkbox" style="width:auto;min-height:auto;"
                    @checked(in_array($contact->id, $selectedIds, true))
                    wire:click="toggleContact({{ $contact->id }})">
                {{ $contact->name }} - {{ $contact->city }}
                @if($contact->do_not_contact)<span class="muted">(não contatar)</span>@endif
            </label>
        @empty
            <p class="muted">Nenhum contato encontrado com esses filtros.</p>
        @endforelse
    </div>
    <div style="margin-top:12px;">{{ $contacts->links() }}</div>
</div>
