## 1. Especificação

- [x] 1.1 Ler README, documentação de conversas, mensagens recebidas, filas e monitoramento.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` aplicáveis.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9A.
- [x] 1.4 Validar com OpenSpec.

## 2. Dados, enums e modelos

- [x] 2.1 Criar migration de fluxos, perguntas, estado, transições e uso de perguntas.
- [x] 2.2 Adicionar associação opcional de fluxo em lotes e origem em mensagens de conversa.
- [x] 2.3 Criar enums de status de fluxo, estagio e classificação de permissão.
- [x] 2.4 Criar models, relacionamentos e factories.

## 3. Regras deterministicas

- [x] 3.1 Implementar classificador determinístico de permissão com listas configuráveis.
- [x] 3.2 Implementar seletor de pergunta com peso, exclusão de usadas, transação e trava.
- [x] 3.3 Implementar guarda de automação com todas as condições de bloqueio.
- [x] 3.4 Implementar maquina de estados com registro de transições.
- [x] 3.5 Implementar serviço de resposta automática reaproveitando o caminho de envio existente.

## 4. Jobs e integração

- [x] 4.1 Implementar job de avaliação idempotente em fila própria.
- [x] 4.2 Implementar job de envio automático em fila própria.
- [x] 4.3 Despachar avaliação após commit no processamento de mensagens recebidas.
- [x] 4.4 Ativar o estado ao concluir o envio de destinatário de campanha vinculada a fluxo.

## 5. Administração, permissões e configurações

- [x] 5.1 Criar permissões, gates, papeis e menu.
- [x] 5.2 Criar CRUD de fluxos e de perguntas.
- [x] 5.3 Criar tela de estado das conversas com pausar, retomar, encerrar e assumir.
- [x] 5.4 Marcar mensagens automáticas na linha do tempo da conversa.
- [x] 5.5 Semear configurações `conversation_automation.*` desligadas por padrão.

## 6. Validação

- [x] 6.1 Criar testes unitários do classificador e das regras puras.
- [x] 6.2 Criar testes de feature dos seis critérios de aceitação.
- [x] 6.3 Criar testes de idempotência, concorrência e regressão das etapas anteriores.
- [x] 6.4 Executar migrations, testes, build e Pint.
- [x] 6.5 Executar validações OpenSpec.

## 7. Documentação

- [x] 7.1 Criar documentação operacional do módulo.
- [x] 7.2 Criar roteiro de homologação manual.
- [x] 7.3 Atualizar README na seção da Etapa 9.
