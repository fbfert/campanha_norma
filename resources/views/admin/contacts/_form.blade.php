@php($selectedTags = collect(old('tags', isset($contact) ? $contact->tags->pluck('id')->all() : []))->map(fn ($id) => (int) $id)->all())
<div class="grid grid-2">
    <p><label>Nome</label><input name="name" value="{{ old('name', $contact->name ?? '') }}" required></p>
    <p><label>Primeiro nome</label><input name="first_name" value="{{ old('first_name', $contact->first_name ?? '') }}"></p>
    <p><label>Telefone</label><input name="phone" value="{{ old('phone', $contact->phone ?? '') }}" required></p>
    <p><label>E-mail</label><input name="email" type="email" value="{{ old('email', $contact->email ?? '') }}"></p>
    <p><label>Cidade</label><input name="city" value="{{ old('city', $contact->city ?? '') }}"></p>
    <p><label>Estado</label><input name="state" maxlength="2" value="{{ old('state', $contact->state ?? '') }}"></p>
    <p><label>País</label><input name="country" maxlength="2" value="{{ old('country', $contact->country ?? 'BR') }}"></p>
    <p><label>Origem</label><select name="source">@foreach($sources as $source)<option value="{{ $source->value }}" @selected(old('source', isset($contact) ? $contact->source->value : 'manual') === $source->value)>{{ $source->label() }}</option>@endforeach</select></p>
    <p><label>Situação</label><select name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', isset($contact) ? $contact->status->value : 'active') === $status->value)>{{ $status->label() }}</option>@endforeach</select></p>
    <p><label>Consentimento</label><select name="consent_status">@foreach($consents as $consent)<option value="{{ $consent->value }}" @selected(old('consent_status', isset($contact) ? $contact->consent_status->value : 'not_informed') === $consent->value)>{{ $consent->label() }}</option>@endforeach</select></p>
    <p><label>Origem do consentimento</label><input name="consent_source" value="{{ old('consent_source', $contact->consent_source ?? '') }}"></p>
    <p><label>Data do consentimento</label><input name="consent_at" type="date" value="{{ old('consent_at', isset($contact) ? $contact->consent_at?->format('Y-m-d') : '') }}"></p>
</div>
<p><label>Texto do consentimento</label><textarea name="consent_text">{{ old('consent_text', $contact->consent_text ?? '') }}</textarea></p>
<p><label>Observações</label><textarea name="notes">{{ old('notes', $contact->notes ?? '') }}</textarea></p>
<fieldset class="card" style="margin-bottom:16px;">
    <legend>Etiquetas</legend>
    @forelse($tags as $tag)
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;">
            <input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selectedTags, true)) style="width:auto;min-height:auto;">
            <span style="color:{{ $tag->color }}">{{ $tag->name }}</span>
        </label>
    @empty
        <p class="muted">Nenhuma etiqueta ativa cadastrada.</p>
    @endforelse
</fieldset>
<fieldset class="card" style="margin-bottom:16px;border-color:#f2aaa6;">
    <legend>Não contatar</legend>
    <label style="display:flex;gap:8px;align-items:center;font-weight:400;">
        <input type="checkbox" name="do_not_contact" value="1" @checked(old('do_not_contact', $contact->do_not_contact ?? false)) style="width:auto;min-height:auto;">
        Não contatar novamente
    </label>
    <p><label>Motivo para não contatar</label><textarea name="do_not_contact_reason">{{ old('do_not_contact_reason', $contact->do_not_contact_reason ?? '') }}</textarea></p>
</fieldset>
