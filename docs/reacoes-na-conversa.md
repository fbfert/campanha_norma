# Reações na conversa

Reagir é a resposta mais barata que o WhatsApp oferece. Não exige teclado, não
exige frase, não exige decidir como escrever — e por isso é a resposta que muita
gente dá. Um 👍 no convite é um "sim".

Até esta etapa o sistema não enxergava nenhum deles.

Pelo provedor `web`, que é o que roda em produção, a reação nem chegava: o
whatsapp-web.js entrega reação por um evento próprio, `message_reaction`, e esse
evento não era assinado. Pela Meta ela chegava, virava uma mensagem de tipo
`reaction` — e caía num vazio, porque nenhum dos quatro ramos de roteamento de
`ProcessIncomingMessageJob` pega uma mensagem que não é texto e não tem mídia.

O efeito era pior que silêncio. A reação ainda incrementava `unread_count`,
jogava a conversa para `WaitingOperator` e interrompia os lotes pendentes da
pessoa. Do lado dela: respondeu. Do nosso: uma conversa no topo da fila, sem
nada dentro.

---

## O caminho de uma reação

```text
Pessoa reage 👍 na mensagem que perguntou
  → whatsapp-service: evento message_reaction
       descarta reação nossa, de grupo, órfã e remoção de reação
       monta o envelope de mensagem recebida, com message_type = reaction,
       o emoji no corpo e a MENSAGEM REAGIDA em quoted_external_message_id
  → ProcessIncomingMessageJob grava a reação como ConversationMessage
  → EvaluateConversationFlowJob
       1. KeywordCampaignTrigger — casa a palavra-chave contra o texto da
          MENSAGEM REAGIDA, não contra o emoji
       2. atendimento de entrada: reação nunca abre
       3. ConversationFlowService — só no estágio de permissão, e só se a
          reação foi feita na última mensagem nossa
```

## As três travas

Nenhuma reação decide nada sozinha. Três condições valem juntas, e cada uma
existe por um motivo diferente.

**Só na mensagem que perguntou.** O evento traz o alvo, então dá para conferir.
Um 👍 numa mensagem de três semanas atrás é alguém acusando recebimento de outra
coisa, e não resposta à pergunta de hoje. `ReactionTargetResolver` compara o alvo
com a última mensagem nossa da conversa — que é a que fez a pergunta, tenha ela
nascido de um lote ou de uma resposta automática.

**Só na mensagem nossa.** O WhatsApp deixa reagir na própria mensagem. Isso é a
pessoa concordando consigo mesma, e não responder a nós.

**Só no estágio de permissão.** Uma pergunta aberta pede texto. Gravar um emoji
como opinião sobre o problema mais urgente da cidade produziria o mesmo dado
inventado que "batata" produziu em 17/08/2026 — e um dado inventado é
indistinguível, no banco, de um dado de verdade.

## O que cada emoji quer dizer

As listas vivem em `system_settings` e são editáveis na tela de configuração da
automação:

- `conversation_automation.positive_reactions`
- `conversation_automation.negative_reactions`

Não estão no código porque o teclado de emoji do WhatsApp muda a cada versão, e
quem acompanha isso é quem lê as conversas, não quem faz o deploy. Esvaziar a
lista devolve o sistema ao comportamento anterior: reagir para de significar
alguma coisa, sem deploy.

`ReactionClassifier` ignora tom de pele e seletor de variação — 👍 cobre de 👍🏻 a
👍🏿, e ❤ cobre ❤️. Sequência composta cai para o emoji base, então 🙅‍♀️ é lida como
🙅. A negativa é conferida antes da positiva, como no classificador de texto:
se alguém puser o mesmo emoji nas duas listas, o erro cai para o lado de não
presumir consentimento.

### Não existe opt-out por reação

Descadastro é irreversível para quem o sofre. Um toque errado no teclado de
emoji não pode tirar alguém da base, e a pessoa não teria como saber que saiu.

Quem quer sair escreve "sair", e `PermissionResponseClassifier` continua tratando
isso com prioridade absoluta sobre qualquer outra classificação. A reação
negativa recusa a pesquisa e encerra com agradecimento — o mesmo que um "não
quero" escrito.

## O que a reação positiva desencadeia

**Autoriza a pesquisa.** Vale como `PermissionResponseClassification::PermissionYes`
e a primeira pergunta sai, exatamente como um "sim" escrito.

**Grava o consentimento.** `consent_status` sobe para `granted`, com a
finalidade escrita em `consent_text` — incluindo a frase exata a que a pessoa
respondeu. Sem ela o banco diria apenas que alguém consentiu, sem dizer com o
quê.

Vale igual para quem escreveu "sim": `consent_source` é `resposta_na_conversa`
no caminho escrito e `reacao_na_conversa` no caminho da reação. A primeira
versão gravava só a reação, e ficou uma assimetria sem defesa — tocar num emoji
registrava mais do que escrever a palavra, que é o ato mais deliberado dos dois.

Nunca sobrescreve `revoked`: devolver à base, a partir de um emoji, alguém que
pediu para sair por escrito seria desfazer a única decisão que ela tomou.

**"Sim" ouvido pela máquina não consente.** Áudio transcrito como "sim" continua
autorizando a pergunta, porque o custo de errar ali é uma pergunta a mais numa
conversa que a pessoa abriu. Consentimento é outra coisa: fica no cadastro,
sustenta disparo futuro, e um consentimento criado por engano de transcrição é
indistinguível, no banco, de um de verdade. É a mesma linha que a inscrição por
palavra-chave traça.

**Inscreve na campanha.** Reagir na mensagem que traz a palavra-chave inscreve,
como escrever a palavra inscreveria.

## Por que a campanha aceita reação, se não aceita áudio

`docs/gatilhos-de-palavra-chave.md` diz que o casamento lê `body` de propósito,
para que áudio transcrito não inscreva ninguém. O motivo é que transcrição é a
máquina supondo o que foi dito, e uma inscrição criada por engano de transcrição
é indistinguível, no banco, de uma de verdade.

A reação é diferente do áudio em três pontos, e são eles que sustentam a
exceção:

1. o ato é da própria pessoa, e não de um modelo;
2. o alvo fica gravado, então dá para reconstruir o que ela respondeu;
3. o texto sobre o qual ela reagiu é um texto que ela leu.

Nada disso vale para uma transcrição. Continua valendo, igual: reação numa
mensagem nossa que não fala da campanha não inscreve, reação na própria mensagem
não inscreve, e reação negativa não inscreve.

### Inscrição e pesquisa são dois consentimentos

Reagir 👎 no convite da campanha **inscreve do mesmo jeito**. Quem reage no
convite respondeu ao convite: entra na lista e recebe a confirmação. O que a
reação negativa diz é sobre a outra coisa — que ela não quer responder à
pesquisa —, e o efeito é só esse: a confirmação sai sem o convite emendado, e
nenhum fluxo é aberto. Emendar o convite ali seria perguntar de novo a quem
acabou de dizer que não, na mesma mensagem.

Emoji fora das listas não inscreve: um 🍕 no convite é alguém achando graça.

A independência vale nos dois sentidos. Recusar a pesquisa — reagindo ou
escrevendo "não quero" — **nunca cancela a inscrição**. Quem está no sorteio
continua no sorteio.

A prova de origem passa a ser a reação — `conversation_message_id` aponta para
ela, e a mensagem reagida fica em `quoted_message_id`. As duas linhas lado a
lado reconstroem a inscrição inteira.

## O que o serviço Node descarta antes de encaminhar

| Situação | Por quê |
|---|---|
| Reação nossa (`senderId` é a conta conectada) | Um 👍 da equipe numa resposta viraria opt-in da pessoa |
| Reação em grupo | Grupo não entra no sistema, em nenhum caminho |
| Reação órfã (`orphan` diferente de zero) | Ressincronização traz reação antiga: viraria inscrição de hoje para um 👍 de semanas atrás |
| Remoção de reação (emoji vazio) | Ver abaixo |
| Reação sem `msgId` | Sem alvo não há o que conferir |

### Remoção de reação não é encaminhada

O mesmo evento dispara quando a pessoa tira o emoji, e aí `reaction` vem vazio.
Encaminhar produziria uma segunda linha na conversa dizendo que alguém tirou um
emoji, e não desfaria nada: a pergunta seguinte já saiu, a inscrição já existe.

Consentimento que se retira, se retira escrevendo.

## Na tela

A reação aparece na conversa como uma entrada própria, com o emoji em corpo
maior e a mensagem reagida citada logo abaixo — sem a citação, ela seria
indistinguível de alguém que mandou só um emoji, e é justamente a mensagem
reagida que decide se aquilo respondeu alguma coisa. Quando a mensagem reagida é
anterior à sincronização e não existe no banco, a tela diz isso em vez de mostrar
uma citação vazia.

Na lista de conversas a prévia mostra "Reagiu com 👍". Ela mostrava `body` cru, e
para uma reação isso era um emoji sozinho no meio da fila: quem varre a fila
decide pela prévia se abre a conversa, e um emoji solto não diz que houve
resposta a alguma coisa.

### Por que uma entrada própria, e não um selo na mensagem reagida

No WhatsApp a reação aparece grudada na mensagem, e a primeira reação de quem lê
é querer o mesmo aqui. A conversa atualiza sozinha por polling incremental — o
navegador pede `after_id` e acrescenta o que veio depois. Um selo colado numa
mensagem antiga não é "o que veio depois": ele exigiria reescrever uma bolha que
já está na tela, e sem isso a reação só apareceria no recarregamento seguinte.

A entrada própria ainda carrega o horário e entra na trilha de auditoria, que é
o que permite reconstruir por que a pergunta seguinte saiu.

## Limites conhecidos

- **Reagir não conta como resposta a pergunta aberta.** É deliberado, e está
  descrito acima.
- **Reação não abre atendimento de entrada.** Um 👍 não é alguém puxando
  assunto, e logo depois de um disparo seria uma saudação por emoji no mesmo
  minuto — o volume que mais rápido leva um número do WhatsApp Web a bloqueio.
- **Reação trazida pela sincronização não é lida.** A sincronização lê mensagens,
  e reação não é mensagem para o whatsapp-web.js. Só o evento ao vivo entra.
