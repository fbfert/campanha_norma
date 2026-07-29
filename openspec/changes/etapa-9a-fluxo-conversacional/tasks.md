## 1. Especificacao

- [x] 1.1 Ler README, documentacao de conversas, mensagens recebidas, filas e monitoramento.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` aplicaveis.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9A.
- [x] 1.4 Validar com OpenSpec.

## 2. Dados, enums e modelos

- [x] 2.1 Criar migration de fluxos, perguntas, estado, transicoes e uso de perguntas.
- [x] 2.2 Adicionar associacao opcional de fluxo em lotes e origem em mensagens de conversa.
- [x] 2.3 Criar enums de status de fluxo, estagio e classificacao de permissao.
- [x] 2.4 Criar models, relacionamentos e factories.

## 3. Regras deterministicas

- [x] 3.1 Implementar classificador deterministico de permissao com listas configuraveis.
- [x] 3.2 Implementar seletor de pergunta com peso, exclusao de usadas, transacao e trava.
- [x] 3.3 Implementar guarda de automacao com todas as condicoes de bloqueio.
- [x] 3.4 Implementar maquina de estados com registro de transicoes.
- [x] 3.5 Implementar servico de resposta automatica reaproveitando o caminho de envio existente.

## 4. Jobs e integracao

- [x] 4.1 Implementar job de avaliacao idempotente em fila propria.
- [x] 4.2 Implementar job de envio automatico em fila propria.
- [x] 4.3 Despachar avaliacao apos commit no processamento de mensagens recebidas.
- [x] 4.4 Ativar o estado ao concluir o envio de destinatario de campanha vinculada a fluxo.

## 5. Administracao, permissoes e configuracoes

- [x] 5.1 Criar permissoes, gates, papeis e menu.
- [x] 5.2 Criar CRUD de fluxos e de perguntas.
- [x] 5.3 Criar tela de estado das conversas com pausar, retomar, encerrar e assumir.
- [x] 5.4 Marcar mensagens automaticas na linha do tempo da conversa.
- [x] 5.5 Semear configuracoes `conversation_automation.*` desligadas por padrao.

## 6. Validacao

- [x] 6.1 Criar testes unitarios do classificador e das regras puras.
- [x] 6.2 Criar testes de feature dos seis criterios de aceitacao.
- [x] 6.3 Criar testes de idempotencia, concorrencia e regressao das etapas anteriores.
- [x] 6.4 Executar migrations, testes, build e Pint.
- [x] 6.5 Executar validacoes OpenSpec.

## 7. Documentacao

- [x] 7.1 Criar documentacao operacional do modulo.
- [x] 7.2 Criar roteiro de homologacao manual.
- [x] 7.3 Atualizar README na secao da Etapa 9.
