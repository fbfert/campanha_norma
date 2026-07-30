## 1. Especificacao

- [x] 1.1 Ler README, documentacao das subetapas 9A e 9B, filas, monitoramento e implantacao.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` e as mudancas 9A e 9B.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9C.
- [x] 1.4 Validar com OpenSpec.

## 2. Dados, enums e models

- [x] 2.1 Criar migration da caixa de sugestoes com unicidade de sugestao viva.
- [x] 2.2 Adicionar metadados de IA em `conversation_messages`.
- [x] 2.3 Adicionar contador de aprofundamentos e modo de resposta por fluxo.
- [x] 2.4 Criar enums de modo, acao, status, tipo de aprofundamento, motivo de handoff e feedback.
- [x] 2.5 Criar models, relacionamentos e factories.

## 3. Servico de saida unificado

- [x] 3.1 Criar `ConversationReplyService` com elegibilidade, snapshots, auditoria e despacho.
- [x] 3.2 Fazer o envio manual delegar sem alterar comportamento nem mensagens de erro.
- [x] 3.3 Fazer o envio automatico da 9A delegar.
- [x] 3.4 Garantir regressao do envio manual por teste.

## 4. Geracao, validacao e guards

- [x] 4.1 Criar contrato `ConversationResponseGenerator` e implementacao por provedor.
- [x] 4.2 Criar prompt versionado e schema da resposta com as seis acoes.
- [x] 4.3 Criar montador de contexto restrito a propria conversa.
- [x] 4.4 Criar validador deterministico do texto gerado.
- [x] 4.5 Criar resolucao de modo efetivo entre global e fluxo.
- [x] 4.6 Criar servico de sugestao com debounce, limite de turnos e encerramento.
- [x] 4.7 Criar guards de autoenvio limitado com registro do motivo de cada decisao.
- [x] 4.8 Criar servico de handoff humano com os motivos exigidos.
- [x] 4.9 Criar job em fila propria.

## 5. Interface, permissoes e observabilidade

- [x] 5.1 Criar caixa de aprovacao com editar, aprovar, rejeitar, regenerar e assumir.
- [x] 5.2 Bloquear aprovacao de sugestao obsoleta e proibir aprovacao em massa.
- [x] 5.3 Criar feedback operacional por sugestao.
- [x] 5.4 Exibir selo de origem e autoria de IA na linha do tempo.
- [x] 5.5 Criar permissoes, gates, papeis e menu.
- [x] 5.6 Semear configuracoes `ai.response.*` desligadas por padrao.

## 6. Validacao

- [x] 6.1 Criar testes unitarios do validador de texto e da resolucao de modo.
- [x] 6.2 Criar testes de feature dos criterios de aceitacao com HTTP fake.
- [x] 6.3 Criar testes de sugestao obsoleta, aprovacao concorrente e autoenvio duplicado.
- [x] 6.4 Criar testes de limite de turnos, handoff, opt-out e contato inativado entre geracao e envio.
- [x] 6.5 Criar testes dos quatro modos de operacao e de regressao da resposta manual.
- [x] 6.6 Executar migrations, testes, build e Pint.
- [x] 6.7 Executar validacoes OpenSpec.

## 7. Documentacao

- [x] 7.1 Criar documentacao operacional da subetapa.
- [x] 7.2 Criar roteiro de homologacao manual.
- [x] 7.3 Atualizar README na secao da Etapa 9.
