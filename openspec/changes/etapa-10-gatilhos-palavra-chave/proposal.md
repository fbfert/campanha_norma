## Why

Até a Etapa 9 toda automação nascia de um envio nosso, e o commit `a851867` corrigiu metade disso: quem escreve primeiro passou a ser atendido, com contato criado e fluxo aberto pelo `InboundAttendanceService`. O que ficou de fora é o outro uso de quem escreve primeiro — **a pessoa que escreve porque foi convidada a escrever uma palavra**.

O atendimento de entrada roteia por conteúdo, mas o que ele produz é uma conversa: um perfil, um fluxo, uma abertura. Uma captação por palavra-chave precisa de outra coisa — uma lista de inscritos, com prova de origem, conferível, congelável e sorteável. Perfil de atendimento não guarda inscrição, não tem vigência, não tem teto de participantes e não sabe dizer quem entrou primeiro.

Há ainda um limite estrutural: o roteamento de entrada só alcança conversa **sem** estado de fluxo. Quem está no meio de uma pesquisa e manda a palavra-chave hoje não é atendido por nada — e é justamente quem já demonstrou que responde.

Esta etapa acrescenta a campanha por palavra-chave: mensagem recebida vira participação registrada, com o contato criado quando o número é desconhecido e com a mensagem que a originou guardada como prova.

## What Changes

- Adicionar campanha por palavra-chave com vigência, limite de participantes, teto por hora e textos próprios de confirmação, de já inscrito e de fora de vigência.
- Adicionar participação ligada à mensagem que a originou, com unicidade por campanha e contato garantida por índice do banco.
- Avaliar o gatilho **dentro** de `EvaluateConversationFlowJob`, antes da decisão de roteamento, sob o `Cache::lock` de conversa que o job já segura, e sem escrever em `conversation_flow_states`.
- Registrar participação também para conversa com fluxo em andamento, sem alterar o estágio nem `last_processed_message_id`.
- Casar por palavra inteira sobre texto normalizado, disparando com qualquer palavra da lista, sem IA e sem tolerância a erro de digitação.
- Ler apenas o texto escrito. Áudio transcrito **não** casa palavra-chave nesta etapa, por decisão explícita.
- Criar contato a partir da mensagem recebida com origem `gatilho`, consentimento concedido com finalidade registrada e etiqueta da campanha.
- Acrescentar barreira de finalidade ao serviço de seleção de destinatário de lote, que hoje não filtra por origem nenhuma.
- Suprimir a abertura do atendimento de entrada quando a campanha responde, para que a pessoa receba uma mensagem e não duas.
- Adicionar limitador global de confirmação com incremento atômico, teto por minuto e intervalo mínimo, com excedente adiado e nunca descartado.
- Dispensar a confirmação da janela de horário da automação de conversas.
- Adicionar marcação de elegibilidade de aluno por importação de lista, que marca participações sem filtrar inscrições.
- Adicionar fila de conferência manual e congelamento de lista condicionado à conferência concluída.
- Adicionar sorteio reproduzível sobre lista congelada, com hash da lista e semente registrados.
- Corrigir a derivação de semente do `RandomSelectionService`, que reduz a semente a 32 bits, sem alterar o comportamento observável do sorteio de lote.
- Adicionar lote de cupons importado por CSV, atribuição transacional ao ganhador e proibição de cupom em claro em log, exportação e histórico.
- Preencher o nome do remetente no serviço Node, hoje cravado como `null` no encaminhamento de mensagem recebida.
- Adicionar comandos de reprocessamento, diagnóstico e leitura de quase-casamentos.

## Impact

- Affected specs: `keyword-campaigns` (nova), `whatsapp-connection`
- Affected code: migrations de campanha, participação, sorteio e cupom; enums de situação, elegibilidade e origem de contato; models; serviço de casamento; serviço de participação; `EvaluateConversationFlowJob`; `InboundAttendanceService`; `ContactSelectionService`; limitador de confirmação; `RandomSelectionService`; controllers, rotas, views, permissões, seeders, comandos, testes e documentação; `whatsapp-service/src/services/WhatsAppClientService.ts` e `src/types/WhatsAppService.ts`.
- Não afetado: `ConversationFlowService` e nenhum estágio de fluxo da 9A; a interpretação da 9B; a geração da 9C; a base oficial da 9D; os relatórios da 9E; o roteamento por perfil do atendimento de entrada, que continua o mesmo quando nenhuma campanha casa.
- Alteração de comportamento existente, em três pontos, todos cobertos por teste:
  1. `EvaluateConversationFlowJob` passa a avaliar campanhas antes de rotear. Sem campanha vigente cadastrada, a avaliação encerra na primeira consulta e o job se comporta exatamente como hoje.
  2. Mensagem que casa palavra-chave de campanha vigente **não** abre atendimento de entrada. A supressão vale só para essa mensagem; a próxima mensagem da mesma pessoa é roteada normalmente.
  3. `ContactSelectionService` passa a excluir contato de origem `gatilho` da seleção padrão de lote. Nenhum contato existente tem essa origem no momento desta mudança, então nenhum lote já montado muda de tamanho.
- Compatibilidade: sem campanha cadastrada nada dispara. As Fases 0 a 6 podem ir para produção desligadas. A tabela `conversation_messages` já tem `sender_name_snapshot` desde a Etapa 7 e já é preenchida pelo caminho da Meta; o serviço Node passa a preenchê-la também, e ausência de nome continua sendo tratada como caso normal.
- Segurança e LGPD: a finalidade do consentimento registrado é a participação na campanha, e não o recebimento de disparo posterior — por isso a barreira de finalidade é obrigatória e fica no serviço de seleção, não na tela. O cupom é valor: não aparece em log, em mensagem de erro, em evento de auditoria nem em exportação, e o histórico guarda referência ao cupom, não o código.
- Risco operacional: divulgação bem-sucedida gera centenas de mensagens recebidas em minutos. Sem teto global, o sistema responderia todas no ritmo que o worker drenar, e pelo provedor WhatsApp Web esse é o comportamento que mais rápido leva um número a bloqueio. Um número bloqueado interrompe a operação inteira, não apenas a campanha. Daí o limitador próprio, o adiamento em vez do descarte, e o alarme de teto por hora enquanto a rajada está acontecendo.
- Risco jurídico, fora do escopo do código e registrado aqui para não se perder: distribuição gratuita de prêmio decidida por sorte é promoção comercial regida pela Lei 5.768/71 e exige autorização federal prévia. O código não decide o enquadramento; ele permite tanto sorteio quanto concurso de mérito, porque quem decide o ganhador é configuração, não estrutura.
