<div class="grid grid-2">
    <div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $flow->name) }}" maxlength="150" required></div>
    <div><label for="status">Status</label><select id="status" name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', $flow->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
</div>
<div style="margin-top:12px;"><label for="description">Descrição</label><textarea id="description" name="description" rows="2">{{ old('description', $flow->description) }}</textarea></div>
<div style="margin-top:12px;"><label for="presentation_template_id">Modelo de apresentação</label><select id="presentation_template_id" name="presentation_template_id"><option value="">Texto livre</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) old('presentation_template_id', $flow->presentation_template_id) === (string) $template->id)>{{ $template->name }}</option>@endforeach</select></div>
<div style="margin-top:12px;"><label for="presentation_text">Texto de apresentação</label><textarea id="presentation_text" name="presentation_text" rows="4">{{ old('presentation_text', $flow->presentation_text) }}</textarea><p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="presentation_text" /> Usado quando nenhum modelo for selecionado.</p></div>
<div style="margin-top:12px;"><label for="thank_you_text">Texto de agradecimento</label><textarea id="thank_you_text" name="thank_you_text" rows="3">{{ old('thank_you_text', $flow->thank_you_text) }}</textarea><p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="thank_you_text" /> Enviado após a resposta do contato.</p></div>
<div style="margin-top:12px;"><label for="permission_denied_text">Texto de recusa</label><textarea id="permission_denied_text" name="permission_denied_text" rows="3">{{ old('permission_denied_text', $flow->permission_denied_text) }}</textarea><p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="permission_denied_text" /> Enviado quando o contato não autorizar a pesquisa.</p></div>
<div class="grid grid-3" style="margin-top:12px;">
    <div><label for="max_main_questions">Perguntas principais</label><input id="max_main_questions" name="max_main_questions" type="number" min="1" max="10" value="{{ old('max_main_questions', $flow->max_main_questions ?? 1) }}"><p class="muted">Quantas perguntas da pesquisa cada conversa recebe.</p></div>
    <div><label for="max_followups">Perguntas de aprofundamento</label><input id="max_followups" name="max_followups" type="number" min="0" max="10" value="{{ old('max_followups', $flow->max_followups ?? 0) }}"><p class="muted">Geradas por IA a partir da resposta, depois das perguntas da pesquisa.</p></div>
    <div><label for="validity_hours">Validade (horas)</label><input id="validity_hours" name="validity_hours" type="number" min="1" max="8760" value="{{ old('validity_hours', $flow->validity_hours ?? 48) }}"></div>
</div>
@php $ordemAtual = old('question_order', ($flow->question_order ?? \App\Enums\ConversationQuestionOrder::Sorteio)->value ?? 'sorteio'); @endphp
<div style="margin-top:12px;">
    <label for="question_order">Ordem das perguntas</label>
    <select id="question_order" name="question_order">
        @foreach(\App\Enums\ConversationQuestionOrder::cases() as $ordem)
            <option value="{{ $ordem->value }}" @selected($ordemAtual === $ordem->value)>{{ $ordem->label() }}</option>
        @endforeach
    </select>
    <p class="muted">
        @foreach(\App\Enums\ConversationQuestionOrder::cases() as $ordem)
            <strong>{{ $ordem->label() }}:</strong> {{ $ordem->description() }}@if(! $loop->last)<br>@endif
        @endforeach
    </p>
</div>
<label style="display:flex;gap:8px;align-items:center;font-weight:400;margin-top:12px;">
    <input type="checkbox" name="transparency_enabled" value="1" @checked(old('transparency_enabled', $flow->transparency_enabled ?? true)) style="width:auto;min-height:auto;">
    Avisar que a mensagem e automática
</label>
<div style="margin-top:12px;"><label for="transparency_text">Texto de transparência</label><textarea id="transparency_text" name="transparency_text" rows="2">{{ old('transparency_text', $flow->transparency_text) }}</textarea></div>
