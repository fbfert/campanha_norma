# Etapa 5 - Fila de Processamento e Controle dos Envios

## Por que

Os lotes preparados na Etapa 4 precisam passar a ser processados de forma assíncrona e controlada, respeitando limites de envio, janela de horário, estado da conexão WhatsApp, pausas, retomadas, paradas, tentativas e auditoria.

## O que muda

- Configura Redis e Laravel Queue para processamento em fila dedicada de mensagens.
- Amplia estados de lotes e destinatários para o ciclo completo de processamento.
- Adiciona configurações de envio, limite por minuto/hora/dia, intervalo mínimo, janela por horário/dia e política de retry.
- Cria ações, jobs, comandos Artisan, eventos, tentativas e tela de acompanhamento.
- Mantém o envio sempre via abstração `WhatsAppProvider`.
- Atualiza dashboard, menu, permissões, documentação operacional e auditoria.

## Fora do escopo

- Caixa de entrada, leitura de respostas, chatbot, anexos, grupos, múltiplas contas, API pública, API oficial da Meta, relatórios avançados e automações condicionais.
