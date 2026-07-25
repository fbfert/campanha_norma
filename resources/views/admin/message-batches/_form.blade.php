<div class="card">
    <h2>1. Identificacao</h2>
    <div class="grid grid-2">
        <div><label for="name">Nome do lote</label><input id="name" name="name" value="{{ old('name', $batch->name ?? '') }}" required></div>
        <div><label for="selection_type">Selecao</label><select id="selection_type" name="selection_type">@foreach($selectionTypes as $type)<option value="{{ $type->value }}" @selected(old('selection_type', $batch->selection_type?->value ?? 'manual') === $type->value)>{{ $type->label() }}</option>@endforeach</select></div>
    </div>
    <div style="margin-top:12px;"><label for="description">Descricao</label><textarea id="description" name="description" rows="2">{{ old('description', $batch->description ?? '') }}</textarea></div>
</div>
<div class="card" style="margin-top:16px;">
    <h2>2. Mensagem</h2>
    <label for="message_template_id">Modelo</label>
    <select id="message_template_id" name="message_template_id"><option value="">Mensagem avulsa</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) old('message_template_id', $batch->message_template_id ?? '') === (string) $template->id)>{{ $template->name }} v{{ $template->version }}</option>@endforeach</select>
    <label for="message_body" style="margin-top:12px;">Mensagem avulsa ou ajuste do lote</label>
    <textarea id="message_body" name="message_body" rows="6" maxlength="4096">{{ old('message_body', $batch->message_body_snapshot ?? '') }}</textarea>
    <p class="muted">Placeholders: @foreach($catalog as $key => $info)<button class="btn ghost" type="button" onclick="document.getElementById('message_body').value += '{{ '{'.$key.'}' }}'">{{ '{'.$key.'}' }}</button> @endforeach</p>
</div>
<div class="card" style="margin-top:16px;">
    <h2>3. Contatos</h2>
    <div class="grid grid-3">
        <div><label for="filters_city">Cidade</label><input id="filters_city" name="filters[city]" value="{{ old('filters.city', $batch->selection_filters['city'] ?? '') }}"></div>
        <div><label for="filters_state">Estado</label><input id="filters_state" name="filters[state]" maxlength="2" value="{{ old('filters.state', $batch->selection_filters['state'] ?? '') }}"></div>
        <div><label for="filters_tag_id">Etiqueta</label><select id="filters_tag_id" name="filters[tag_id]"><option value="">Todas</option>@foreach($tags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></div>
        <div><label for="random_quantity">Quantidade aleatoria</label><input id="random_quantity" name="random_quantity" type="number" min="1" max="1000" value="{{ old('random_quantity') }}"></div>
    </div>
    <label style="margin-top:12px;">Selecao manual</label>
    <div class="grid grid-3">
        @foreach($contacts as $contact)
            <label style="font-weight:400;"><input type="checkbox" name="contact_ids[]" value="{{ $contact->id }}"> {{ $contact->name }} - {{ $contact->city }}</label>
        @endforeach
    </div>
</div>
