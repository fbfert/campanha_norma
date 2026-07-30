## 1. Especificação

- [x] 1.1 Ler README, documentação das subetapas 9A e 9B, filas, monitoramento e implantação.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` e as mudanças 9A e 9B.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9C.
- [x] 1.4 Validar com OpenSpec.

## 2. Dados, enums e models

- [x] 2.1 Criar migration da caixa de sugestões com unicidade de sugestão viva.
- [x] 2.2 Adicionar metadados de IA em `conversation_messages`.
- [x] 2.3 Adicionar contador de aprofundamentos e modo de resposta por fluxo.
- [x] 2.4 Criar enums de modo, ação, status, tipo de aprofundamento, motivo de handoff e feedback.
- [x] 2.5 Criar models, relacionamentos e factories.

## 3. Serviço de saída unificado

- [x] 3.1 Criar `ConversationReplyService` com elegibilidade, snapshots, auditoria e despacho.
- [x] 3.2 Fazer o envio manual delegar sem alterar comportamento nem mensagens de erro.
- [x] 3.3 Fazer o envio automático da 9A delegar.
- [x] 3.4 Garantir regressão do envio manual por teste.

## 4. Geração, validação e guards

- [x] 4.1 Criar contrato `ConversationResponseGenerator` e implementação por provedor.
- [x] 4.2 Criar prompt versionado e schema da resposta com as seis ações.
- [x] 4.3 Criar montador de contexto restrito a própria conversa.
- [x] 4.4 Criar validador determinístico do texto gerado.
- [x] 4.5 Criar resolução de modo efetivo entre global e fluxo.
- [x] 4.6 Criar serviço de sugestão com debounce, limite de turnos e encerramento.
- [x] 4.7 Criar guards de autoenvio limitado com registro do motivo de cada decisão.
- [x] 4.8 Criar serviço de handoff humano com os motivos exigidos.
- [x] 4.9 Criar job em fila própria.

## 5. Interface, permissões e observabilidade

- [x] 5.1 Criar caixa de aprovação com editar, aprovar, rejeitar, regenerar e assumir.
- [x] 5.2 Bloquear aprovação de sugestão obsoleta e proibir aprovação em massa.
- [x] 5.3 Criar feedback operacional por sugestão.
- [x] 5.4 Exibir selo de origem e autoria de IA na linha do tempo.
- [x] 5.5 Criar permissões, gates, papeis e menu.
- [x] 5.6 Semear configurações `ai.response.*` desligadas por padrão.

## 6. Validação

- [x] 6.1 Criar testes unitários do validador de texto e da resolução de modo.
- [x] 6.2 Criar testes de feature dos critérios de aceitação com HTTP fake.
- [x] 6.3 Criar testes de sugestão obsoleta, aprovação concorrente e autoenvio duplicado.
- [x] 6.4 Criar testes de limite de turnos, handoff, opt-out e contato inativado entre geração e envio.
- [x] 6.5 Criar testes dos quatro modos de operação e de regressão da resposta manual.
- [x] 6.6 Executar migrations, testes, build e Pint.
- [x] 6.7 Executar validações OpenSpec.

## 7. Documentação

- [x] 7.1 Criar documentação operacional da subetapa.
- [x] 7.2 Criar roteiro de homologação manual.
- [x] 7.3 Atualizar README na seção da Etapa 9.
