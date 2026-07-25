## 1. Especificacao

- [x] 1.1 Criar proposta OpenSpec da Etapa 7.
- [x] 1.2 Validar a proposta com OpenSpec.

## 2. Dados, configuracao e permissoes

- [x] 2.1 Criar migrations para conversas, mensagens, eventos, atribuicoes, etiquetas e notas.
- [x] 2.2 Adicionar campos de resposta em contatos e configuracoes de inbox/webhook/retencao.
- [x] 2.3 Criar enums, models, factories e permissoes.

## 3. Webhook, processamento e interrupcao

- [x] 3.1 Implementar assinatura HMAC, validacao de timestamp/nonce e endpoint interno.
- [x] 3.2 Implementar normalizacao, idempotencia, matching de contato e resolucao de conversa.
- [x] 3.3 Implementar interrupcao de envios pendentes por resposta.

## 4. Caixa de entrada e resposta manual

- [x] 4.1 Implementar listagem/detalhe da caixa de entrada, leitura, atribuicao, status, prioridade, arquivamento e contato associado.
- [x] 4.2 Implementar notas, etiquetas e bloqueio/nao-contatar.
- [x] 4.3 Implementar resposta manual por job usando `WhatsAppProvider`.

## 5. Node.js, monitoramento e documentacao

- [x] 5.1 Alterar o servico Node.js para encaminhar mensagens recebidas assinadas.
- [x] 5.2 Atualizar dashboard, relatorio basico de conversas, monitoramento, scheduler e comandos.
- [x] 5.3 Atualizar README e criar documentacao operacional.

## 6. Validacao

- [x] 6.1 Criar testes Laravel e Node.js principais.
- [x] 6.2 Executar migrations, rotas, testes, build, lint, Pint e OpenSpec.
