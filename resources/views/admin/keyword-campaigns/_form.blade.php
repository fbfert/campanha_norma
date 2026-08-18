<div class="card">
    <h2>1. Identificação</h2>
    <div class="grid grid-2">
        <div>
            <label for="name">Nome da campanha</label>
            <input id="name" name="name" value="{{ old('name', $campaign->name ?? '') }}" required>
        </div>
        <div>
            <label for="status">Situação</label>
            <select id="status" name="status">
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $campaign->status?->value ?? 'rascunho') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            <p class="muted">
                Em rascunho a campanha não pega ninguém, mesmo dentro do período. Encerrada, ela para de reconhecer a
                própria palavra e não responde mais nada.
            </p>
        </div>
    </div>
    <div style="margin-top:12px;">
        <label for="description">Descrição</label>
        <textarea id="description" name="description" rows="2">{{ old('description', $campaign->description ?? '') }}</textarea>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h2>2. Palavras que inscrevem</h2>
    <p class="muted">
        Uma por linha. A comparação ignora caixa, acento, pontuação e emoji, e casa por palavra ou frase inteira —
        <code>sorte</code> não casa dentro de <code>sorteio</code>. Basta uma palavra da lista, não todas.
    </p>
    <p class="muted">
        Não há tolerância a erro de digitação: <code>sorteios</code> é uma palavra diferente de <code>sorteio</code>.
        Cadastre as duas se quiser as duas.
    </p>
    <p class="muted">
        Áudio não inscreve ninguém, nem transcrito. Inscrição criada por engano de transcrição é indistinguível, no
        banco, de uma de verdade.
    </p>
    <label for="keywords">Palavras-chave</label>
    <textarea id="keywords" name="keywords" rows="6" required>{{ old('keywords', $keywordsTexto) }}</textarea>

    @if($avisos !== [])
        <div class="alert warning" style="margin-top:12px;">
            <ul>
                @foreach($avisos as $aviso)
                    <li>{{ $aviso }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

<div class="card" style="margin-top:16px;">
    <h2>3. Vigência e tetos</h2>
    <div class="grid grid-2">
        <div>
            <label for="starts_at">Início</label>
            <input id="starts_at" name="starts_at" type="datetime-local"
                   value="{{ old('starts_at', $campaign->starts_at?->format('Y-m-d\TH:i')) }}" required>
        </div>
        <div>
            <label for="ends_at">Fim</label>
            <input id="ends_at" name="ends_at" type="datetime-local"
                   value="{{ old('ends_at', $campaign->ends_at?->format('Y-m-d\TH:i')) }}" required>
        </div>
    </div>
    <div class="grid grid-2" style="margin-top:12px;">
        <div>
            <label for="participant_limit">Limite de participantes</label>
            <input id="participant_limit" name="participant_limit" type="number" min="1"
                   value="{{ old('participant_limit', $campaign->participant_limit) }}">
            <p class="muted">Em branco é sem limite. Atingido o limite, quem escrever não é inscrito e não recebe resposta.</p>
        </div>
        <div>
            <label for="hourly_alert_threshold">Alarme por hora</label>
            <input id="hourly_alert_threshold" name="hourly_alert_threshold" type="number" min="1"
                   value="{{ old('hourly_alert_threshold', $campaign->hourly_alert_threshold) }}">
            <p class="muted">
                Não freia nada — o freio é o teto global de confirmações. Serve para alguém descobrir que a divulgação
                pegou mais do que se esperava enquanto ainda está acontecendo.
            </p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h2>4. Pesquisa depois da inscrição</h2>
    <p class="muted">
        Em branco, a campanha só sorteia: inscreve, confirma e não pergunta nada. Escolhendo um fluxo, quem se
        inscrever recebe, na mesma mensagem da confirmação, o pedido de permissão para uma pergunta — e daí em diante
        quem conduz é o motor da pesquisa, com interpretação por IA da resposta.
    </p>
    <label for="conversation_flow_id">Fluxo conversacional</label>
    <select id="conversation_flow_id" name="conversation_flow_id">
        <option value="">Não abrir pesquisa</option>
        @foreach($flows as $fluxo)
            <option value="{{ $fluxo->id }}" @selected((int) old('conversation_flow_id', $campaign->conversation_flow_id) === $fluxo->id)>
                {{ $fluxo->name }} — {{ $fluxo->active_questions_count }} {{ $fluxo->active_questions_count === 1 ? 'pergunta ativa' : 'perguntas ativas' }}
            </option>
        @endforeach
    </select>
    <p class="muted">
        As perguntas ficam no fluxo, em Pesquisa → Fluxos conversacionais, com peso, ordem e versão. A campanha só
        aponta para ele.
    </p>

    <div style="margin-top:12px;">
        <label for="survey_invite_text">Convite da pesquisa</label>
        <textarea id="survey_invite_text" name="survey_invite_text" rows="3">{{ old('survey_invite_text', $campaign->survey_invite_text ?? '') }}</textarea>
        <p class="muted">
            Em branco, usa o texto de apresentação do fluxo. Preencha quando a frase precisar mudar por causa do
            sorteio: "além disso, posso te fazer uma pergunta?" lê diferente de uma abertura fria.
        </p>
    </div>

    <p class="muted">
        Quem já está no meio de outra pesquisa recebe só a confirmação. Abrir uma segunda faria duas perguntas
        concorrerem na mesma conversa — a inscrição no sorteio continua valendo.
    </p>
</div>

<div class="card" style="margin-top:16px;">
    <h2>5. O que a pessoa recebe</h2>
    <div>
        <label for="confirmation_text">Inscrição aceita</label>
        <textarea id="confirmation_text" name="confirmation_text" rows="3" required>{{ old('confirmation_text', $campaign->confirmation_text ?? '') }}</textarea>
        <p class="muted">
            Sai mesmo fora do horário da automação de conversas. Quem escreve às 23h está com o celular na mão, e
            segurar até as 8h produz a segunda e a terceira mensagem da mesma pessoa perguntando se deu certo.
        </p>
    </div>
    <div style="margin-top:12px;">
        <label for="already_enrolled_text">Já estava inscrito</label>
        <textarea id="already_enrolled_text" name="already_enrolled_text" rows="3" required>{{ old('already_enrolled_text', $campaign->already_enrolled_text ?? '') }}</textarea>
    </div>
    <div style="margin-top:12px;">
        <label for="out_of_window_text">Fora da vigência</label>
        <textarea id="out_of_window_text" name="out_of_window_text" rows="3">{{ old('out_of_window_text', $campaign->out_of_window_text ?? '') }}</textarea>
        <p class="muted">
            Em branco é silêncio deliberado. Preenchido, uma campanha ainda ativa cujo período já passou continua
            reconhecendo a própria palavra só para dizer que acabou — sem isso, quem viu o cartaz uma semana depois
            escreve, não recebe nada, e conclui que se inscreveu.
        </p>
    </div>
</div>
