# Tarefas — Etapa 10

## 1. Especificação

- [x] 1.1 Ler CLAUDE.md, `docs/padroes-de-interface.md`, `docs/ortografia.md`, `docs/inbound-attendance.md` e as specs aprovadas.
- [x] 1.2 Reconciliar o plano da etapa com os commits `a851867` e `17b5955`, que criaram o atendimento de entrada.
- [x] 1.3 Criar proposta, design e deltas de spec da Etapa 10.
- [x] 1.4 Validar com `openspec validate --specs` e `openspec validate --all --json`.

## 2. Nome do remetente

- [x] 2.1 Preencher `sender_name` em `forwardIncoming`, preferindo o nome da agenda e caindo para o nome de perfil.
- [x] 2.2 Aparar e limitar a 120 caracteres; string vazia vira `null`.
- [x] 2.3 Declarar os campos usados em `src/types/WhatsAppService.ts`, sem alargar o contrato.
- [x] 2.4 Capturar falha ao obter o contato, registrar no logger e seguir com `null`.
- [x] 2.5 Testes no serviço Node: nome de agenda, só nome de perfil, nenhum dos dois, erro ao obter contato.
- [x] 2.6 Teste no Laravel garantindo que o nome recebido é gravado em `sender_name_snapshot` e que a ausência não quebra o processamento.

## 3. Fundação de dados

- [x] 3.1 Migration `keyword_campaigns` com vigência, limite, teto por hora, textos e situação.
- [x] 3.2 Migration `keyword_campaign_participations` com `conversation_message_id` obrigatório e índice único em `(keyword_campaign_id, contact_id)`.
- [x] 3.3 Migration `keyword_campaign_draws` com hash da lista, semente, quantidade e resultado.
- [x] 3.4 Índices para os filtros de tela por situação e por elegibilidade.
- [x] 3.5 Enums `KeywordCampaignStatus`, `KeywordParticipationStatus` e `KeywordParticipationEligibility`.
- [x] 3.6 Case `Gatilho` em `ContactSource`.
- [x] 3.7 Models com casts, relações e escopos de vigência e de pendência de conferência.
- [x] 3.8 Permissões no enum, gates registrados e atribuição aos papéis no seeder.
- [x] 3.9 `down()` seguro em todas as migrations.

## 4. Casamento de palavra-chave

- [x] 4.1 Extrair a normalização e o casamento por palavra inteira de `InboundAttendanceRouter` para um ponto comum, sem alterar o comportamento do roteador.
- [x] 4.2 Criar o serviço de casamento, lendo o texto escrito e não a transcrição.
- [x] 4.3 Devolver qual palavra casou, para gravar na participação.
- [x] 4.4 Expor o cálculo de quase-casamento a distância 1, que não decide nada.
- [x] 4.5 Testes da tabela de casos, incluindo os casos que deliberadamente não casam.

## 5. Participação e criação de contato

- [x] 5.1 Serviço de registro de participação, com os motivos de recusa nomeados.
- [x] 5.2 Criação de contato com origem `gatilho`, consentimento concedido, finalidade registrada e etiqueta da campanha.
- [x] 5.3 Reaproveitar contato existente sem sobrescrever o nome cadastrado.
- [x] 5.4 Telefone ambíguo entra como `em_revisao` e não conta como válido.
- [x] 5.5 Tratar a violação do índice único como já inscrito, sem erro.
- [x] 5.6 Registrar em `ContactHistory` e no log de auditoria.
- [x] 5.7 Barreira de finalidade em `ContactSelectionService`, com inclusão apenas por marcação explícita.
- [x] 5.8 Testes de criação, reaproveitamento, ambiguidade, concorrência e barreira.

## 6. Gatilho no pipeline de entrada

- [x] 6.1 Avaliar as campanhas vigentes em `EvaluateConversationFlowJob`, antes do roteamento.
- [x] 6.2 Encerrar cedo e barato quando não houver campanha vigente, com cache curto da lista.
- [x] 6.3 Não escrever em `conversation_flow_states` e não mover `last_processed_message_id`.
- [x] 6.4 Suprimir a abertura do atendimento de entrada para a mensagem atendida pela campanha.
- [x] 6.5 Ignorar eco de mensagem enviada, mensagem de grupo e mensagem sem texto.
- [x] 6.6 Registrar evento de campanha em cada registro.
- [x] 6.7 Teste crítico: conversa em `waiting_answer` registra participação e mantém o estágio intacto.
- [x] 6.8 Testes de duas campanhas vigentes com a mesma palavra e de supressão da abertura.

## 7. Confirmação e contenção de rajada

- [x] 7.1 Limitador global de confirmação com incremento atômico, teto por minuto e intervalo mínimo.
- [x] 7.2 Chaves em `system_settings` com padrão seguro.
- [x] 7.3 Adiar o excedente em vez de descartar.
- [x] 7.4 Dispensar a janela de horário, com o motivo comentado no código.
- [x] 7.5 Texto de já inscrito e texto de fora de vigência, este último opcional.
- [x] 7.6 Alarme e evento ao atingir o teto por hora da campanha.
- [x] 7.7 Consumir a cota apenas quando o envio sai.
- [x] 7.8 Testes de rajada, de concorrência entre workers e de envio fora da janela.

## 8. Telas

- [x] 8.1 Lista de campanhas com situação, vigência, inscritos e pendentes de conferência.
- [x] 8.2 Criação e edição, com aviso para palavra curta demais ou comum demais.
- [x] 8.3 Lista de participantes com busca, filtros e paginação.
- [x] 8.4 Edição de nome capturado preservando o valor original.
- [x] 8.5 Invalidação com motivo obrigatório, sem apagar a participação.
- [x] 8.6 Exportação reaproveitando a anonimização, a expiração e o disco privado da 9E.
- [x] 8.7 Entradas em `app/Support/Breadcrumbs.php` e conformidade com `PadraoDeInterfaceTest`.
- [x] 8.8 Testes de permissão por tela e de recusa de invalidação sem motivo.

## 9. Elegibilidade e congelamento

- [x] 9.1 Importação de CSV de alunos que marca participações, sem criar contato e sem filtrar inscrição.
- [x] 9.2 Importação idempotente, com cabeçalho sem acento.
- [x] 9.3 Fila de conferência com marcação individual e em lote, gravando quem conferiu e quando.
- [x] 9.4 Congelamento recusado enquanto houver participação não conferida, informando quantas faltam.
- [x] 9.5 Hash estável da lista congelada, contendo apenas participação válida e aluno confirmado.
- [x] 9.6 Invalidação após o congelamento não altera a lista congelada.
- [x] 9.7 Testes de marcação, idempotência, nono dígito, recusa e estabilidade do hash.

## 10. Sorteio e cupons

- [x] 10.1 Corrigir a derivação de semente sem alterar o comportamento do sorteio de lote.
- [x] 10.2 Sorteio apenas sobre lista congelada, com registro completo.
- [x] 10.3 Tela de resultado permitindo reproduzir a verificação.
- [x] 10.4 Tabela de cupons com índice único em campanha e código.
- [x] 10.5 Importação de lote de cupons idempotente.
- [x] 10.6 Atribuição transacional, sem que dois ganhadores recebam o mesmo código.
- [x] 10.7 Recusa de sorteio com cupons insuficientes, informando quantos faltam.
- [x] 10.8 Cupom fora de log, de exportação e do histórico em claro.
- [x] 10.9 Envio do cupom pelo mesmo limitador da confirmação.
- [x] 10.10 Testes de reprodutibilidade, recusa, concorrência e não vazamento.

## 11. Comandos e documentação

- [x] 11.1 `campanhas:reprocessar`, idempotente, com `--campanha`, `--from`, `--to` e `--dry-run`.
- [x] 11.2 `campanhas:diagnosticar`.
- [x] 11.3 `campanhas:quase-casamentos`.
- [x] 11.4 Não agendar nenhum comando sem justificativa escrita em `routes/console.php`.
- [x] 11.5 `docs/gatilhos-de-palavra-chave.md`, incluindo a seção de operação.
- [x] 11.6 README com escopo implementado e não implementado da Etapa 10.

## 13. Pesquisa a partir da inscrição

- [x] 13.1 Campo `conversation_flow_id` na campanha, opcional, com `survey_invite_text`.
- [x] 13.2 Confirmação e convite emendados numa mensagem só.
- [x] 13.3 Fluxo aberto só depois de a confirmação ter sido entregue.
- [x] 13.4 Palavra-chave marcada como processada, para não virar resposta à permissão.
- [x] 13.5 Conversa já com fluxo recebe só a confirmação.
- [x] 13.6 Corrigir a ligação entre o contato criado e a conversa, sem a qual número
      desconhecido ficava inscrito e sem confirmação.
- [x] 13.7 Testes do caminho completo, da palavra-chave até a pergunta sair.
- [x] 13.8 Documentar as duas chaves da automação de que a pesquisa depende.

## 12. Fechamento

- [x] 12.1 `php artisan test`, incluindo `OrtografiaTest` e `PadraoDeInterfaceTest`.
- [x] 12.2 `./vendor/bin/pint`.
- [x] 12.3 `cd whatsapp-service && npm run lint && npm test`.
- [x] 12.4 `openspec validate --all --json`.
- [x] 12.5 Marcar as tarefas concluídas.
- [ ] 12.6 Arquivar a mudança — **deliberadamente pendente**. O repositório arquivou
      apenas as etapas 1 a 4; da 5 à 9E, todas concluídas e em produção, seguem em
      `changes/`. Arquivar só a 10 promoveria `keyword-campaigns` para
      `openspec/specs/` deixando as capacidades da 9A à 9E fora, o que descreveria
      errado a fonte de verdade. Quando a fila for arquivada em bloco:
      `openspec archive etapa-10-gatilhos-palavra-chave`.
