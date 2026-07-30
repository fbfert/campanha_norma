<div><label for="internal_title">Título interno</label><input id="internal_title" name="internal_title" value="{{ old('internal_title', $question->internal_title) }}" maxlength="150" required></div>
<div style="margin-top:12px;"><label for="text">Texto da pergunta</label><textarea id="text" name="text" rows="5" required>{{ old('text', $question->text) }}</textarea><p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="text" /> Texto enviado ao contato.</p></div>
<div class="grid grid-3" style="margin-top:12px;">
    <div><label for="category">Categoria</label><input id="category" name="category" value="{{ old('category', $question->category) }}"></div>
    <div><label for="weight">Peso</label><input id="weight" name="weight" type="number" min="1" max="1000" value="{{ old('weight', $question->weight ?? 1) }}"><p class="muted">Peso maior aumenta a chance no sorteio.</p></div>
    <div><label for="display_order">Ordem</label><input id="display_order" name="display_order" type="number" min="0" value="{{ old('display_order', $question->display_order ?? 0) }}"></div>
</div>
<label style="display:flex;gap:8px;align-items:center;font-weight:400;margin-top:12px;">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $question->is_active ?? true)) style="width:auto;min-height:auto;">
    Pergunta ativa
</label>
