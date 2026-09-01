@csrf
<div class="grid grid-2">
    <div>
        <label for="name">Nome</label>
        <input id="name" name="name" value="{{ old('name', $topic->name) }}" required>
    </div>
    <div>
        <label for="slug">Identificador</label>
        <input id="slug" name="slug" value="{{ old('slug', $topic->slug) }}" required>
        <p class="muted">Somente letras minúsculas, números e sublinhado. E o valor devolvido pelo modelo.</p>
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
        <label for="is_active">Situação</label>
        <select id="is_active" name="is_active" @disabled($topic->is_fallback)>
            <option value="1" @selected((bool) old('is_active', $topic->is_active))>Ativo</option>
            <option value="0" @selected(! (bool) old('is_active', $topic->is_active))>Inativo</option>
        </select>
        @if($topic->is_fallback)
            <p class="muted">O tema de fallback não pode ser desativado.</p>
        @endif
    </div>
</div>

<div style="margin-top:12px;">
    <label for="synonyms">Sinônimos</label>
    <textarea id="synonyms" name="synonyms" rows="3">{{ old('synonyms', $topic->synonyms) }}</textarea>
    <p class="muted">Separados por barra vertical. Usados no mapeamento determinístico da saída do modelo.</p>
</div>

<div style="margin-top:12px;">
    <label for="description">Descrição</label>
    <textarea id="description" name="description" rows="2">{{ old('description', $topic->description) }}</textarea>
</div>

{{-- Os dois campos que transformam o dossiê da pauta de "resumo do que o
     eleitor disse" em roteiro que protege quem responde. Nenhum passo
     automático preenche isto: é trabalho de quem entende de campanha, uma vez
     por tema, e sem ele o dossiê sai vazio justamente na parte que mais
     importa. --}}
<div style="margin-top:12px;">
    <label for="response_guidance">O que a campanha defende sobre este tema</label>
    <textarea id="response_guidance" name="response_guidance" rows="3">{{ old('response_guidance', $topic->response_guidance) }}</textarea>
    <p class="muted">Aparece no dossiê da pauta de resposta, como apoio de quem vai responder. Deixe em branco enquanto não houver posição escrita.</p>
</div>

<div style="margin-top:12px;">
    <label for="red_lines">Linha vermelha — o que não prometer</label>
    <textarea id="red_lines" name="red_lines" rows="3">{{ old('red_lines', $topic->red_lines) }}</textarea>
    <p class="muted">Aparece em destaque forte no dossiê. Promessa dita pela própria candidata, na voz dela, não tem retratação possível.</p>
</div>
