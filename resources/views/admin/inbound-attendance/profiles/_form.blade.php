<div class="card">
    <h2>1. Identificação</h2>
    <div class="grid grid-2">
        <div>
            <label for="name">Nome do perfil</label>
            <input id="name" name="name" value="{{ old('name', $profile->name ?? '') }}" required>
        </div>
        <div>
            <label for="status">Situação</label>
            <select id="status" name="status">
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $profile->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div style="margin-top:12px;">
        <label for="description">Descrição</label>
        <textarea id="description" name="description" rows="2">{{ old('description', $profile->description ?? '') }}</textarea>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h2>2. Quando este perfil atende</h2>
    <p class="muted">
        As expressões são comparadas com o texto que a pessoa escreveu, sem acento, sem pontuação e sem caixa,
        casando por palavra ou frase inteira — <code>voto</code> não casa dentro de <code>devoto</code>. Uma por linha.
    </p>
    <label for="match_expressions">Expressões</label>
    <textarea id="match_expressions" name="match_expressions" rows="6">{{ old('match_expressions', isset($profile) ? implode("\n", $profile->matchExpressionList()) : '') }}</textarea>

    <div class="grid grid-2" style="margin-top:12px;">
        <div>
            <label for="match_priority">Ordem de avaliação</label>
            <input id="match_priority" name="match_priority" type="number" min="1" max="9999"
                   value="{{ old('match_priority', $profile->match_priority ?? 100) }}">
            <p class="muted">Menor número é avaliado primeiro. Dois perfis que pegam a mesma palavra: vence o de número menor.</p>
        </div>
        <div>
            <label for="is_fallback">
                <input id="is_fallback" name="is_fallback" type="checkbox" value="1" @checked(old('is_fallback', $profile->is_fallback ?? false))>
                Atender o que sobrou
            </label>
            <p class="muted">
                Ninguém escreve pensando na nossa lista de expressões, e quem escreve algo fora dela é quem mais
                precisa de resposta. Exatamente um perfil ativo carrega esta marca.
            </p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h2>3. O que dizer na abertura</h2>
    <label for="opening_mode">Modo de abertura</label>
    <select id="opening_mode" name="opening_mode">
        @foreach($modes as $mode)
            <option value="{{ $mode->value }}" @selected(old('opening_mode', $profile->opening_mode?->value ?? 'ai_then_survey') === $mode->value)>{{ $mode->label() }}</option>
        @endforeach
    </select>
    @foreach($modes as $mode)
        <p class="muted"><strong>{{ $mode->label() }}:</strong> {{ $mode->description() }}</p>
    @endforeach

    <label for="presentation_text" style="margin-top:12px;">Texto de apresentação</label>
    <textarea id="presentation_text" name="presentation_text" rows="5" maxlength="4000">{{ old('presentation_text', $profile->presentation_text ?? '') }}</textarea>
    <p class="muted" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <x-emoji-picker target="presentation_text" />
        Vazio usa o texto de apresentação do próprio fluxo. Aceita os mesmos placeholders do lote — contato recém-criado
        tem apenas <code>{nome}</code>, <code>{primeiro_nome}</code> e <code>{telefone}</code>.
    </p>

    <label for="conversation_flow_id" style="margin-top:12px;">Fluxo conversacional</label>
    <select id="conversation_flow_id" name="conversation_flow_id" required>
        <option value="">Escolha um fluxo</option>
        @foreach($flows as $flow)
            <option value="{{ $flow->id }}" @selected((string) old('conversation_flow_id', $profile->conversation_flow_id ?? '') === (string) $flow->id)>
                {{ $flow->name }} — {{ $flow->active_questions_count }} {{ $flow->active_questions_count === 1 ? 'pergunta ativa' : 'perguntas ativas' }}
            </option>
        @endforeach
    </select>
    @if($flows->isEmpty())
        <p class="muted" style="margin-top:8px;">
            Nenhum fluxo ativo cadastrado. Crie um em <a href="{{ route('admin.conversation-flows.index') }}">Fluxos conversacionais</a>.
        </p>
    @endif
</div>

<div class="card" style="margin-top:16px;">
    <h2>4. Travas</h2>
    <div class="grid grid-2">
        <div>
            <label for="window_start">Início da janela</label>
            <input id="window_start" name="window_start" type="time" value="{{ old('window_start', $profile->window_start ?? '') }}">
        </div>
        <div>
            <label for="window_end">Fim da janela</label>
            <input id="window_end" name="window_end" type="time" value="{{ old('window_end', $profile->window_end ?? '') }}">
        </div>
    </div>
    <p class="muted">Os dois vazios usam a janela geral da automação. Fora da janela nada sai sozinho, e a conversa espera na fila.</p>

    <div class="grid grid-2" style="margin-top:12px;">
        <div>
            <label for="daily_start_limit">Teto diário deste perfil</label>
            <input id="daily_start_limit" name="daily_start_limit" type="number" min="0" max="10000"
                   value="{{ old('daily_start_limit', $profile->daily_start_limit ?? 50) }}">
            <p class="muted">Zero remove o teto. Atingido o teto, as conversas seguintes vão para a fila em vez de sair.</p>
        </div>
        <div>
            <label for="homologation_threshold">Conversas para homologar</label>
            <input id="homologation_threshold" name="homologation_threshold" type="number" min="0" max="1000"
                   value="{{ old('homologation_threshold', $profile->homologation_threshold ?? 5) }}">
            <p class="muted">
                Enquanto o perfil não acumular esse tanto de conversas iniciadas por uma pessoa, nada sai sozinho.
                O primeiro dia é onde se descobre que a expressão pegou o que não devia. Zero dispensa a homologação.
            </p>
        </div>
    </div>
</div>
