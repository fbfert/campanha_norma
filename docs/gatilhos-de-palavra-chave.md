# Campanhas por palavra-chave

Até a Etapa 9, toda automação nascia de um envio nosso. O commit `a851867`
corrigiu metade disso: quem escreve primeiro passou a ser atendido. O que ficou
de fora é o outro uso de quem escreve primeiro — **a pessoa que escreve porque
foi convidada a escrever uma palavra**.

O atendimento de entrada produz uma conversa. Uma captação por palavra-chave
precisa de outra coisa: uma lista de inscritos, com prova de origem, conferível,
congelável e sorteável.

---

## O caminho de uma mensagem até a inscrição

```text
Mensagem recebida
  → ProcessIncomingMessageJob grava a mensagem
  → EvaluateConversationFlowJob  (segura o Cache::lock da conversa)
       1. KeywordCampaignTrigger
            campanhas avaliáveis em cache curto — nenhuma? encerra aqui
            casou palavra inteira no texto escrito?
               → ParticipationRegistrar cria contato se preciso e grava a inscrição
               → CampaignReplyService enfileira a confirmação
       2. esta conversa tem estado de fluxo?
            sim → motor da 9A, intacto
            não → InboundAttendanceService, salvo se o passo 1 atendeu
```

### Por que o gatilho roda dentro do job, e não ao lado dele

O plano original pedia um job irmão, despachado ao lado do de fluxo depois do
commit. Isso estava certo antes de o atendimento de entrada existir. Hoje os
dois jobs criariam contato para o mesmo número desconhecido em paralelo — e
`contacts.phone_normalized` tem índice, não chave única. O resultado seria
contato duplicado numa corrida que só aparece sob carga, ou seja, exatamente
quando a campanha está no ar.

Rodar dentro do job não é o mesmo que rodar dentro do fluxo. O gatilho não lê
nem escreve estágio, não move `last_processed_message_id` e não chama
`ConversationFlowService`. O que ele toma emprestado é só a trava por conversa.

Vir **antes** da decisão "esta conversa tem fluxo?" também é deliberado: o
roteamento de entrada só alcança conversa sem estado, e quem está no meio de uma
pesquisa é justamente quem já provou que responde.

### Uma mensagem por pessoa

Quando a campanha atende a mensagem, o atendimento de entrada não abre para
aquela mensagem. Quem escreveu a palavra-chave já disse o que queria, e a
abertura genérica seria a segunda mensagem no mesmo minuto. Numa divulgação de
centenas de pessoas isso é o dobro do volume no pico — o comportamento que mais
rápido leva um número do WhatsApp Web a bloqueio.

A supressão é da mensagem, não da pessoa: a próxima mensagem dela é roteada
normalmente.

### Áudio não inscreve ninguém

O casamento lê `body`, e não `readableText()`. A transcrição mora em outra
tabela, então áudio transcrito não casa — inclusive quando
`TranscribeIncomingAudioJob` redispara a avaliação depois de transcrever.

Isso é escolha, não limitação. Inscrição é um ato com consequência — entra numa
lista, concorre a prêmio — e transcrição automática erra. Uma inscrição criada
por engano de transcrição é indistinguível, no banco, de uma de verdade, e quem
não se inscreveu não tem como saber que está na lista.

Se a divulgação for por rádio, é a primeira coisa a reconsiderar.

### Reação, sim — e por quê

Reagir com 👍 na mensagem que traz a palavra-chave **inscreve**. É a única
exceção à regra acima, e ela não contradiz o motivo dela.

O que desqualifica o áudio é a suposição: transcrição é a máquina adivinhando o
que foi dito, e ela erra. A reação não tem suposição nenhuma no caminho — o ato
é da própria pessoa, o alvo fica gravado em `quoted_message_id`, e o texto sobre
o qual ela reagiu é um texto que ela leu.

Continua valendo igual: reação numa mensagem nossa que não fala da campanha não
inscreve, e reação na própria mensagem da pessoa não inscreve.

Reação **negativa inscreve do mesmo jeito**. Inscrição e pesquisa são dois
consentimentos, e não um: quem reage no convite respondeu ao convite e entra na
lista; o 👎 diz apenas que ela não quer responder à pesquisa, então a
confirmação sai sem o convite emendado e nenhum fluxo é aberto. Pelo mesmo
motivo, recusar a pesquisa depois — reagindo ou escrevendo — nunca cancela a
inscrição.

Emoji fora das listas não inscreve. Detalhes em `docs/reacoes-na-conversa.md`.

---

## O casamento

Palavra inteira, sobre texto normalizado: caixa, acento, pontuação e emoji fora.
A regra vive em `App\Services\Text\WholeWordMatcher`, compartilhada com o
roteador do atendimento de entrada — duas cópias divergiriam na primeira
correção feita em uma só.

| Mensagem | Palavra `sorteio` | Por quê |
|---|---|---|
| `SORTEIO CURSO` | casa | caixa não importa |
| `sortéio` | casa | acento não importa |
| `Sorteio!` | casa | pontuação não importa |
| `não quero saber de sorteio nenhum` | **casa** | é palavra inteira ali; falso positivo aceito |
| `sorteios` | não casa | plural é outra palavra |
| `sorte` | não casa | não casa dentro de `sorteio` |
| `assorteio` | não casa | pedaço de palavra nunca casa |

Não há tolerância a erro de digitação. Distância de edição aproxima palavra
errada de palavra certa, mas também aproxima duas palavras legítimas e
diferentes, e calibrar o limiar sem dado real é chute. O comando
`campanhas:quase-casamentos` existe para transformar esse chute em número.

O falso positivo da linha em negrito sai da lista pela invalidação com motivo,
feita por um humano. A alternativa seria classificar intenção com IA, que erraria
de um jeito bem mais difícil de auditar num processo cuja única defesa é ser
auditável.

---

## Consentimento e a barreira de finalidade

Contato criado por palavra-chave nasce com `source = gatilho` e
`consent_status = granted`, com a finalidade escrita em `consent_text`.

Isso difere do atendimento de entrada, que grava `not_informed` — e as duas
estão certas. Quem manda "bom dia" não consentiu com nada; quem escreve uma
palavra que só existe no material da campanha fez um ato inequívoco e
**específico**.

"Específico" é a palavra que importa: a pessoa consentiu em participar da
campanha, não em receber disparo. Por isso `ContactSelectionService` exclui
contatos de origem `gatilho` da seleção padrão de lote.

- **Lote por filtro ou por amostra:** esses contatos são excluídos em silêncio.
- **Seleção manual:** a montagem é recusada, dizendo quantos vieram de campanha.
  Tirar sem avisar um contato que o operador clicou produz um lote menor do que
  ele montou, e o que ele conclui é que o sistema perdeu gente.

Incluir mesmo assim exige marcação explícita.

---

## Abrir uma pesquisa depois da inscrição

A campanha pode, além de inscrever, puxar uma conversa de pesquisa. É opcional:
sem fluxo escolhido, ela só sorteia.

```text
1. A pessoa manda a palavra-chave
2. Recebe, NUMA MENSAGEM SÓ, a confirmação da inscrição + o pedido de permissão
3. Respondendo que sim, o motor da 9A dispara a pergunta do fluxo
4. A resposta é interpretada pela 9B, e a 9C conduz a continuação
```

Os passos 3 e 4 não são código desta etapa: são o mesmo motor que os lotes usam
desde a 9A. A campanha só faz o que o lote faz — aponta para um fluxo e chama
`activateForConversation` quando a mensagem sai.

### Onde se configura

Na campanha, em **4. Pesquisa depois da inscrição**: escolha o fluxo
conversacional. As perguntas continuam morando no fluxo, em Pesquisa → Fluxos
conversacionais, com peso, ordem, categoria e versão — e continuam aparecendo no
relatório de qualidade das perguntas.

O **convite da pesquisa** é opcional. Em branco, usa o texto de apresentação do
fluxo. Preencha quando a frase precisar mudar por causa do sorteio: "além disso,
posso te fazer uma pergunta?" lê diferente de uma abertura fria.

### Três coisas que não acontecem

- **Duas mensagens.** Confirmação e convite vão emendados. Separados seriam duas
  mensagens no mesmo minuto para a mesma pessoa, que é o volume que o limitador
  existe para conter.
- **A palavra-chave virar resposta de pesquisa.** Mensagem que é *só* a
  palavra-chave não chega ao motor da 9A: ela já virou inscrição e já foi
  respondida. Entregá-la ao motor a transformava em opinião — em 17/08/2026,
  "batata" foi gravada como resposta à pergunta sobre o problema mais urgente
  da cidade, e a pesquisa avançou para a pergunta seguinte com esse dado dentro.

  Só a palavra sozinha. `falta saúde no bairro`, numa campanha cuja palavra é
  `saude`, é a resposta da pessoa e passa normalmente.

- **Pesquisa por cima de pesquisa viva.** Quem está respondendo outra pesquisa
  agora se inscreve e recebe só a confirmação. Abrir uma segunda faria duas
  perguntas concorrerem na mesma conversa.

  **Viva**, e não apenas existente. Pesquisa encerrada, ou não terminada com o
  prazo vencido, é **reaberta** pela campanha. A distinção não é preciosismo:
  em 17/08/2026 a base tinha 69 conversas com fluxo, e nenhuma delas viva — 67
  vencidas e 2 concluídas. Barrar por existência fazia a pesquisa da campanha
  alcançar só quem nunca tinha sido abordado, em silêncio.

  A reabertura reaproveita a mesma linha de estado, porque a tabela tem chave
  única por conversa. As transições, os insights e as mensagens da pesquisa
  anterior ficam onde estão, e a reabertura é registrada em auditoria como
  `keyword_campaign.survey_reopened`.
- **Pesquisa antes da confirmação sair.** O fluxo só é aberto depois de o
  provedor confirmar o envio. Numa rajada a confirmação pode esperar minutos na
  fila; abrindo o fluxo ao enfileirar, qualquer coisa escrita nesse intervalo
  seria lida como resposta a um pedido que a pessoa ainda não recebeu.

### As duas chaves que precisam estar ligadas

A pesquisa depende da automação de conversas, e são **duas** chaves em
Configuração da automação:

| Chave | Sem ela |
|---|---|
| `conversation_automation.enabled` | a conversa nunca sai de "aguardando permissão" |
| `conversation_automation.auto_send_enabled` | a permissão é concedida e **a pergunta não sai** |

A segunda é a que mais engana: do lado de fora parece que funcionou, porque a
pessoa disse sim e o estágio avançou. Confira as duas antes de divulgar.

---

## Conferir a elegibilidade

A campanha é entre alunos, mas a entrada não verifica nada. A conferência
**marca**, nunca recusa.

1. `/admin/keyword-campaigns/{id}/eligibility`
2. Importe o CSV ou XLSX exportado do portal. A coluna de telefone é reconhecida
   pelos cabeçalhos `telefone`, `phone`, `celular` ou `whatsapp`; um arquivo de
   uma coluna só é lido como lista de telefones.
3. O nono dígito não atrapalha: as duas formas do número são testadas.
4. O que não casar fica na fila de conferência, onde um humano marca em lote.

Na fila, o botão **"Marcar todos"** alterna entre marcar e desmarcar a página
inteira. Ele marca só o que está na tela: a fila é paginada de 100 em 100, e
marcar o que não se vê seria conferir às cegas quem ninguém leu. O rótulo muda
junto com o estado, porque marcar tudo sem poder desmarcar obriga a recarregar a
página para desfazer um clique.

A importação é idempotente. Quem um humano já marcou como não aluno continua não
aluno: o arquivo é um retrato do portal num instante, e a decisão humana veio de
olhar o caso.

---

## Congelar, sortear e entregar

### Congelar

O congelamento exige a fila de conferência vazia, e recusa dizendo quantas
faltam. Uma lista congelada com inelegível dentro obriga a resortear quando o
sorteio apontar um deles — e um sorteio refeito porque o ganhador não servia é
indistinguível, para quem está de fora, de um sorteio refeito porque o ganhador
não agradou.

A lista congelada guarda um hash do **conteúdo**, não do instante: congelar duas
vezes o mesmo conjunto produz o mesmo hash.

Depois de congelada, invalidar uma participação não altera a lista congelada nem
um sorteio já executado. Novo sorteio exige novo congelamento, e descongelar
pede motivo escrito.

### Sortear

Só sobre lista congelada, e só quando houver cupom para cada ganhador. Sortear
primeiro e descobrir depois que falta prêmio obriga a escolher entre não entregar
a um ganhador anunciado e refazer o sorteio.

A semente fica registrada **em claro**. Semente em segredo não serve de auditoria
nenhuma: a auditoria é justamente alguém de fora reexecutar com a mesma semente e
a mesma lista e chegar ao mesmo resultado. O botão "Refazer a conta" faz isso na
frente de quem está olhando.

A ordenação é por `sha256(semente|id)`, e não por gerador pseudoaleatório. A
diferença que importa não é estatística: é que qualquer pessoa, em qualquer
linguagem, refaz esta conta com a semente e a lista publicadas.

O primeiro sorteado é o ganhador; os seguintes formam a fila de suplentes.
Sortear é sempre um ato deliberado, com confirmação na tela. Não há agendamento.

### De onde vem o cupom

Duas portas, e as duas caem no mesmo `CouponService::importarCodigos()`:

- **Importar cupons** — CSV ou XLSX com a coluna `codigo`, `code`, `cupom` ou
  `coupon`; um arquivo de uma coluna só é lido como lista de códigos.
- **Cadastrar cupons à mão** — um código por linha, para o prêmio que veio em um
  e-mail e não em planilha. Vírgula e ponto e vírgula também separam, porque
  quem copia de uma planilha cola tudo numa linha só. O teto é 1000 de uma vez;
  acima disso, use o arquivo.

As duas são idempotentes, e a idempotência vem da chave única do banco, não de
uma consulta prévia — a verificação feita antes do `insert` perde a corrida
entre dois processos, e aqui perder a corrida significa dar o mesmo prêmio duas
vezes.

O registro de auditoria guarda a origem (`arquivo` ou `manual`) e as contagens.
Nunca um código.

### A mensagem que o ganhador recebe

Configurável na própria tela de sorteio, em `coupon_text` da campanha. Campo
nulo manda o texto que saía fixo antes de a mensagem existir, para campanha
antiga não mudar de comportamento.

Dois marcadores, na mesma sintaxe do catálogo de placeholders do resto do
sistema:

| Marcador | Obrigatório | Vira |
| --- | --- | --- |
| `{codigo}` | sim | o código do cupom, no momento do envio |
| `{nome}` | não | o nome conferido na tela de elegibilidade |

Só estes dois, de propósito. O catálogo geral oferece cidade, estado, e-mail e
país, e quem se inscreve por palavra-chave nasce sem nenhum deles — a campanha
só tem nome e telefone. Oferecer um campo que sempre chega vazio é oferecer uma
frase quebrada para o ganhador.

**Duas travas, ambas antes de qualquer job sair:**

1. Mensagem sem `{codigo}` é recusada. "Parabéns, você ganhou" sem o código é um
   prêmio que não foi entregue, e o ganhador não tem como saber que faltou
   alguma coisa: o cupom fica marcado como entregue e o erro só aparece quando a
   pessoa reclama.
2. Se o texto usa `{nome}` e algum ganhador não tem nome cadastrado, a entrega é
   recusada, dizendo quais são. Descobrir isso no meio da fila deixaria a
   escolha entre mandar "Parabéns, !" e não mandar nada — e as duas são ruins
   depois que metade do lote já saiu.

O que fica gravado é o **molde**, com `{codigo}` no lugar do código. O código
entra na variável que vai ao provedor e em lugar nenhum além dela: é isso que
permite guardar a mensagem em banco sem guardar o prêmio junto.

### Entregar

Cupom é valor. O código:

- não aparece em log, em mensagem de erro nem em evento de auditoria;
- não sai na exportação de participantes;
- não é gravado em claro no histórico — o que fica lá é a referência do cupom;
- não é gravado no molde da mensagem;
- só aparece na tela para quem tem `keyword_coupons.manage`.

O modelo esconde `code` de toda serialização; quem precisa dele chama
`CouponService::revelar()` explicitamente. Na listagem, essa chamada acontece no
controlador, onde a permissão já foi conferida — sem ela a view recebe um mapa
vazio e não tem o que vazar.

O botão de entrega vale para a campanha inteira, e não para um sorteio: ele
enfileira todo cupom atribuído e ainda não entregue. Por isso mora num card
próprio, e não embaixo de cada sorteio.

O envio passa pelo mesmo teto global das confirmações.

### Acompanhar os cupons

O topo da tela conta as três situações — disponíveis, atribuídos esperando
entrega, entregues — e o card **"Cupons"** lista um a um, com código (para quem
administra), referência, ganhador, telefone e as duas datas.

Os usados aparecem primeiro. Quem abre a tela depois do sorteio quer saber para
quem o prêmio foi; quem abre antes quer saber se tem cupom bastante, e para isso
o contador do topo já responde sem descer a página.

A distinção que importa é entre **atribuído** e **entregue**: o primeiro é o
prêmio que ainda pode falhar no envio, e é exatamente o que alguém precisa achar
quando o ganhador diz que não recebeu nada. As duas situações têm cores
diferentes na lista por causa disso.

---

## Contenção de rajada

Divulgação bem-sucedida gera centenas de mensagens em minutos. Sem teto, o
sistema responderia todas no ritmo que o worker drenar.

| Chave | Padrão | O que faz |
|---|---|---|
| `keyword_campaigns.confirmation_max_per_minute` | 20 | teto global, somando todas as campanhas |
| `keyword_campaigns.confirmation_min_interval_seconds` | 2 | intervalo mínimo entre duas confirmações |
| `keyword_campaigns.send_queue` | `keyword-campaigns-send` | fila das respostas de campanha |

Três decisões dentro disso:

- **Atômico.** O incremento vem primeiro e a decisão sai do valor que ele
  devolveu. `SendingRateLimiterService` lê em `check()` e incrementa em
  `consume()` sem trava, e dois workers furam o teto; aqui isso não se repete.
- **Adiado, nunca descartado.** O excedente sai depois. Ninguém perde a
  confirmação por ter escrito no minuto errado.
- **Sem janela de horário.** Quem escreve às 23h está com o celular na mão.
  Segurar até as 8h produz a segunda e a terceira mensagem da mesma pessoa
  perguntando se deu certo, o que é pior para a reputação do número.

O **alarme por hora** da campanha não freia nada: existe para alguém saber que a
divulgação pegou mais do que se esperava enquanto ainda está acontecendo.

---

## Operação

### "Alguém diz que participou e não consta"

1. Confirme que a campanha estava vigente no momento:

   ```bash
   php artisan campanhas:diagnosticar --campanha=1
   ```

2. Procure a pessoa na tela de participantes, buscando pelo telefone. Se aparecer
   como **invalidada**, o motivo está ao lado. Se aparecer como **em revisão**, o
   telefone casou com mais de um contato e espera um humano.

3. Não aparecendo, veja se a mensagem dela existe no histórico. Existindo, o job
   morreu — e a inscrição é derivável:

   ```bash
   php artisan campanhas:reprocessar --campanha=1 --dry-run
   php artisan campanhas:reprocessar --campanha=1
   ```

   O comando é idempotente: rodar duas vezes produz o mesmo estado.

4. Não existindo mensagem nenhuma, a pessoa escreveu para outro número, mandou
   áudio, ou escreveu uma variação da palavra. O último caso aparece em:

   ```bash
   php artisan campanhas:quase-casamentos --campanha=1
   ```

### "Ligamos a campanha e não acontece nada"

`campanhas:diagnosticar` avisa quando uma campanha está vigente e sem nenhuma
inscrição. Confira, nesta ordem: a situação é `ativa`? a vigência já começou? as
palavras estão como as pessoas escrevem? A lista de campanhas fica em cache por
30 segundos, mas gravar a campanha limpa o cache — ligar na tela liga de verdade.

### Os comandos

| Comando | Para quê |
|---|---|
| `campanhas:reprocessar` | rede de segurança; idempotente, aceita `--campanha`, `--from`, `--to` e `--dry-run` |
| `campanhas:diagnosticar` | responde "está funcionando?" sem abrir o banco |
| `campanhas:quase-casamentos` | insumo para decidir se vale relaxar o casamento |

**Nenhum deles está no agendador**, e isso é deliberado. Existem dois comandos no
projeto criados e nunca agendados, que portanto nunca rodaram; os três daqui são
de operação humana. Se um deles precisar de agendamento, acrescente a
`routes/console.php` com o motivo escrito no comentário.

---

## Permissões

| Permissão | Consulta | Operador | Administrador |
|---|---|---|---|
| `keyword_campaigns.view` | sim | sim | sim |
| `keyword_participations.view` | sim | sim | sim |
| `keyword_participations.invalidate` | não | sim | sim |
| `keyword_participations.export` | não | sim | sim |
| `keyword_campaigns.manage` | não | não | sim |
| `keyword_draws.execute` | não | não | sim |
| `keyword_coupons.manage` | não | não | sim |

As três ações que decidem quem ganha o prêmio — congelar, sortear e ver código
de cupom — ficam com o administrador.

---

## Antes de ligar a campanha

- [ ] Enquadramento jurídico confirmado e regulamento publicado
- [ ] Textos de confirmação, de já inscrito e de fora de vigência revisados por
      um humano
- [ ] Teto de confirmação por minuto conferido em `campanhas:diagnosticar`
- [ ] Palavras revisadas: nenhuma curta demais, nenhuma ambígua — a tela avisa,
      mas não bloqueia
- [ ] Lote de cupons importado, com folga sobre o número de ganhadores
- [ ] CSV de alunos importado e fila de conferência esvaziada
- [ ] Barreira de finalidade verificada: monte um lote de teste e confirme que
      contato de origem gatilho não é selecionado
- [ ] Se a campanha abre pesquisa: fluxo escolhido, com pergunta ativa, e as duas
      chaves da automação ligadas
- [ ] `campanhas:diagnosticar` rodando limpo
- [ ] Alguém de plantão na primeira hora, olhando o alarme de teto por hora

### A nota jurídica, que precisa ser resolvida antes do anúncio

Distribuição gratuita de prêmio decidida por sorte é promoção comercial regida
pela Lei 5.768/71: exige autorização federal prévia e regulamento registrado, e o
processo leva semanas.

Curso digital próprio tem custo marginal quase zero, o que torna dois desvios
mais baratos que a autorização: dar acesso a todo participante e sortear apenas
um prêmio de maior valor; ou fazer um concurso de mérito, com critério publicado
e avaliação por júri.

Nenhum dos dois muda uma linha do código — quem decide o ganhador é configuração,
não estrutura. Mudam o texto de confirmação e o regulamento. Isto não é parecer
jurídico: confirme antes de divulgar.
