<div class="card">
    <h2>1. Identificação</h2>
    <div class="grid grid-2">
        <div><label for="name">Nome do lote</label><input id="name" name="name" value="{{ old('name', $batch->name ?? '') }}" required></div>
        <div><label for="selection_type">Seleção</label><select id="selection_type" name="selection_type">@foreach($selectionTypes as $type)<option value="{{ $type->value }}" @selected(old('selection_type', $batch->selection_type?->value ?? 'manual') === $type->value)>{{ $type->label() }}</option>@endforeach</select></div>
    </div>
    <div style="margin-top:12px;"><label for="description">Descrição</label><textarea id="description" name="description" rows="2">{{ old('description', $batch->description ?? '') }}</textarea></div>
</div>
<div class="card" style="margin-top:16px;">
    <h2>2. Mensagem</h2>
    <label for="message_template_id">Modelo</label>
    <select id="message_template_id" name="message_template_id"><option value="">Mensagem avulsa</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) old('message_template_id', $batch->message_template_id ?? '') === (string) $template->id)>{{ $template->name }} v{{ $template->version }}</option>@endforeach</select>
    <label for="message_body" style="margin-top:12px;">Mensagem avulsa ou ajuste do lote</label>
    <textarea id="message_body" name="message_body" rows="6" maxlength="4096">{{ old('message_body', $batch->message_body_snapshot ?? '') }}</textarea>
    <p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><x-emoji-picker target="message_body" /> Placeholders: @foreach($catalog as $key => $info)<button class="btn ghost" type="button" onclick="document.getElementById('message_body').value += '{{ '{'.$key.'}' }}'">{{ '{'.$key.'}' }}</button> @endforeach</p>
</div>
<div class="card" style="margin-top:16px;">
    <h2>3. Resposta automática</h2>
    @php $selectedFlow = (string) old('conversation_flow_id', $batch->conversation_flow_id ?? ''); @endphp
    <p class="muted">Ao vincular um fluxo conversacional, cada envio bem-sucedido abre o fluxo na conversa do destinatário: quem responder recebe a pergunta automática, sem intervenção do operador. Sem fluxo, o lote apenas envia e as respostas ficam para atendimento humano.</p>
    <label for="conversation_flow_id">Fluxo conversacional</label>
    <select id="conversation_flow_id" name="conversation_flow_id">
        <option value="">Sem resposta automática</option>
        @foreach($flows as $flow)
            <option value="{{ $flow->id }}" @selected($selectedFlow === (string) $flow->id)>{{ $flow->name }} — {{ $flow->questions_count }} {{ $flow->questions_count === 1 ? 'pergunta ativa' : 'perguntas ativas' }}@if($flow->status !== \App\Enums\ConversationFlowStatus::Active) ({{ $flow->status->label() }})@endif</option>
        @endforeach
    </select>
    @if($flows->isEmpty())
        <p class="muted" style="margin-top:8px;">Nenhum fluxo ativo cadastrado. Crie um em <a href="{{ route('admin.conversation-flows.index') }}">Fluxos conversacionais</a>.</p>
    @endif
</div>
<div class="card" style="margin-top:16px;">
    <h2>4. Contatos</h2>
    <p class="muted">Os filtros abaixo valem tanto para a seleção manual quanto para "todos os filtrados" e "amostra aleatória" (escolhidos em 1. Identificação).</p>
    <div><label for="random_quantity">Quantidade aleatória</label><input id="random_quantity" name="random_quantity" type="number" min="1" max="1000" value="{{ old('random_quantity') }}" style="max-width:200px;"></div>
    @livewire(\App\Http\Livewire\CampaignContactPicker::class, ['initialSelectedIds' => collect(old('contact_ids', []))->map(fn ($id) => (int) $id)->all()])
</div>
