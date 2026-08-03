# Geração de respostas e aprovação humana — Etapa 9C

Geração de resposta contextualizada para **aprofundar a opinião da própria pessoa**. O modo padrão e sugerir para aprovação humana. Nenhum texto gerado chega ao contato sem aprovação explícita, salvo autoenvio deliberadamente habilitado e sob todos os guards.

## Objetivo conversacional

A resposta reconhece brevemente o ponto, permanece neutra, faz **no máximo uma pergunta** e usa apenas o que a própria pessoa escreveu. Não tenta mudar voto, não promete ação, não afirma proposta inexistente e não simula intimidade.

## Modos de operação

```text
disabled           nao gera nada, nenhuma chamada ao provedor
draft_only         gera e armazena; nenhum envio possivel
approval_required  gera e aguarda aprovacao humana individual
auto_send_limited  permite autoenvio, apenas sob todos os guards
```

Ordenados por permissividade. **O modo efetivo e o menor entre o global e o do fluxo**: um fluxo pode restringir, nunca ampliar. Desligar globalmente e um botão de parada real.

```text
ai.response.mode                 global, padrao `disabled`
conversation_flows.response_mode por fluxo, nulo herda o global
```

## Ações do contrato

```text
suggest_reply          reconhece e pergunta uma vez
request_clarification  pede o basico antes de aprofundar
thank_and_complete     agradece e encerra
handoff_human          encaminha para a equipe
no_reply               nada a responder
opt_out                a pessoa pediu para parar
```

Somente as três primeiras produzem texto. `suggest_reply` e `request_clarification` contam como aprofundamento quando enviadas.

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

## Onde esta a garantia de "uma sugestão valida por mensagem"

MySQL não possui índice único parcial. A tabela usa uma coluna espelho anulável:

```text
active_source_message_id = source_message_id   enquanto pending ou approved
active_source_message_id = NULL                quando sent, rejected, superseded, expired, blocked ou failed
```

com `UNIQUE (active_source_message_id)`. MySQL aceita múltiplos `NULL` em índice único — comportamento verificado empiricamente em MariaDB 10.5 — então o efeito e exatamente "no máximo uma sugestão viva por mensagem recebida", sem impedir o histórico de regenerações.

## Obsolescência

Uma sugestão fica obsoleta quando chega mensagem recebida mais nova que a de origem. A verificação compara identificadores no momento da aprovação e do autoenvio.

Não usamos tempo como critério principal: uma sugestão de dez minutos atrás continua valida se a pessoa não escreveu mais nada, e uma de dez segundos atrás já e invalida se ela escreveu. Existe um teto de validade (`ai.response.validity_minutes`), mas ele e limite, não critério.

## Texto gerado e texto final

`generated_text` nunca e sobrescrito. A edição do operador vai para `final_text`. O que e enviado e `final_text ?? generated_text`. Isso permite responder depois: o modelo estava bom, ou o operador consertou?

O texto editado também passa pelo validador antes de sair.

## Validador determinístico

Roda depois do modelo, sempre. Reprova por:

```text
texto_vazio                     mais_de_uma_pergunta
texto_muito_longo               mensagem_longa_demais
promessa                        pedido_de_voto
comparacao_com_adversarios      urgencia_artificial
intimidade_simulada             alegacao_de_leitura_pessoal
coleta_de_dado_pessoal
```

As listas ficam em `ai.response.forbidden.*`, editáveis sem deploy. Texto reprovado nunca e enviado, nem automaticamente nem por aprovação.

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

`ai.response.auto_send_classifications` nasce **vazia**: nenhuma categoria e elegível até alguém preencher deliberadamente.

## Handoff humano

Motivos: pedido explícito, pergunta factual sem base aprovada, denuncia ou acusação, ameaca, pedido de ajuda individual, assunto juridico, promessa ou compromisso, baixa confiança, conteúdo hostil, midia não suportada, conflito de contexto, limite de turnos e falha repetida do provedor.

Ao encaminhar: pausa a automação, muda o estagio para `waiting_human`, eleva a prioridade quando o motivo indica risco, cria evento, exibe o motivo e invalida sugestões vivas. **Nunca envia texto improvisado.**

## Sem base factual aprovada

Não existe base de conhecimento nesta subetapa. Pergunta factual sobre a Professora Norma tem dois destinos configuráveis em `ai.response.factual_behavior`:

```text
handoff        encaminha para a equipe (padrao)
institutional  responde com `ai.response.institutional_text`, texto fixo
```

O modelo nunca preenche essa lacuna. O prompt declara explicitamente que ele não possui informação factual.

## Serviço de saída unificado

`ConversationReplyService` concentra elegibilidade, criação da mensagem pendente, `request_id` único, snapshots, evento e auditoria. Manual, automático da 9A e aprovado por IA passam pelo mesmo caminho, com origens distintas:

```text
manual       resposta escrita por uma pessoa
automation   pergunta e textos da 9A
approved_ai  sugestao aprovada ou autoenviada
```

As validações próprias do envio manual continuam em `ManualReplyService` e seu comportamento não mudou.

### Placeholders

`createPending` resolve os placeholders do contato antes de criar a mensagem, para as três origens. Antes so a automação renderizava, e um `{cidade}` escrito a mão numa resposta manual — ou copiado pelo modelo do texto da pergunta — chegava literal no WhatsApp da pessoa.

Campo vazio no contato interrompe o envio com erro de validação em vez de mandar a chave crua. A automação nunca chega nesse erro: ela verifica antes, registra `automation_placeholder_missing` e simplesmente não envia.

## Quando a geração não acontece

A 9C não gera quando o motor determinístico acabou de responder a mesma mensagem — estágio `question_selected` ou `waiting_answer` com o motor tendo rodado. É o caso do "sim": quem responde a autorização e a 9A, mandando a pergunta cadastrada, e uma sugestão de IA para a mesma mensagem nasceria genérica, entupindo a caixa de aprovação sem poder ser autoenviada, porque `permission_yes` não esta na allowlist.

Com a 9A desligada ou bloqueada (`flowEngineRan` falso), a 9C continua gerando: a independência entre as etapas e preservada.

## Metadados na linha do tempo

```text
generated_by_ai                 ai_run_id
ai_prompt_version               ai_confidence
approved_by                     approved_at
automation_state_transition_id
```

Colunas relacionais, não JSON: precisam ser filtráveis pelos relatórios da subetapa seguinte. A timeline exibe selo identificando a assistência de IA e quem aprovou.

## Filas

```text
ai-response-generation   geracao da sugestao
ai-response-send         envio da resposta aprovada
```

Adicionar ao worker:

```bash
php artisan queue:work --queue=whatsapp-incoming,whatsapp-messages,whatsapp-manual-replies,whatsapp-conversation-sync,whatsapp-maintenance,conversation-automation,conversation-automation-send,ai-interpretation,ai-response-generation,ai-response-send,default
```

## Configurações

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

## Permissões

```text
reply_suggestions.view              reply_suggestions.approve
reply_suggestions.reject            reply_suggestions.regenerate
reply_suggestions.feedback          reply_suggestions.manage_settings
```

- Administrador: todas.
- Operador: `view`, `reject` e `feedback`. **Não aprova.**
- Consulta: apenas `view`.

Aprovar e enviar exige permissão própria, separada de visualizar.

## Caixa de aprovação

`/admin/reply-suggestions` lista sugestões pendentes com a mensagem da pessoa, a pergunta original, o resumo, o tema, a confiança e o motivo de revisão.

- Editar antes de enviar.
- Aprovar e enviar, individualmente.
- Rejeitar com motivo.
- Regenerar com justificativa obrigatória.
- Assumir manualmente, pausando a automação.
- Registrar feedback.

**Aprovação em massa existe, e não nasceu com o sistema.** A ausência era deliberada, pelo motivo que continua valendo: uma tela que aprova cinquenta sugestões com um clique transforma revisão humana em carimbo. O botão foi acrescentado por decisão de quem opera a campanha, depois de a objeção ter sido apresentada.

O que o código garante e que ele não seja uma porta lateral:

```text
cada sugestao passa por approveAndSend, uma a uma
todos os guards continuam valendo: obsolescencia, validador de
texto, elegibilidade do contato, janela de horario, fundamentacao
obsoletas ficam de fora, porque seriam recusadas de qualquer forma
a confirmacao mostra quantas serao enviadas
a tela informa quantas foram recusadas e por que
`conversation_response.bulk_approved` registra o total na auditoria
```

Ao lado dele ha **Descartar obsoletas**, que tira da fila o que perdeu a validade porque a pessoa escreveu de novo. Esse não envia nada: marca como substituída, com o motivo gravado.

O que nenhum dos dois faz e dispensar leitura. Numa conversa por WhatsApp, sugestão que espera aprovação humana tende a nascer obsoleta — se a fila cresce, o sinal e que o autoenvio sob guards deveria cobrir mais casos, e não que a revisão deveria ser mais rápida.

## Feedback operacional

O operador marca a sugestão como boa, ruim ou inadequada, com motivo opcional. O valor fica armazenado com autor e data.

Nenhum processo automático le esse campo para ajustar prompt, modelo, threshold ou allowlist. Promover aprendizado exige nova versão de prompt no repositório, revisada em diff.

## Rollback

```bash
# Remove apenas a 9C.
php artisan migrate:rollback --step=1 --force
```

O `down()` remove a tabela de sugestões, os metadados de IA em `conversation_messages`, os contadores em `conversation_flow_states` e o modo por fluxo. Sugestões são dados derivados: descarta-las não afeta o histórico de conversas nem as mensagens já enviadas.

## Solução de problemas

- **Nada e gerado**: confirmar `ai.enabled`, `ai.analysis_enabled`, `ai.response.mode` diferente de `disabled`, o worker da fila `ai-response-generation` e a existência de estado de fluxo na conversa.
- **Sugestão criada mas botão de aprovar desabilitado**: ler o motivo exibido no topo da tela. Obsoleta, expirada, contato inelegível ou modo `draft_only`.
- **Autoenvio nunca dispara**: `ai.response.auto_send_classifications` vazia bloqueia tudo por padrão. Conferir também a janela de horário da 9A e a atribuição da conversa.
- **Tudo caindo em handoff**: revisar as categorias da 9B que forcam encaminhamento e os thresholds de confiança.
- **Texto sempre reprovado**: as listas em `ai.response.forbidden.*` podem estar amplas demais; conferir o motivo exato em `validation_error`.

## Não implementado nesta subetapa

- Recuperação vetorial e base de conhecimento oficial (9D).
- Relatórios analíticos finais (9E).
- Conversa aberta fora do fluxo da pesquisa.
- Aprendizado automático a partir de feedback ou correções.
