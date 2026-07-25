# Gerenciador de Contatos e Mensagens Iniciais pelo WhatsApp

## Fonte

Este documento consolida o planejamento de `projeto_gerenciador_whatsapp.md` para orientar implementacao, validacao e manutencao das specs OpenSpec.

## Objetivo

Construir uma aplicacao web para organizar contatos, criar mensagens iniciais personalizadas, controlar lotes de envio pelo WhatsApp e registrar historico completo.

A automacao deve se limitar ao primeiro contato. Depois que a pessoa responder, a continuidade da conversa deve ocorrer manualmente e de forma humana pelo WhatsApp.

## Arquitetura Recomendada

- Aplicacao principal em PHP 8.3+, Laravel, MySQL, Redis, Laravel Queue, Blade, Livewire e Alpine.js.
- Hospedagem em VPS com Apache, HTTPS, cron e Supervisor ou systemd.
- Servico Node.js separado para conexao com WhatsApp Web por QR Code.
- Comunicacao privada entre Laravel e Node.js via endereco local, token secreto, timeout e validacao de origem.
- Laravel deve concentrar regras administrativas, filas, historico, auditoria e provider abstraction.
- Node.js deve ficar restrito a conexao, QR Code, estado da sessao e envio pelo WhatsApp Web.

## Escopo Minimo da Primeira Versao

- Login, usuarios administradores e controle de acesso.
- Cadastro, importacao, busca, filtros, etiquetas e bloqueio de duplicados em contatos.
- Lista de nao contatar.
- Conexao WhatsApp Web por QR Code com persistencia segura de sessao.
- Modelos de mensagem, placeholders, pre-visualizacao e validacao antes de criar lote.
- Criacao de lotes com ordem aleatoria definida uma unica vez.
- Limites por minuto, hora e dia, janela de horarios e dias permitidos.
- Fila com iniciar, pausar, continuar, parar, retentar e cancelar destinatario.
- Status individual, historico, snapshots, erros e logs basicos.
- Arquitetura de provedor para futura migracao para API oficial.

## Itens Fora da Primeira Versao

- Caixa de entrada completa.
- Envio de anexos.
- Multiplos numeros.
- Multiplos operadores.
- Campanhas recorrentes.
- Respostas automaticas.
- Painel estatistico avancado.
- Campos personalizados.
- Automacoes baseadas em resposta.
- Integracao com CRM.
- API publica.
- Aplicativo movel.

## Cuidados Obrigatorios

- A integracao por WhatsApp Web e temporaria e deve permitir substituicao futura pela API oficial.
- O sistema nao deve fazer disparos em massa.
- Mensagens com placeholders obrigatorios sem valor devem ser bloqueadas antes de entrar na fila.
- Contatos bloqueados ou em lista de nao contatar nunca devem receber novos lotes.
- A sessao do WhatsApp deve ser protegida como credencial e nunca ficar em diretorio publico.
- Logs nao devem conter tokens, QR Codes, sessoes completas ou informacoes sensiveis desnecessarias.
- Toda tentativa de envio deve ser auditavel e idempotente por `request_id`.

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
