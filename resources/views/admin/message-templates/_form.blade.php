<div class="grid grid-2">
    <div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $template->name) }}" required></div>
    <div><label for="status">Status</label><select id="status" name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', $template->status?->value ?? 'active') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
</div>
<div style="margin-top:12px;"><label for="description">Descricao</label><textarea id="description" name="description" rows="2">{{ old('description', $template->description) }}</textarea></div>
<div style="margin-top:12px;"><label for="body">Mensagem</label><textarea id="body" name="body" rows="8" maxlength="4096" required>{{ old('body', $template->body) }}</textarea><p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="body" /> Texto simples. Placeholders disponiveis: @foreach($catalog as $key => $info)<button class="btn ghost" type="button" onclick="document.getElementById('body').value += '{{ '{'.$key.'}' }}'">{{ '{'.$key.'}' }}</button> @endforeach</p></div>
