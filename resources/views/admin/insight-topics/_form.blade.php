@csrf
<div class="grid grid-2">
    <div>
        <label for="name">Nome</label>
        <input id="name" name="name" value="{{ old('name', $topic->name) }}" required>
    </div>
    <div>
        <label for="slug">Identificador</label>
        <input id="slug" name="slug" value="{{ old('slug', $topic->slug) }}" required>
        <p class="muted">Somente letras minusculas, numeros e sublinhado. E o valor devolvido pelo modelo.</p>
    </div>
    <div>
        <label for="parent_id">Tema pai</label>
        <select id="parent_id" name="parent_id">
            <option value="">Nenhum (tema principal)</option>
            @foreach($parents as $parent)
                <option value="{{ $parent->id }}" @selected((string) old('parent_id', $topic->parent_id) === (string) $parent->id)>{{ $parent->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="display_order">Ordem</label>
        <input id="display_order" name="display_order" type="number" min="0" max="9999" value="{{ old('display_order', $topic->display_order ?? 0) }}" required>
    </div>
    <div>
        <label for="color">Cor da interface</label>
        <input id="color" name="color" value="{{ old('color', $topic->color) }}" placeholder="#2563eb">
    </div>
    <div>
        <label for="is_active">Situacao</label>
        <select id="is_active" name="is_active" @disabled($topic->is_fallback)>
            <option value="1" @selected((bool) old('is_active', $topic->is_active))>Ativo</option>
            <option value="0" @selected(! (bool) old('is_active', $topic->is_active))>Inativo</option>
        </select>
        @if($topic->is_fallback)
            <p class="muted">O tema de fallback nao pode ser desativado.</p>
        @endif
    </div>
</div>

<div style="margin-top:12px;">
    <label for="synonyms">Sinonimos</label>
    <textarea id="synonyms" name="synonyms" rows="3">{{ old('synonyms', $topic->synonyms) }}</textarea>
    <p class="muted">Separados por barra vertical. Usados no mapeamento deterministico da saida do modelo.</p>
</div>

<div style="margin-top:12px;">
    <label for="description">Descricao</label>
    <textarea id="description" name="description" rows="2">{{ old('description', $topic->description) }}</textarea>
</div>
