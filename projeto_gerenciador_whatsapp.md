# Projeto: Gerenciador de Contatos e Mensagens Iniciais pelo WhatsApp

**Versão do documento:** 1.0
**Data:** 24 de julho de 2026
**Objetivo:** planejar uma aplicação web para organizar contatos, criar mensagens personalizadas, controlar filas de envio e acompanhar o histórico de contatos iniciais pelo WhatsApp.

---

## 1. Visão geral

O projeto consiste em uma aplicação web para gerenciamento de contatos e envio controlado de mensagens iniciais pelo WhatsApp.

A proposta não é criar um chatbot nem automatizar conversas completas. A automação será usada somente para uma abordagem inicial, por exemplo:

> Oi {nome}, como está {cidade}?
> Sou o professor Felipe. Posso lhe fazer uma pergunta?

Depois que a pessoa responder, a continuidade da conversa ocorrerá manualmente e de forma humana pelo WhatsApp.

A primeira versão utilizará uma conexão pelo WhatsApp Web, com leitura de QR Code. Essa integração será usada para validar o projeto. Posteriormente, a camada de integração poderá ser substituída pela API oficial do WhatsApp Business, sem reconstruir o restante do sistema.

---

## 2. Objetivos do sistema

O sistema deverá permitir:

1. cadastrar e organizar pessoas;
2. pesquisar e filtrar contatos;
3. selecionar uma ou várias pessoas;
4. criar mensagens com placeholders;
5. visualizar a mensagem personalizada antes do envio;
6. embaralhar a ordem dos destinatários;
7. configurar limites por minuto, hora e dia;
8. limitar os envios a determinados dias e horários;
9. iniciar, pausar, continuar ou interromper um lote;
10. registrar o histórico completo;
11. identificar falhas e permitir novas tentativas;
12. manter uma lista de pessoas que não devem ser contatadas;
13. preparar a futura migração para a API oficial.

---

## 3. Tecnologias recomendadas

### 3.1 Aplicação principal

- PHP 8.3 ou superior;
- Laravel;
- MySQL;
- Redis;
- Laravel Queue;
- Blade;
- Livewire;
- Alpine.js;
- Apache;
- Supervisor ou systemd;
- cron do Linux.

### 3.2 Serviço de integração com WhatsApp Web

- Node.js;
- serviço separado da aplicação Laravel;
- biblioteca de integração com WhatsApp Web;
- sessão autenticada por QR Code;
- persistência segura da sessão;
- comunicação privada entre Laravel e Node.js.

### 3.3 Estrutura geral

```text
Usuário
   ↓
Painel Laravel
   ↓
MySQL
   ↓
Redis e filas
   ↓
Worker do Laravel
   ↓
Serviço Node.js
   ↓
WhatsApp Web
   ↓
Destinatário
```

O Laravel será responsável por toda a lógica administrativa. O Node.js ficará restrito à conexão e ao envio pelo WhatsApp Web.

---

## 4. Hospedagem na VPS com Apache

A aplicação poderá ser hospedada na VPS já existente com Apache.

### 4.1 Estrutura sugerida de diretórios

```text
/var/www/gerenciador-mensagens
/var/www/gerenciador-mensagens/public
/opt/whatsapp-service
```

O Apache deverá apontar o domínio ou subdomínio para:

```text
/var/www/gerenciador-mensagens/public
```

O serviço Node.js poderá funcionar apenas no endereço local:

```text
http://127.0.0.1:3100
```

Assim, ele não ficará diretamente exposto à internet.

### 4.2 Serviços necessários

- Apache;
- PHP-FPM ou módulo PHP compatível;
- MySQL;
- Redis;
- Node.js;
- Supervisor ou systemd;
- certificado HTTPS;
- firewall;
- backups;
- cron.

### 4.3 Processos permanentes

Deverão permanecer ativos:

```text
php artisan queue:work
node /opt/whatsapp-service/server.js
```

O Supervisor ou o systemd deverá reiniciar esses processos automaticamente em caso de falha ou reinicialização da VPS.

---

## 5. Módulos do sistema

### 5.1 Autenticação e usuários

O sistema deverá possuir:

- tela de login;
- recuperação de senha;
- usuários administradores;
- controle de acesso;
- registro de ações;
- opção futura de autenticação em dois fatores.

### 5.2 Dashboard

O painel inicial deverá apresentar:

- total de contatos;
- contatos ativos;
- contatos bloqueados;
- mensagens enviadas hoje;
- limite diário disponível;
- mensagens pendentes;
- mensagens com erro;
- lotes em andamento;
- status da conexão do WhatsApp;
- horário permitido para envio;
- última atividade do serviço.

---

## 6. Cadastro de pessoas

### 6.1 Campos principais

O cadastro deverá conter:

| Campo | Obrigatório | Observação |
|---|---:|---|
| Nome | Sim | Nome completo |
| Telefone | Sim | Salvo em formato internacional |
| E-mail | Não | Usado para organização |
| Cidade | Não | Pode ser usada em placeholders |
| Observações | Não | Informações internas |
| Situação | Sim | Ativo, bloqueado ou não contatar |
| Origem do contato | Não | Formulário, evento, indicação etc. |
| Autorização de contato | Recomendado | Registro de consentimento ou base aplicável |
| Data da autorização | Não | Data do aceite |
| Último contato | Automático | Atualizado após o envio |

### 6.2 Normalização do telefone

Os telefones deverão ser armazenados sem espaços, parênteses ou traços.

Exemplo:

```text
5549999999999
```

O sistema deverá:

- validar o número;
- evitar duplicidades;
- identificar telefone ausente;
- impedir envio para contato bloqueado;
- permitir atualização do número;
- registrar alterações relevantes.

### 6.3 Importação

A aplicação poderá oferecer importação por CSV ou XLSX.

Antes de concluir a importação, deverá mostrar:

- registros válidos;
- números duplicados;
- campos obrigatórios ausentes;
- telefones inválidos;
- contatos já existentes;
- registros que serão ignorados.

---

## 7. Etiquetas e segmentação

Os contatos poderão receber etiquetas, por exemplo:

- Alunos;
- Ex-alunos;
- Interessados;
- Lages;
- Brasília;
- Evento 2026;
- Pesquisa;
- Não respondeu.

As etiquetas facilitarão filtros e seleção de grupos.

---

## 8. Conexão pelo WhatsApp Web

### 8.1 Tela de conexão

A tela deverá exibir:

- QR Code;
- estado da conexão;
- número conectado;
- data da autenticação;
- última atividade;
- última falha;
- versão do serviço;
- horário da última reconexão.

### 8.2 Estados possíveis

```text
desconectado
gerando_qrcode
aguardando_leitura
autenticando
conectado
reconectando
sessao_expirada
erro
```

### 8.3 Botões

- Gerar QR Code;
- Atualizar QR Code;
- Reconectar;
- Testar conexão;
- Desconectar;
- Excluir sessão.

### 8.4 Persistência da sessão

A autenticação deverá ser armazenada fora do diretório público da aplicação.

Os arquivos da sessão não poderão ser acessados por URL.

### 8.5 Observação importante

A integração por WhatsApp Web não será tratada como a solução definitiva. Ela será usada para validar o fluxo do projeto. A arquitetura deverá permitir a futura substituição pela API oficial.

---

## 9. Modelos de mensagens

O sistema deverá possuir uma tela para cadastrar modelos.

### 9.1 Campos do modelo

- nome;
- descrição;
- texto;
- situação;
- data de criação;
- data de atualização;
- usuário responsável.

### 9.2 Exemplo

```text
Nome: Primeiro contato

Mensagem:
Oi {primeiro_nome}, como está {cidade}?

Sou o professor Felipe. Posso lhe fazer uma pergunta?
```

### 9.3 Placeholders iniciais

```text
{nome}
{primeiro_nome}
{telefone}
{email}
{cidade}
```

Futuramente poderão ser criados campos personalizados.

### 9.4 Regras de substituição

Para o contato:

```text
Nome: Mariana de Souza
Cidade: Brasília
```

O modelo:

```text
Oi {primeiro_nome}, como está {cidade}?
```

será transformado em:

```text
Oi Mariana, como está Brasília?
```

---

## 10. Tratamento de placeholders sem valor

Antes de criar o lote, o sistema deverá analisar todos os destinatários.

Quando um placeholder obrigatório estiver vazio, a regra inicial recomendada será:

> não incluir o contato no lote até que o cadastro seja corrigido.

Exemplo de aviso:

```text
Mariana de Souza não foi incluída.
Motivo: o placeholder {cidade} está sem valor.
```

A tela deverá apresentar um resumo:

```text
Selecionados: 30
Aptos: 27
Sem cidade: 2
Telefone inválido: 1
```

Futuramente poderão existir valores alternativos, como:

```text
{cidade|sua cidade}
```

Entretanto, essa função não é necessária para a primeira versão.

---

## 11. Tela de criação de envio

### 11.1 Área de contatos

A tela deverá oferecer:

- busca por nome;
- busca por telefone;
- filtro por cidade;
- filtro por etiqueta;
- filtro por situação;
- nunca contatados;
- já contatados;
- respondeu;
- não respondeu;
- com dados completos;
- sem bloqueio;
- seleção individual;
- seleção da página;
- seleção de todos os resultados filtrados.

### 11.2 Área da mensagem

Deverá conter:

- seleção de modelo;
- editor de mensagem;
- lista de placeholders;
- pré-visualização;
- contador de caracteres;
- quantidade de destinatários;
- botão para salvar rascunho;
- botão para criar lote;
- opção de agendamento.

### 11.3 Pré-visualização

Antes de confirmar, o usuário deverá poder visualizar exemplos personalizados.

```text
Contato: Mariana de Souza

Mensagem:
Oi Mariana, como está Brasília?

Sou o professor Felipe. Posso lhe fazer uma pergunta?
```

É recomendável permitir navegar por pelo menos cinco amostras do lote.

---

## 12. Seleção e ordem aleatórias

Existem duas funcionalidades diferentes.

### 12.1 Seleção aleatória

Permite escolher apenas uma quantidade dos resultados filtrados.

Exemplo:

```text
Filtro: cidade = Lages
Resultado: 240 pessoas
Selecionar aleatoriamente: 30 pessoas
```

### 12.2 Ordem aleatória

Depois que as pessoas forem escolhidas, o sistema deverá embaralhar a ordem de envio.

Exemplo:

```text
Seleção original:
Ana
Bruno
Carlos
Daniela
Eduardo

Ordem do lote:
Carlos
Eduardo
Ana
Daniela
Bruno
```

### 12.3 Regra técnica

A ordem aleatória deverá ser criada uma única vez, no momento da geração do lote.

Cada destinatário receberá uma posição:

```text
random_position
```

O worker deverá processar por:

```sql
ORDER BY random_position ASC
```

O sistema não deverá sortear novamente a ordem a cada execução, pois isso prejudicaria a retomada e a auditoria.

---

## 13. Lotes de envio

Cada operação de envio será registrada como um lote.

### 13.1 Informações do lote

- nome;
- mensagem-modelo;
- usuário criador;
- data de criação;
- data de início;
- data de conclusão;
- horário programado;
- total de destinatários;
- total enviado;
- total entregue;
- total com erro;
- total cancelado;
- situação.

### 13.2 Estados do lote

```text
rascunho
agendado
em_processamento
pausado
parando
concluido
concluido_com_erros
cancelado
```

---

## 14. Fila e regras de envio

### 14.1 Limites configuráveis

O administrador poderá definir:

- máximo por minuto;
- máximo por hora;
- máximo por dia;
- horário inicial;
- horário final;
- dias da semana permitidos;
- fuso horário;
- número máximo de tentativas;
- intervalo entre novas tentativas.

### 14.2 Exemplo

```text
Máximo por minuto: 1
Máximo por hora: 15
Máximo por dia: 40
Horário inicial: 09:00
Horário final: 18:00
Dias permitidos: segunda a sexta-feira
Fuso horário: America/Sao_Paulo
```

### 14.3 Validação antes de cada envio

Uma mensagem somente será liberada quando todas as condições forem atendidas:

```text
conexão ativa
+
lote em processamento
+
destinatário apto
+
dentro do horário permitido
+
limite por minuto disponível
+
limite por hora disponível
+
limite por dia disponível
```

### 14.4 Intervalo entre mensagens

Mesmo dentro do limite por minuto, o sistema poderá adotar um intervalo mínimo entre mensagens.

Esse intervalo deverá ser configurável e não deve substituir os limites por minuto, hora e dia.

### 14.5 Quando o limite for atingido

O destinatário continuará pendente com uma justificativa:

```text
aguardando_limite
```

ou:

```text
aguardando_horario
```

O worker retomará automaticamente quando surgir uma nova janela permitida, desde que o lote não esteja pausado ou cancelado.

---

## 15. Controles da fila

### Iniciar

Inicia um lote em rascunho ou agendado.

### Pausar

Interrompe a liberação de novas mensagens, preservando a fila.

### Continuar

Retoma o lote do ponto em que foi pausado.

### Parar

Cancela definitivamente os destinatários ainda não processados.

### Tentar novamente

Recoloca na fila mensagens com falhas técnicas elegíveis.

### Cancelar destinatário

Remove somente uma pessoa da fila.

---

## 16. Status individual dos destinatários

Cada destinatário poderá assumir um dos seguintes estados:

```text
pendente
aguardando_horario
aguardando_limite
processando
enviada
falhou
respondida
cancelada
ignorada
```

### 16.1 Informações exibidas

- nome;
- telefone;
- cidade;
- posição aleatória;
- mensagem personalizada;
- horário previsto;
- horário de envio;
- quantidade de tentativas;
- status;
- código do erro;
- mensagem do erro.

---

## 17. Histórico de envios

### 17.1 Histórico por lote

A tela deverá mostrar:

- nome do lote;
- mensagem;
- data;
- responsável;
- quantidade selecionada;
- aptos;
- enviados;
- falhas;
- cancelados;
- situação;
- duração.

### 17.2 Histórico por destinatário

Deverá apresentar:

- dados do contato no momento do envio;
- mensagem efetivamente enviada;
- posição no lote;
- tentativas;
- datas e horários;
- resultado;
- erro;
- alterações de status.

### 17.3 Snapshot

O sistema deverá guardar uma cópia dos dados utilizados no envio.

Exemplo:

```text
contact_name_snapshot
contact_phone_snapshot
contact_city_snapshot
rendered_message
```

Assim, o histórico continuará correto mesmo que o cadastro seja alterado posteriormente.

---

## 18. Erros previstos

O sistema deverá registrar erros como:

```text
telefone_invalido
placeholder_sem_valor
contato_bloqueado
whatsapp_desconectado
sessao_expirada
tempo_limite_excedido
erro_no_servico_node
falha_de_comunicacao
erro_desconhecido
```

Erros permanentes não deverão gerar repetição automática.

Erros temporários poderão ser reenviados conforme o limite de tentativas.

---

## 19. Banco de dados sugerido

### 19.1 Tabelas principais

```text
users
contacts
tags
contact_tag
message_templates
whatsapp_connections
message_batches
message_recipients
message_events
sending_settings
opt_outs
audit_logs
```

### 19.2 Tabela contacts

```text
id
name
phone
email
city
notes
status
consent_status
consent_source
consent_at
do_not_contact
last_contacted_at
created_at
updated_at
```

### 19.3 Tabela message_templates

```text
id
name
description
body
is_active
created_by
created_at
updated_at
```

### 19.4 Tabela message_batches

```text
id
name
message_template_id
message_body_snapshot
status
scheduled_at
started_at
paused_at
finished_at
created_by
created_at
updated_at
```

### 19.5 Tabela message_recipients

```text
id
message_batch_id
contact_id
random_position
status
attempts
contact_name_snapshot
contact_phone_snapshot
contact_city_snapshot
rendered_message
scheduled_at
processing_at
sent_at
failed_at
cancelled_at
error_code
error_message
external_message_id
created_at
updated_at
```

### 19.6 Tabela message_events

```text
id
message_recipient_id
event_type
payload
created_at
```

### 19.7 Tabela sending_settings

```text
id
max_per_minute
max_per_hour
max_per_day
minimum_interval_seconds
start_time
end_time
allowed_weekdays
timezone
max_attempts
retry_interval_minutes
created_at
updated_at
```

---

## 20. API interna entre Laravel e Node.js

O serviço Node.js deverá possuir uma API privada.

### 20.1 Rotas sugeridas

```text
GET  /status
GET  /qrcode
POST /connect
POST /disconnect
POST /send-message
DELETE /session
```

### 20.2 Envio de mensagem

Exemplo de solicitação:

```json
{
  "request_id": "uuid-do-envio",
  "phone": "5549999999999",
  "message": "Oi Mariana, como está Brasília?"
}
```

Exemplo de resposta:

```json
{
  "success": true,
  "request_id": "uuid-do-envio",
  "external_message_id": "identificador-externo",
  "status": "sent"
}
```

### 20.3 Segurança

A comunicação deverá usar:

- endereço local;
- token secreto;
- validação de origem;
- timeout;
- logs sem exposição da sessão;
- proteção contra envio duplicado pelo mesmo `request_id`.

---

## 21. Camada de provedor

Para facilitar a migração, o Laravel deverá utilizar uma interface abstrata:

```php
interface WhatsAppProvider
{
    public function sendMessage(
        string $phone,
        string $message
    ): SendResult;

    public function getConnectionStatus(): ConnectionStatus;

    public function disconnect(): void;
}
```

### 21.1 Primeiro provedor

```text
WhatsAppWebProvider
```

### 21.2 Futuro provedor

```text
WhatsAppCloudApiProvider
```

O restante do sistema não deverá conhecer os detalhes do provedor.

---

## 22. Segurança

O projeto deverá incluir:

- HTTPS;
- senhas com hash;
- proteção CSRF;
- controle de sessão;
- limitação de tentativas de login;
- permissões;
- logs;
- backups automáticos;
- arquivos de autenticação fora do diretório público;
- tokens criptografados;
- firewall;
- atualização periódica do servidor;
- auditoria das ações administrativas.

Os logs não deverão gravar tokens, QR Codes, sessões completas ou informações sensíveis desnecessárias.

---

## 23. LGPD e gestão dos contatos

O sistema armazenará dados pessoais, especialmente nome, telefone, e-mail e cidade.

Deverão existir mecanismos para:

- registrar a origem do dado;
- registrar a finalidade do contato;
- registrar autorização, quando aplicável;
- atender pedidos de bloqueio;
- corrigir dados;
- excluir ou anonimizar dados quando necessário;
- controlar acesso;
- registrar operações relevantes;
- definir política de retenção.

### 23.1 Lista de não contatar

O campo deverá ser destacado no cadastro:

```text
Não contatar novamente
```

Quando marcado, o sistema deverá bloquear qualquer novo lote para aquele telefone.

A lista de bloqueio deverá prevalecer mesmo que o contato seja importado novamente.

---

## 24. Fluxo completo

```text
1. Usuário acessa o painel.
2. Confere a conexão com o WhatsApp.
3. Cadastra ou importa contatos.
4. Aplica filtros.
5. Seleciona os destinatários.
6. Escolhe ou escreve uma mensagem.
7. Insere placeholders.
8. O sistema valida os dados.
9. O usuário confere as prévias.
10. O sistema cria o lote.
11. Os destinatários são embaralhados.
12. A fila verifica limites e horários.
13. O worker solicita o envio ao Node.js.
14. O resultado é salvo no histórico.
15. Falhas temporárias podem ser reenviadas.
16. O usuário acompanha a tela de status.
17. Ao receber resposta, a conversa continua manualmente.
```

---

## 25. Etapas de desenvolvimento

### Etapa 1 — Fundação

- projeto Laravel;
- autenticação;
- estrutura do banco;
- usuários;
- permissões;
- dashboard inicial.

### Etapa 2 — Contatos

- cadastro;
- edição;
- exclusão ou inativação;
- filtros;
- etiquetas;
- importação;
- validação de telefone;
- bloqueio de duplicados.

### Etapa 3 — WhatsApp Web

- serviço Node.js;
- QR Code;
- persistência da sessão;
- status;
- reconexão;
- envio de teste;
- proteção da API interna.

### Etapa 4 — Mensagens

- modelos;
- placeholders;
- pré-visualização;
- validação;
- snapshot;
- criação de lotes.

### Etapa 5 — Seleção aleatória

- seleção manual;
- todos os filtrados;
- amostra aleatória;
- ordem aleatória;
- gravação da posição.

### Etapa 6 — Fila

- Redis;
- workers;
- limites;
- horários;
- tentativas;
- pausa;
- retomada;
- cancelamento.

### Etapa 7 — Monitoramento

- tela de status;
- histórico;
- erros;
- relatórios;
- auditoria;
- exportação.

### Etapa 8 — Migração oficial

- implementação do provedor oficial;
- webhooks;
- status de entrega;
- caixa de entrada;
- substituição gradual da conexão por QR Code.

---

## 26. Escopo mínimo da primeira versão

A primeira versão deverá conter:

- login;
- cadastro e importação de contatos;
- busca e filtros;
- etiquetas;
- conexão por QR Code;
- modelos;
- placeholders;
- pré-visualização;
- criação de lotes;
- ordem aleatória;
- limites por minuto, hora e dia;
- janela de horários;
- iniciar, pausar, continuar e parar;
- status individual;
- histórico;
- erros;
- lista de não contatar;
- logs básicos;
- arquitetura de provedor.

---

## 27. Itens que podem ficar para uma segunda versão

- caixa de entrada completa;
- envio de anexos;
- múltiplos números;
- múltiplos operadores;
- campanhas recorrentes;
- respostas automáticas;
- painel estatístico avançado;
- campos personalizados;
- automações baseadas em resposta;
- integração com CRM;
- API pública;
- aplicativo móvel.

---

## 28. Critérios de aceite da primeira versão

O projeto será considerado validado quando:

1. o usuário conseguir autenticar o WhatsApp por QR Code;
2. a sessão permanecer ativa após reinicialização controlada;
3. o usuário conseguir cadastrar e importar contatos;
4. placeholders forem substituídos corretamente;
5. contatos com dados ausentes forem identificados;
6. destinatários forem embaralhados uma única vez;
7. os limites de envio forem respeitados;
8. envios fora do horário forem mantidos em espera;
9. um lote puder ser pausado e retomado;
10. um lote puder ser cancelado;
11. cada tentativa ficar registrada;
12. falhas forem apresentadas claramente;
13. contatos bloqueados não receberem novos envios;
14. a integração puder ser substituída por outro provedor.

---

## 29. Riscos e cuidados

### 29.1 Integração não oficial

A conexão por QR Code depende do funcionamento do WhatsApp Web e poderá sofrer interrupções ou alterações.

Por isso:

- deverá ser tratada como fase de validação;
- o número utilizado deverá ser escolhido com cautela;
- a sessão deverá ser monitorada;
- o sistema não deverá fazer disparos em massa;
- a migração oficial deverá permanecer no roadmap.

### 29.2 Duplicidade

O sistema deverá adotar identificadores únicos para evitar que uma mesma solicitação seja enviada duas vezes após timeout ou reinício.

### 29.3 Dados incompletos

Mensagens com placeholders não preenchidos deverão ser bloqueadas antes de entrar na fila.

### 29.4 Segurança da sessão

A sessão do WhatsApp equivale a uma credencial. Ela deverá ser protegida como uma senha.

---

## 30. Conclusão

O projeto poderá ser construído com Laravel, MySQL, Redis e Apache, mantendo um serviço Node.js separado para a autenticação por QR Code e o envio pelo WhatsApp Web.

A estrutura deverá priorizar:

- baixa quantidade de mensagens;
- controle de horários;
- limites configuráveis;
- personalização por placeholders;
- ordem aleatória;
- histórico;
- prevenção de duplicidade;
- bloqueio de contatos;
- segurança;
- possibilidade de migração para a API oficial.

A automação ficará limitada à mensagem inicial. Depois da manifestação do destinatário, a continuidade da conversa será manual e humana.
