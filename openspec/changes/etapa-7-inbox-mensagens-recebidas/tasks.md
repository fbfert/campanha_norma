## 1. Especificação

- [x] 1.1 Criar proposta OpenSpec da Etapa 7.
- [x] 1.2 Validar a proposta com OpenSpec.

## 2. Dados, configuração e permissões

- [x] 2.1 Criar migrations para conversas, mensagens, eventos, atribuições, etiquetas e notas.
- [x] 2.2 Adicionar campos de resposta em contatos e configurações de inbox/webhook/retenção.
- [x] 2.3 Criar enums, models, factories e permissões.

## 3. Webhook, processamento e interrupção

- [x] 3.1 Implementar assinatura HMAC, validação de timestamp/nonce e endpoint interno.
- [x] 3.2 Implementar normalização, idempotência, matching de contato e resolução de conversa.
- [x] 3.3 Implementar interrupção de envios pendentes por resposta.

## 4. Caixa de entrada e resposta manual

- [x] 4.1 Implementar listagem/detalhe da caixa de entrada, leitura, atribuição, status, prioridade, arquivamento e contato associado.
- [x] 4.2 Implementar notas, etiquetas e bloqueio/não-contatar.
- [x] 4.3 Implementar resposta manual por job usando `WhatsAppProvider`.

## 5. Node.js, monitoramento e documentação

- [x] 5.1 Alterar o serviço Node.js para encaminhar mensagens recebidas assinadas.
- [x] 5.2 Atualizar dashboard, relatório básico de conversas, monitoramento, scheduler e comandos.
- [x] 5.3 Atualizar README e criar documentação operacional.

## 6. Validação

- [x] 6.1 Criar testes Laravel e Node.js principais.
- [x] 6.2 Executar migrations, rotas, testes, build, lint, Pint e OpenSpec.
