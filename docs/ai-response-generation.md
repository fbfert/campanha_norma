# Geracao de respostas e aprovacao humana — Etapa 9C

Geracao de resposta contextualizada para **aprofundar a opiniao da propria pessoa**. O modo padrao e sugerir para aprovacao humana. Nenhum texto gerado chega ao contato sem aprovacao explicita, salvo autoenvio deliberadamente habilitado e sob todos os guards.

## Objetivo conversacional

A resposta reconhece brevemente o ponto, permanece neutra, faz **no maximo uma pergunta** e usa apenas o que a propria pessoa escreveu. Nao tenta mudar voto, nao promete acao, nao afirma proposta inexistente e nao simula intimidade.

## Modos de operacao

```text
disabled           nao gera nada, nenhuma chamada ao provedor
draft_only         gera e armazena; nenhum envio possivel
approval_required  gera e aguarda aprovacao humana individual
auto_send_limited  permite autoenvio, apenas sob todos os guards
```

Ordenados por permissividade. **O modo efetivo e o menor entre o global e o do fluxo**: um fluxo pode restringir, nunca ampliar. Desligar globalmente e um botao de parada real.

```text
ai.response.mode                 global, padrao `disabled`
conversation_flows.response_mode por fluxo, nulo herda o global
```

## Acoes do contrato

```text
suggest_reply          reconhece e pergunta uma vez
request_clarification  pede o basico antes de aprofundar
thank_and_complete     agradece e encerra
handoff_human          encaminha para a equipe
no_reply               nada a responder
opt_out                a pessoa pediu para parar
```

Somente as tres primeiras produzem texto. `suggest_reply` e `request_clarification` contam como aprofundamento quando enviadas.

## Fluxo

```text
Mensagem recebida
        -> 9A decide (deterministico)
        -> evento ConversationMessageEvaluated
        -> 9B interpreta (fila propria)
        -> GenerateConversationReplyJob (fila propria, com debounce)
              agrupamento: se ja chegou mensagem mais nova, desiste
              guard: pausa, encerramento, opt-out
              categoria sensivel -> handoff, sem chamar o provedor
              limite de turnos -> agradece e conclui
              gera -> valida schema -> valida texto -> cria sugestao
              modo auto_send_limited e todos os guards -> envia
              caso contrario -> pendente na caixa de aprovacao
```

## Onde esta a garantia de "uma sugestao valida por mensagem"

MySQL nao possui indice unico parcial. A tabela usa uma coluna espelho anulavel:

```text
active_source_message_id = source_message_id   enquanto pending ou approved
active_source_message_id = NULL                quando sent, rejected, superseded, expired, blocked ou failed
```

com `UNIQUE (active_source_message_id)`. MySQL aceita multiplos `NULL` em indice unico — comportamento verificado empiricamente em MariaDB 10.5 — entao o efeito e exatamente "no maximo uma sugestao viva por mensagem recebida", sem impedir o historico de regeneracoes.

## Obsolescencia

Uma sugestao fica obsoleta quando chega mensagem recebida mais nova que a de origem. A verificacao compara identificadores no momento da aprovacao e do autoenvio.

Nao usamos tempo como criterio principal: uma sugestao de dez minutos atras continua valida se a pessoa nao escreveu mais nada, e uma de dez segundos atras ja e invalida se ela escreveu. Existe um teto de validade (`ai.response.validity_minutes`), mas ele e limite, nao criterio.

## Texto gerado e texto final

`generated_text` nunca e sobrescrito. A edicao do operador vai para `final_text`. O que e enviado e `final_text ?? generated_text`. Isso permite responder depois: o modelo estava bom, ou o operador consertou?

O texto editado tambem passa pelo validador antes de sair.

## Validador deterministico

Roda depois do modelo, sempre. Reprova por:

```text
texto_vazio                     mais_de_uma_pergunta
texto_muito_longo               mensagem_longa_demais
promessa                        pedido_de_voto
comparacao_com_adversarios      urgencia_artificial
intimidade_simulada             alegacao_de_leitura_pessoal
coleta_de_dado_pessoal
```

As listas ficam em `ai.response.forbidden.*`, editaveis sem deploy. Texto reprovado nunca e enviado, nem automaticamente nem por aprovacao.

## Guards do autoenvio

Todos precisam valer, e o motivo de cada recusa e registrado em `conversation_events`:

```text
modo efetivo permite autoenvio          categoria na allowlist
confianca acima do threshold            sem sinalizacao de revisao
contato elegivel                        conversa nao atribuida a humano
dentro da janela de horario             limite de turnos nao excedido
mensagem de origem ainda e a ultima     nenhuma outra mensagem pendente
trava obtida                            texto aprovado no validador
```

`ai.response.auto_send_classifications` nasce **vazia**: nenhuma categoria e elegivel ate alguem preencher deliberadamente.

## Handoff humano

Motivos: pedido explicito, pergunta factual sem base aprovada, denuncia ou acusacao, ameaca, pedido de ajuda individual, assunto juridico, promessa ou compromisso, baixa confianca, conteudo hostil, midia nao suportada, conflito de contexto, limite de turnos e falha repetida do provedor.

Ao encaminhar: pausa a automacao, muda o estagio para `waiting_human`, eleva a prioridade quando o motivo indica risco, cria evento, exibe o motivo e invalida sugestoes vivas. **Nunca envia texto improvisado.**

## Sem base factual aprovada

Nao existe base de conhecimento nesta subetapa. Pergunta factual sobre a Professora Norma tem dois destinos configuraveis em `ai.response.factual_behavior`:

```text
handoff        encaminha para a equipe (padrao)
institutional  responde com `ai.response.institutional_text`, texto fixo
```

O modelo nunca preenche essa lacuna. O prompt declara explicitamente que ele nao possui informacao factual.

## Servico de saida unificado

`ConversationReplyService` concentra elegibilidade, criacao da mensagem pendente, `request_id` unico, snapshots, evento e auditoria. Manual, automatico da 9A e aprovado por IA passam pelo mesmo caminho, com origens distintas:

```text
manual       resposta escrita por uma pessoa
automation   pergunta e textos da 9A
approved_ai  sugestao aprovada ou autoenviada
```

As validacoes proprias do envio manual continuam em `ManualReplyService` e seu comportamento nao mudou.

## Metadados na linha do tempo

```text
generated_by_ai                 ai_run_id
ai_prompt_version               ai_confidence
approved_by                     approved_at
automation_state_transition_id
```

Colunas relacionais, nao JSON: precisam ser filtraveis pelos relatorios da subetapa seguinte. A timeline exibe selo identificando a assistencia de IA e quem aprovou.

## Filas

```text
ai-response-generation   geracao da sugestao
ai-response-send         envio da resposta aprovada
```

Adicionar ao worker:

```bash
php artisan queue:work --queue=whatsapp-incoming,whatsapp-messages,whatsapp-manual-replies,whatsapp-conversation-sync,whatsapp-maintenance,conversation-automation,conversation-automation-send,ai-interpretation,ai-response-generation,ai-response-send,default
```

## Configuracoes

```text
ai.response.mode                        disabled
ai.response.queue                       ai-response-generation
ai.response.send_queue                  ai-response-send
ai.response.prompt_version              v1
ai.response.schema_version              1
ai.response.min_confidence              0.75
ai.response.auto_send_min_confidence    0.90
ai.response.auto_send_classifications   (vazia)
ai.response.auto_send_when_assigned     0
ai.response.max_followups               2
ai.response.debounce_seconds            20
ai.response.validity_minutes            120
ai.response.max_text_length             500
ai.response.max_lines                   4
ai.response.max_input_chars             1500
ai.response.max_context_messages        4
ai.response.factual_behavior            handoff
ai.response.institutional_text          texto
ai.response.forbidden.*                 listas de expressoes proibidas
```

## Permissoes

```text
reply_suggestions.view              reply_suggestions.approve
reply_suggestions.reject            reply_suggestions.regenerate
reply_suggestions.feedback          reply_suggestions.manage_settings
```

- Administrador: todas.
- Operador: `view`, `reject` e `feedback`. **Nao aprova.**
- Consulta: apenas `view`.

Aprovar e enviar exige permissao propria, separada de visualizar.

## Caixa de aprovacao

`/admin/reply-suggestions` lista sugestoes pendentes com a mensagem da pessoa, a pergunta original, o resumo, o tema, a confianca e o motivo de revisao.

- Editar antes de enviar.
- Aprovar e enviar, individualmente.
- Rejeitar com motivo.
- Regenerar com justificativa obrigatoria.
- Assumir manualmente, pausando a automacao.
- Registrar feedback.

**Nao existe aprovacao em massa.** A ausencia e deliberada: uma tela que aprova cinquenta sugestoes com um clique transforma revisao humana em carimbo.

## Feedback operacional

O operador marca a sugestao como boa, ruim ou inadequada, com motivo opcional. O valor fica armazenado com autor e data.

Nenhum processo automatico le esse campo para ajustar prompt, modelo, threshold ou allowlist. Promover aprendizado exige nova versao de prompt no repositorio, revisada em diff.

## Rollback

```bash
# Remove apenas a 9C.
php artisan migrate:rollback --step=1 --force
```

O `down()` remove a tabela de sugestoes, os metadados de IA em `conversation_messages`, os contadores em `conversation_flow_states` e o modo por fluxo. Sugestoes sao dados derivados: descarta-las nao afeta o historico de conversas nem as mensagens ja enviadas.

## Solucao de problemas

- **Nada e gerado**: confirmar `ai.enabled`, `ai.analysis_enabled`, `ai.response.mode` diferente de `disabled`, o worker da fila `ai-response-generation` e a existencia de estado de fluxo na conversa.
- **Sugestao criada mas botao de aprovar desabilitado**: ler o motivo exibido no topo da tela. Obsoleta, expirada, contato inelegivel ou modo `draft_only`.
- **Autoenvio nunca dispara**: `ai.response.auto_send_classifications` vazia bloqueia tudo por padrao. Conferir tambem a janela de horario da 9A e a atribuicao da conversa.
- **Tudo caindo em handoff**: revisar as categorias da 9B que forcam encaminhamento e os thresholds de confianca.
- **Texto sempre reprovado**: as listas em `ai.response.forbidden.*` podem estar amplas demais; conferir o motivo exato em `validation_error`.

## Nao implementado nesta subetapa

- Recuperacao vetorial e base de conhecimento oficial (9D).
- Relatorios analiticos finais (9E).
- Conversa aberta fora do fluxo da pesquisa.
- Aprendizado automatico a partir de feedback ou correcoes.
