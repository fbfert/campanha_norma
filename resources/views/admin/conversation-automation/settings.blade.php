<x-layouts.app title="Configuração da automação" breadcrumbs="Inicio / Pesquisa conversacional / Automacao / Configuracao">
    <section class="card">
        <p class="muted">
            Estas chaves decidem se o sistema responde sozinho a quem responde a um lote. Elas valem para toda a base:
            não ha aqui nada que se aplique a uma conversa só. Para pausar uma conversa específica, use a tela da
            <a href="{{ route('admin.conversation-automation.index') }}">pesquisa conversacional</a>.
        </p>
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Situação atual</h2>
        <p>
            <strong>Motor:</strong> {{ $diagnostico['ligada'] ? 'ligado' : 'desligado' }} |
            <strong>Envio automático:</strong> {{ $diagnostico['envio_automatico'] ? 'liberado' : 'bloqueado' }} |
            <strong>Fluxos ativos:</strong> {{ $diagnostico['fluxos_ativos'] }} |
            <strong>Lotes vinculados a um fluxo:</strong> {{ $diagnostico['lotes_com_fluxo'] }}
        </p>

        @if($diagnostico['fluxos_ativos'] === 0)
            <p class="alert warning">Nenhum fluxo ativo cadastrado. Sem fluxo, nenhum lote ativa automação — cadastre um em <a href="{{ route('admin.conversation-flows.index') }}">Fluxos conversacionais</a>.</p>
        @elseif($diagnostico['fluxos_sem_pergunta'] > 0)
            <p class="alert warning">{{ $diagnostico['fluxos_sem_pergunta'] }} fluxo(s) ativo(s) sem nenhuma pergunta ativa. Quem responder "sim" cai no comportamento escolhido em "Sem pergunta disponível", e não recebe pergunta nenhuma.</p>
        @endif

        @if($diagnostico['lotes_com_fluxo'] === 0)
            <p class="alert warning">Nenhum lote vinculado a um fluxo. O vínculo e feito no formulário do lote, em "3. Resposta automática": sem ele, o envio não abre fluxo nenhum e todas as respostas vão para atendimento humano.</p>
        @endif

        <p class="muted">
            Filas usadas: <code>{{ $diagnostico['filas'][0] }}</code> e <code>{{ $diagnostico['filas'][1] }}</code>.
            Elas não são editáveis por esta tela: nome de fila que nenhum worker consome não dá erro, apenas emudece a
            automação. Confirme que o <code>queue:work</code> em produção consome as duas.
        </p>
    </section>

    <form method="post" action="{{ route('admin.conversation-automation.settings.update') }}">
        @csrf
        @method('put')

        <section class="card" style="margin-top:16px;">
            <h2>Chaves gerais</h2>
            <p>
                <label style="font-weight:400;">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $form['enabled']) == '1') style="width:auto;min-height:auto;">
                    Automação ligada — avaliar as respostas recebidas
                </label>
            </p>
            <p>
                <label style="font-weight:400;">
                    <input type="checkbox" name="auto_send_enabled" value="1" @checked(old('auto_send_enabled', $form['auto_send_enabled']) == '1') style="width:auto;min-height:auto;">
                    Envio automático liberado — mandar a mensagem sem revisão humana
                </label>
            </p>
            <p class="muted">
                Ligar só a primeira faz o sistema classificar e registrar as respostas sem enviar nada. É o modo de
                homologação: da para conferir a classificação antes de deixar o sistema falar com alguém.
            </p>
            <p>
                <label style="font-weight:400;">
                    <input type="checkbox" name="mark_do_not_contact_on_refusal" value="1" @checked(old('mark_do_not_contact_on_refusal', $form['mark_do_not_contact_on_refusal']) == '1') style="width:auto;min-height:auto;">
                    Marcar "não contatar" quando a pessoa recusa participar
                </label>
            </p>
            <p class="muted">
                Recusar responder uma pergunta não e o mesmo que pedir para não receber mais mensagens. Pedido explícito
                de interrupção já sai da base pelas expressões de opt-out, independentemente desta opção.
            </p>
        </section>

        <section class="card" style="margin-top:16px;">
            <h2>Limites e janela</h2>
            <div class="grid grid-2">
                <div>
                    <label for="max_automated_messages">Máximo de mensagens automáticas por conversa</label>
                    <input id="max_automated_messages" name="max_automated_messages" type="number" min="1" max="10" value="{{ old('max_automated_messages', $form['max_automated_messages']) }}" required>
                </div>
                <div>
                    <label for="default_validity_hours">Validade padrão do fluxo (horas)</label>
                    <input id="default_validity_hours" name="default_validity_hours" type="number" min="1" max="720" value="{{ old('default_validity_hours', $form['default_validity_hours']) }}" required>
                </div>
                <div>
                    <label for="short_answer_max_words">Limite de palavras para resposta curta</label>
                    <input id="short_answer_max_words" name="short_answer_max_words" type="number" min="1" max="50" value="{{ old('short_answer_max_words', $form['short_answer_max_words']) }}" required>
                    <p class="muted">Acima disso o texto e tratado como ambíguo, sem tentativa de aproximação.</p>
                </div>
                <div>
                    <label for="min_response_interval_seconds">Intervalo mínimo antes de responder (segundos)</label>
                    <input id="min_response_interval_seconds" name="min_response_interval_seconds" type="number" min="0" max="3600" value="{{ old('min_response_interval_seconds', $form['min_response_interval_seconds']) }}" required>
                </div>
                <div>
                    <label for="window_start">Início da janela de envio</label>
                    <input id="window_start" name="window_start" type="time" value="{{ old('window_start', $form['window_start']) }}" required>
                </div>
                <div>
                    <label for="window_end">Fim da janela de envio</label>
                    <input id="window_end" name="window_end" type="time" value="{{ old('window_end', $form['window_end']) }}" required>
                    <p class="muted">Início igual ao fim significa sem restrição de horário. Janela que cruza a meia-noite e aceita.</p>
                </div>
            </div>
        </section>

        <section class="card" style="margin-top:16px;">
            <h2>Comportamento</h2>
            <div class="grid grid-2">
                <div>
                    <label for="ambiguous_behavior">Resposta ambígua</label>
                    <select id="ambiguous_behavior" name="ambiguous_behavior">
                        <option value="waiting_human" @selected(old('ambiguous_behavior', $form['ambiguous_behavior']) === 'waiting_human')>Encaminhar para atendimento humano</option>
                        <option value="keep_waiting" @selected(old('ambiguous_behavior', $form['ambiguous_behavior']) === 'keep_waiting')>Continuar aguardando resposta</option>
                    </select>
                </div>
                <div>
                    <label for="no_question_behavior">Sem pergunta disponível</label>
                    <select id="no_question_behavior" name="no_question_behavior">
                        <option value="waiting_human" @selected(old('no_question_behavior', $form['no_question_behavior']) === 'waiting_human')>Encaminhar para atendimento humano</option>
                        <option value="completed" @selected(old('no_question_behavior', $form['no_question_behavior']) === 'completed')>Encerrar a conversa</option>
                    </select>
                </div>
                <div>
                    <label for="transparency_mode">Aviso de automação</label>
                    <select id="transparency_mode" name="transparency_mode">
                        <option value="none" @selected(old('transparency_mode', $form['transparency_mode']) === 'none')>Sem aviso</option>
                        <option value="prefix" @selected(old('transparency_mode', $form['transparency_mode']) === 'prefix')>Antes da mensagem</option>
                        <option value="suffix" @selected(old('transparency_mode', $form['transparency_mode']) === 'suffix')>Depois da mensagem</option>
                    </select>
                </div>
                <div>
                    <label for="transparency_text">Texto do aviso</label>
                    <input id="transparency_text" name="transparency_text" maxlength="500" value="{{ old('transparency_text', $form['transparency_text']) }}">
                </div>
            </div>
        </section>

        <section class="card" style="margin-top:16px;">
            <h2>Textos automáticos</h2>
            <div>
                <label for="thank_you_text">Agradecimento final</label>
                <textarea id="thank_you_text" name="thank_you_text" rows="2" maxlength="1000" required>{{ old('thank_you_text', $form['thank_you_text']) }}</textarea>
            </div>
            <div style="margin-top:12px;">
                <label for="permission_denied_text">Recusa de participação</label>
                <textarea id="permission_denied_text" name="permission_denied_text" rows="2" maxlength="1000" required>{{ old('permission_denied_text', $form['permission_denied_text']) }}</textarea>
            </div>
            <div style="margin-top:12px;">
                <label for="opt_out_text">Confirmação de opt-out</label>
                <textarea id="opt_out_text" name="opt_out_text" rows="2" maxlength="1000" required>{{ old('opt_out_text', $form['opt_out_text']) }}</textarea>
            </div>
        </section>

        <section class="card" style="margin-top:16px;">
            <h2>Expressões</h2>
            <p class="muted">
                Uma expressão por linha. A comparação ignora caixa, acento, pontuação e emoji, e casa palavra ou frase
                inteira. A ordem de precedência e opt-out, depois negativa, depois positiva.
            </p>
            <div>
                <label for="yes_expressions">Positivas — autorizam a pergunta</label>
                <textarea id="yes_expressions" name="yes_expressions" rows="6" maxlength="4000" required>{{ old('yes_expressions', $form['yes_expressions']) }}</textarea>
            </div>
            <div style="margin-top:12px;">
                <label for="no_expressions">Negativas — encerram com agradecimento</label>
                <textarea id="no_expressions" name="no_expressions" rows="6" maxlength="4000" required>{{ old('no_expressions', $form['no_expressions']) }}</textarea>
            </div>
            <div style="margin-top:12px;">
                <label for="opt_out_expressions">Opt-out — removem o contato da base</label>
                <p class="alert warning">
                    Expressão colocada aqui marca o contato como "não contatar" e interrompe os lotes pendentes dele.
                    Termo que indica assunto sensível, e não pedido de interrupção, não pertence a esta lista: "denúncia"
                    já esteve aqui e removia da base quem só queria ser atendido.
                </p>
                <textarea id="opt_out_expressions" name="opt_out_expressions" rows="6" maxlength="4000" required>{{ old('opt_out_expressions', $form['opt_out_expressions']) }}</textarea>
            </div>
        </section>

        <section class="card" style="margin-top:16px;">
            <button class="btn" type="submit">Salvar configuração</button>
        </section>
    </form>

    <form method="post" action="{{ route('admin.conversation-automation.settings.thresholds') }}">
        @csrf
        @method('put')

        <section class="card" style="margin-top:16px;">
            <h2>Limiares de confiança da IA</h2>
            <p class="muted">
                Confiança e o modelo avaliando a própria resposta, de 0 a 1. Ele erra para cima: um texto
                fluente e errado costuma vir com confiança alta. Estes números filtram o descarado, não o
                plausível e errado &mdash; por isso baixá-los troca revisão humana por risco, e não por
                agilidade.
            </p>

            <h3>Resposta gerada</h3>
            <p class="muted">Os dois números abaixo formam três faixas:</p>
            <ul class="muted">
                <li>abaixo do limiar de revisão &mdash; sinalizada, nunca autoenviada;</li>
                <li>entre os dois &mdash; gerada normalmente, mas espera aprovação humana;</li>
                <li>a partir do limiar de autoenvio &mdash; pode sair sozinha, se os outros guards passarem.</li>
            </ul>
            <div class="grid grid-2">
                <div>
                    <label for="ai_response_min_confidence">Revisão obrigatória abaixo de</label>
                    <input id="ai_response_min_confidence" name="ai_response_min_confidence" type="number" step="0.01" min="0" max="1" value="{{ old('ai_response_min_confidence', $limiares['ai_response_min_confidence']) }}" required>
                </div>
                <div>
                    <label for="ai_response_auto_send_min_confidence">Autoenvio permitido a partir de</label>
                    <input id="ai_response_auto_send_min_confidence" name="ai_response_auto_send_min_confidence" type="number" step="0.01" min="0" max="1" value="{{ old('ai_response_auto_send_min_confidence', $limiares['ai_response_auto_send_min_confidence']) }}" required>
                    <p class="muted">É este que decide se um texto chega ao contato sem ninguém ler.</p>
                </div>
            </div>

            <h3 style="margin-top:16px;">Interpretação</h3>
            <div class="grid grid-2">
                <div>
                    <label for="ai_min_classification_confidence">Classificação abaixo disso pede revisão</label>
                    <input id="ai_min_classification_confidence" name="ai_min_classification_confidence" type="number" step="0.01" min="0" max="1" value="{{ old('ai_min_classification_confidence', $limiares['ai_min_classification_confidence']) }}" required>
                    <p class="muted">Como o sistema entendeu a intenção da mensagem.</p>
                </div>
                <div>
                    <label for="ai_min_extraction_confidence">Extração abaixo disso pede revisão</label>
                    <input id="ai_min_extraction_confidence" name="ai_min_extraction_confidence" type="number" step="0.01" min="0" max="1" value="{{ old('ai_min_extraction_confidence', $limiares['ai_min_extraction_confidence']) }}" required>
                    <p class="muted">Tema, resumo e demanda tirados do que a pessoa escreveu.</p>
                </div>
            </div>

            <h3 style="margin-top:16px;">Relatórios</h3>
            <div>
                <label for="analytics_low_confidence_threshold">Marcar como baixa confiança abaixo de</label>
                <input id="analytics_low_confidence_threshold" name="analytics_low_confidence_threshold" type="number" step="0.01" min="0" max="1" value="{{ old('analytics_low_confidence_threshold', $limiares['analytics_low_confidence_threshold']) }}" required style="max-width:200px;">
                <p class="muted">Só sinaliza o dado como frágil nos relatórios. Não interfere em envio nenhum.</p>
            </div>

            <button class="btn" type="submit" style="margin-top:16px;">Salvar limiares</button>
        </section>
    </form>
</x-layouts.app>
