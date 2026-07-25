<div class="grid grid-2">
    <p><label>Nome</label><input name="name" value="{{ old('name', $tag->name ?? '') }}" required></p>
    <p><label>Cor</label><input name="color" value="{{ old('color', $tag->color ?? '#176b4d') }}" required pattern="^#[0-9a-fA-F]{6}$"></p>
</div>
<p><label>Descrição</label><textarea name="description">{{ old('description', $tag->description ?? '') }}</textarea></p>
<label style="display:flex;gap:8px;align-items:center;font-weight:400;">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tag->is_active ?? true)) style="width:auto;min-height:auto;">
    Etiqueta ativa
</label>
