# Atendimento de entrada — quem escreve primeiro

Até aqui todo fluxo conversacional nascia de um lote: o sistema mandava a
mensagem inicial e, no envio bem-sucedido, abria o fluxo na conversa do
destinatário. Quem escrevia por conta própria caía em
`ConversationFlowService::handleIncomingMessage` sem estado, e o motor saía
calado — o que virava atendimento humano quando havia gente olhando, e silêncio
quando não havia.

Pior no caso que mais importa: a rede de segurança
(`conversations:answer-pending`) recusa conversa **sem contato identificado**, e
número novo é justamente isso. Quem nunca falou com a gente ficava sem nenhuma
resposta, nem automática nem do piso.

## O que muda

```text
Mensagem recebida de numero novo
        -> ProcessIncomingMessageJob grava a mensagem
        -> EvaluateConversationFlowJob (trava por conversa)
              conversa sem estado de fluxo?
                    -> InboundAttendanceService
                          roteia por conteudo -> perfil
                          guarda -> travas
                          cria o contato
                          cria o estado do fluxo
                          compoe a abertura e enfileira
              conversa com estado -> motor da 9A, como sempre
```

O ponto de entrada é o job, e não o serviço de fluxo, porque o job já segura
`Cache::lock` por conversa: é o que impede duas mensagens seguidas abrirem dois
atendimentos.

## Perfil de atendimento

É o equivalente do lote para este lado. Diz qual fluxo abrir, o que responder,
em que horário e com que teto. O que muda é a seleção: no lote nós escolhemos os
contatos, aqui quem escolhe é quem escreve.

```text
inbound_attendance_profiles
inbound_attendance_attempts
conversation_flow_states.inbound_attendance_profile_id
```

Tela: `/admin/inbound-attendance/profiles`.

### Roteamento por conteúdo

As expressões são comparadas com o texto que a pessoa escreveu, normalizado —
caixa, acento, pontuação e emoji fora — e casando **por palavra ou frase
inteira**. `voto` não casa dentro de `devoto`.

Essa regra não é preciosismo. A palavra `denuncia` dentro da lista de opt-out
removia da base quem só queria fazer uma denúncia; casar por pedaço de palavra é
o mesmo defeito com outro nome.

Perfis são avaliados por `match_priority` crescente. **Um perfil ativo precisa
carregar a marca de atender o que sobrou**, e o formulário recusa salvar sem
isso: ninguém escreve pensando na nossa lista de expressões, e quem escreve algo
fora dela é quem mais precisa de resposta.

### Expressões de exclusão

Nem toda mensagem recebida é alguém falando com a gente. Operadora avisa saldo,
banco manda código, robô de recarga oferece serviço. Sem filtro, o atendimento
responderia a todos — apresentando uma pesquisa eleitoral a um sistema que não
lê — e cada um ainda ocuparia uma linha da fila, ensinando a ignorar a fila.

Na primeira execução real havia uma dessas entre quatro conversas paradas: "Por
aqui você pode recarregar um número Vivo".

```text
inbound_attendance.exclusion_expressions
```

Regras do formato:

- **frases inteiras, não palavras soltas.** `recarga` sozinha pegaria quem
  escreve sobre o preço da recarga, e é justamente essa pessoa que se quer
  atender;
- **só a forma acentuada.** A comparação normaliza acento antes de casar, então
  `código` pega `codigo` também;
- mesma comparação do roteamento: por palavra ou frase inteira, nunca por pedaço
  de palavra.

Mensagem excluída **não abre atendimento e não entra na fila**, mas fica
registrada como tentativa `skipped` e aparece em "Ignoradas hoje", na própria
tela da fila. Expressão larga demais engoliria uma pessoa de verdade, e isso
precisa ser visível — a lista existe para poupar atenção, não para esconder.

A exclusão vale só para o automático. Quem clica está olhando a conversa e viu o
que tem nela.

Salvar a lista aplica as regras ao que **já está parado**:

```bash
php artisan inbound-attendance:apply-exclusions              # simula
php artisan inbound-attendance:apply-exclusions --dry-run
```

Fora do scheduler de propósito: é uma varredura que tira coisa da vista, e
varredura que acontece sozinha some do radar quando erra.

### Modo de abertura

```text
ai_then_survey   responde o texto e apresenta a pesquisa na mesma mensagem
survey_only      so a apresentacao
```

Quem chega por lote não disse nada ainda, e a apresentação é a primeira frase da
conversa. Aqui a pessoa já escreveu, e já escreveu alguma coisa específica:
abrir com a apresentação por cima de uma pergunta é responder outra coisa.

No modo padrão, **sem resposta confiável nada sai**. O critério é o da rede de
segurança (`ai.response.safety_net_min_confidence`, padrão 0,92), e é mais
exigente que o autoenvio comum de propósito: ninguém leu o texto antes, e é a
primeira coisa que essa pessoa vai ler da gente. A conversa fica na fila com o
motivo `resposta_ia_indisponivel` à vista.

A geração passa por `AiConversationResponseGenerator` direto, e não por
`ConversationSuggestionService`: lá o autoenvio pode disparar sozinho ao fim da
geração, e o texto sairia sem a apresentação — que é justamente o que a abertura
precisa juntar numa mensagem só. A sugestão usada é marcada como enviada,
apontando para a mensagem de abertura.

## O contato nasce ao iniciar, não ao chegar

Criar na chegada faria a base crescer com todo número que mandou um "oi" e nunca
mais voltou. Quem chega ao ponto de ser atendido vai receber uma mensagem nossa,
e mandar mensagem para quem não está no cadastro é o que nos deixa sem
histórico.

```text
name               nome do perfil do WhatsApp (sender_name_snapshot), ou o telefone
source             recebido
consent_status     nao_informado
has_replied        true
```

**Consentimento não é presumido.** A pessoa escreveu para nós, o que autoriza
responder — e não autoriza incluí-la em campanha. Quem quiser tratar como opt-in
faz isso na tela do contato, com registro de quem decidiu.

Telefone que já existe na base é vinculado à conversa em vez de duplicado.

## Travas

```text
inbound_attendance.enabled              chave geral, o botao de desligar tudo
conversation_automation.enabled         o motor precisa estar ligado
conversation_automation.auto_send_enabled
janela de horario do perfil             cai na janela geral quando vazia
teto diario do perfil
inbound_attendance.daily_start_limit    teto do dia somando os perfis
homologacao                             perfil novo nao sai sozinho
sessao do WhatsApp conectada
contato: nao contatar, inativo, sem telefone
conversa que ja tem fluxo
```

Cada recusa grava uma linha em `inbound_attendance_attempts` com o código do
motivo, e o código vira uma frase na fila. É o que separa "a conversa está
parada" de "está parada porque o teto de hoje acabou": a primeira não diz o que
fazer, a segunda diz.

### Por que a automação precisa estar ligada

A abertura sai pelo piso — quem escreveu merece resposta —, mas o que vem depois
não: a resposta da pessoa passa por `canEvaluate` e `canSend`, e as duas recusam
com a automação desligada. Abrir assim entregaria uma apresentação de pesquisa e
depois silêncio, que é pior que não ter aberto.

### Sem sessão conectada não se tenta

A lição é da rede de segurança, e custou 1535 linhas de repetição em duas
conversas: enquanto a sessão está fora do ar o envio falha com certeza, e cada
tentativa deixa uma linha na conversa. Voltando a conexão, a mensagem seguinte
tenta de novo — a pessoa está inalcançável de qualquer jeito.

### Só resposta que responde abre conversa

`producesText()` aceita o agradecimento de encerramento, e colá-lo na
apresentação produz uma mensagem que se contradiz. Saiu assim, para uma pessoa
de verdade, em 12/08/2026:

> Agradeço por participar da pesquisa. Se quiser compartilhar mais ideias no
> futuro, estamos à disposição.
>
> Aproveitando: a Prof. Norma está ouvindo as pessoas da região. Posso te fazer
> uma pergunta rápida?

Encerra e abre no mesmo fôlego. Pedido de esclarecimento tem o mesmo defeito
pelo avesso — perguntar o que a pessoa quis dizer e, na mesma mensagem, mudar de
assunto.

Só `suggest_reply` passa. A régua fica em
`InboundAttendanceService::openingAnswerUsable()`, pública para poder ser
cobrada por teste sem envolver o provedor.

### Mensagem antiga não abre conversa

```text
inbound_attendance.max_message_age_hours   72
```

A fila guarda conversa parada, e parada há muito tempo é o caso comum nela. A
mesma conversa de 12/08 foi iniciada respondendo a um "Certo, obrigada" de
**15/07** — vinte e oito dias antes. Do lado da pessoa, uma conversa encerrada
em julho voltou sozinha em agosto.

Vale também no clique, e essa é a parte que importa: quem clica vê a conversa,
mas a lista mostra a data em letra pequena ao lado de vinte linhas, e "marcar
todas" não olha data nenhuma. Zero desliga a trava.

### Homologação

Enquanto o perfil não acumular `homologation_threshold` conversas iniciadas
**por uma pessoa**, nada sai sozinho. O primeiro dia é onde se descobre que a
expressão pegou o que não devia e que o texto de abertura soa errado, e descobrir
isso depois de duzentas conversas é caro demais.

Conversa iniciada pelo automático não conta: a trava existe justamente para
exigir olho humano. Teto zero dispensa o rito.

### O clique passa por menos travas

Teto diário, janela e homologação existem para conter o que sai sozinho. Barrar
quem está olhando a conversa e decidiu iniciá-la seria inverter o propósito.

O que continua valendo no clique é o que protege a pessoa: não contatar, contato
inativo, sessão fora do ar e conversa que já está em um fluxo.

## A fila e o contador

```text
/admin/inbound-attendance
```

O contador aparece na barra do topo, em toda tela, e como cartão no painel.
Pendência de resposta não espera alguém passar pelo painel: cada hora parada é
uma hora de silêncio para quem escreveu.

O que entra na fila:

1. **Aguardando resposta** — conversas em que a última palavra é da pessoa e a
   automação não resolveu, porque uma trava recusou ou porque
   `inbound_attendance.pending_grace_minutes` passou e nada aconteceu.
2. **Atendidas hoje** — confirmação, não tarefa. Existe para quem entra de manhã
   ver o que foi dito em seu nome.

A carência existe porque o contador podia ser simplesmente "conversa cuja última
mensagem é da pessoa", e seria enganoso: a maior parte dessas conversas está a
segundos de receber resposta automática, e um número que sobe e desce sozinho
ensina a ignorar o número.

Saída que **falhou** não conta como resposta. Foi essa confusão que fez a rede de
segurança parar de tentar em conversas que ela devia atender.

A seleção múltipla marca só o que está na página: marcar o que não se vê seria
iniciar conversa às cegas. Conversa recusada no clique volta com o motivo na
mesma tela — quem clicou em vinte e viu duas recusadas precisa saber quais foram.

## Sincronização

A sincronização do WhatsApp Web também abre atendimento, com as mesmas duas
condições da mídia ilegível: a mensagem precisa ser a **última** da conversa e
precisa ser **recente** (`inbound_attendance.sync_max_age_hours`, padrão 72).

Sem esse recorte, uma execução de trinta dias mandaria abertura para dezenas de
conversas antigas de uma vez, e apresentar hoje uma pesquisa a quem escreveu há
três semanas é falar do passado.

Com ele, quem escreveu durante uma queda de sessão é atendido quando a conexão
volta — que é o caso do áudio que ficou 64 horas sem retorno.

## Permissões

```text
inbound_attendance.view              ver a fila e os perfis
inbound_attendance.start             iniciar conversa a partir da fila
inbound_attendance.manage_profiles   criar e editar perfis, e ligar/desligar tudo
```

- Administrador: todas.
- Operador: `view` e `start`.
- Consulta: apenas `view`.

`start` é decisão sobre uma pessoa; `manage_profiles` decide o texto que sai para
todas elas sem ninguém ler. Mesma separação de `control` e `manage_settings` na
automação conversacional.

## Configurações

```text
inbound_attendance.enabled                  0   (desligado por padrao)
inbound_attendance.daily_start_limit        200
inbound_attendance.pending_grace_minutes    5
inbound_attendance.sync_max_age_hours       72
inbound_attendance.exclusion_expressions    lista de frases de robo e operadora
```

Depois de alterar pelo seeder:

```bash
php artisan cache:clear
```

## Nome de chave estrangeira no MySQL

`inbound_attendance_attempts_inbound_attendance_profile_id_foreign` tem 65
caracteres, e o limite de identificador no MySQL é 64. A migração quebrava no
meio — as tabelas ficavam criadas e a chave não —, e o SQLite dos testes não
nomeia chave assim, então a suíte inteira passava sem tocar no defeito.

Por isso as chaves desta migração têm nome explícito e curto (`iaa_*`,
`cfs_inbound_profile_fk`). Tabela com nome longo mais coluna com nome longo é
uma combinação que a suíte não pega: confira contra o MySQL antes de fechar.

## Mídia de número novo

Com a visão ligada (`ai.vision.enabled`), imagem e figurinha viram texto antes de
o motor avaliar, e a partir daí seguem o caminho de qualquer mensagem escrita:
roteiam por conteúdo e abrem atendimento normalmente. Detalhes em
`docs/midia-recebida.md`.

Vídeo e documento continuam de fora. `UnreadableMediaResponder` exige um estado
de fluxo para responder, e conversa que nunca entrou em fluxo nenhum não tem: o
aviso de "recebi, mas não consigo ler" segue valendo só para quem já está em uma
pesquisa.

## Testes

```bash
php artisan test --filter=QuemEscrevePrimeiroEAtendidoTest
```

## Solução de problemas

- Nada acontece com mensagem nova: conferir `inbound_attendance.enabled`,
  `conversation_automation.enabled`, `auto_send_enabled` e se existe perfil ativo
  marcado para atender o que sobrou. A fila mostra o motivo por conversa.
- Tudo cai no perfil errado: conferir `match_priority` — menor número é avaliado
  primeiro — e se alguma expressão genérica demais está pegando antes.
- Perfil não sai sozinho: provavelmente ainda está em homologação. A coluna
  "Situação" na lista de perfis mostra a contagem.
- Conversa aparece na fila e some sozinha: é a carência funcionando; a automação
  respondeu dentro dos cinco minutos.
- Robô continua na fila depois de acrescentar a frase: a exclusão age quando a
  mensagem é processada. Salvar a lista já limpa o que está parado; para o resto,
  `inbound-attendance:apply-exclusions`.
- Pessoa sumiu da fila sem explicação: conferir "Ignoradas hoje" — alguma
  expressão de exclusão pode estar larga demais.
