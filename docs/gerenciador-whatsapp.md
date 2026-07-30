# Gerenciador de Contatos e Mensagens Iniciais pelo WhatsApp

## Fonte

Este documento consolida o planejamento de `projeto_gerenciador_whatsapp.md` para orientar implementação, validação e manutenção das specs OpenSpec.

## Objetivo

Construir uma aplicação web para organizar contatos, criar mensagens iniciais personalizadas, controlar lotes de envio pelo WhatsApp e registrar histórico completo.

A automação deve se limitar ao primeiro contato. Depois que a pessoa responder, a continuidade da conversa deve ocorrer manualmente e de forma humana pelo WhatsApp.

## Arquitetura Recomendada

- Aplicação principal em PHP 8.3+, Laravel, MySQL, Redis, Laravel Queue, Blade, Livewire e Alpine.js.
- Hospedagem em VPS com Apache, HTTPS, cron e Supervisor ou systemd.
- Serviço Node.js separado para conexão com WhatsApp Web por QR Code.
- Comunicação privada entre Laravel e Node.js via endereço local, token secreto, timeout e validação de origem.
- Laravel deve concentrar regras administrativas, filas, histórico, auditoria e provider abstraction.
- Node.js deve ficar restrito a conexão, QR Code, estado da sessão e envio pelo WhatsApp Web.

## Escopo Mínimo da Primeira Versão

- Login, usuários administradores e controle de acesso.
- Cadastro, importação, busca, filtros, etiquetas e bloqueio de duplicados em contatos.
- Lista de não contatar.
- Conexão WhatsApp Web por QR Code com persistência segura de sessão.
- Modelos de mensagem, placeholders, pre-visualização e validação antes de criar lote.
- Criação de lotes com ordem aleatória definida uma única vez.
- Limites por minuto, hora e dia, janela de horários e dias permitidos.
- Fila com iniciar, pausar, continuar, parar, retentar e cancelar destinatário.
- Status individual, histórico, snapshots, erros e logs básicos.
- Arquitetura de provedor para futura migração para API oficial.

## Itens Fora da Primeira Versão

- Caixa de entrada completa.
- Envio de anexos.
- Múltiplos números.
- Múltiplos operadores.
- Campanhas recorrentes.
- Respostas automáticas.
- Painel estatistico avancado.
- Campos personalizados.
- Automações baseadas em resposta.
- Integração com CRM.
- API pública.
- Aplicativo móvel.

## Cuidados Obrigatórios

- A integração por WhatsApp Web e temporária e deve permitir substituição futura pela API oficial.
- O sistema não deve fazer disparos em massa.
- Mensagens com placeholders obrigatórios sem valor devem ser bloqueadas antes de entrar na fila.
- Contatos bloqueados ou em lista de não contatar nunca devem receber novos lotes.
- A sessão do WhatsApp deve ser protegida como credencial e nunca ficar em diretório público.
- Logs não devem conter tokens, QR Codes, sessões completas ou informações sensíveis desnecessárias.
- Toda tentativa de envio deve ser auditável e idempotente por `request_id`.

## Specs OpenSpec

As specs aprovadas ficam em:

```text
openspec/specs/
```

Capacidades atuais:

- `project-foundation`
- `contact-management`
- `whatsapp-connection`
- `message-authoring`
- `batch-queue`
- `history-compliance`
