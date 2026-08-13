# Mídia recebida: ver, ouvir e ler

Até aqui mídia era só metadado. A conversa mostrava `[midia não baixada]`, o
arquivo existia apenas dentro da sessão do WhatsApp Web, e quem mandava foto ou
áudio recebia de volta um pedido para escrever o que já tinha mandado.

Três coisas mudaram, e são separáveis:

```text
1. o arquivo chega ao disco          conversations.media_storage_enabled
2. a tela mostra e toca              (decorre da primeira)
3. a maquina le e o fluxo responde   ai.vision.enabled / ai.transcription.enabled
```

## O cache é preguiçoso

Nada é baixado por chegar. Uma sincronização de trinta dias puxaria centenas de
arquivos que ninguém vai abrir, e **cada busca passa pelo Puppeteer**, dentro do
mesmo processo que mantém a sessão do WhatsApp de pé — afogá-lo derruba o
sistema inteiro, não só a foto.

Baixa quem precisar primeiro:

- o operador que abriu a conversa, quando o navegador pede a URL da imagem;
- a visão, quando vai descrever a imagem para o fluxo responder.

```text
conversation_message_media
```

Uma linha por mensagem, com índice único: é o que permite `firstOrCreate` sem
corrida. Uma trava por mensagem evita que abrir a conversa duas vezes busque o
mesmo arquivo duas vezes.

## O registro sobrevive ao arquivo

```text
pending      ninguem precisou ainda
stored       em disco
unavailable  a sessao nao devolveu
too_large    acima do teto
purged       passou do prazo e foi apagado
```

Passados os noventa dias (`conversations.media_retention_days`) o conteúdo sai
do disco e a linha fica marcada como expirada. É o que permite a conversa dizer
"havia uma foto aqui" em vez de fingir que nunca houve — e é foto de gente que
está no nosso disco, então guardar para sempre não é decisão que se toma por
omissão.

```bash
php artisan conversations:prune-attachments              # apaga
php artisan conversations:prune-attachments --dry-run    # simula
```

Diariamente pelo scheduler.

## O download não passa pela biblioteca

`Message.downloadMedia()` do whatsapp-web.js faz duas coisas: resolve a mídia,
se ela ainda não estiver resolvida, e só então baixa. O primeiro passo chama
`msg.downloadMedia(...)` no modelo interno do WhatsApp Web — e nesta build o
modelo **não tem método nenhum**, então a chamada estoura com um `TypeError` que
chega minificado como nome `"r"` e mensagem `"r"`.

Uma sonda na sessão viva separou as duas causas possíveis:

```text
mediaStage                : INIT        (nao é RESOLVED, entao a biblioteca desvia)
metodos do modelo         : []          (o passo de resolucao nao existe)
downloadAndMaybeDecrypt   : function    (o download em si esta la)
tentativa direta          : ok, 57791 bytes
```

Ou seja: **o download funciona**; o que quebrou foi o passo anterior. Por isso
`downloadMediaDirectly()` chama `WAWebDownloadManager.downloadManager.downloadAndMaybeDecrypt`
com os mesmos argumentos que a biblioteca usaria, pulando a resolução. A chamada
dela fica como reserva — se o modelo voltar a ter os métodos, ela volta a
funcionar; se o nosso caminho quebrar, ela cobre.

`REUPLOADING` continua sendo desistência: significa que a mídia expirou e o
próprio WhatsApp está tentando trazer de volta.

### O download precisa desistir sozinho

Há mídia para a qual `downloadAndMaybeDecrypt` **nunca resolve nem rejeita**.
Uma imagem recebida em 12/08 às 22:41 ficou assim, e a primeira versão deste
contorno criava um `AbortController` que nunca era acionado — decoração.

O efeito não fica contido na mídia: a avaliação pendurada segura a conexão com o
navegador, e no mesmo minuto **sete consultas de chat falharam** por causa dela.
Depois disso, até a mídia que baixaria normalmente passou a falhar — o
travamento envenena tudo que vem depois.

Hoje o download corre contra um prazo de 20 segundos, menor que o
`protocolTimeout` do puppeteer de propósito: quem desiste primeiro tem de ser o
download, não a conexão. Com o prazo em pé, as duas imagens que falhavam
baixaram em 0,1 segundo.

O mesmo prazo vale na sonda de diagnóstico, que fazia a mesma chamada e
pendurava igual.

### Imagem cheia de texto estourava o teto de saída

Um plano de ensino fotografado fez o modelo transcrever a página inteira,
estourar os 2000 tokens de saída e devolver JSON cortado no meio: a execução
custou 2396 tokens e produziu nada. Truncar no meio de uma chave é o pior dos
dois mundos — paga-se pelo texto e perde-se o texto.

O prompt e o schema agora pedem limite (300 caracteres na descrição, 700 na
transcrição, com instrução explícita de parar). A mesma imagem passou a custar
766 tokens e a ser lida com sucesso.

Isto depende de internals não documentados e vai quebrar de novo quando o
WhatsApp Web renomear as coisas. O endpoint de diagnóstico
(`/api/diagnostics/conversations/{chatId}/messages/{messageId}/media`) existe
para responder rápido qual das peças caiu: ele lista onde cada argumento está,
se o gerente de download existe e o que a chamada real devolve.

## Legenda de verdade dispensa a visão

Foto com legenda não é descrita: a pessoa já escreveu o que queria dizer, e
descrever por cima é gastar por informação que já temos.

Mas o WhatsApp manda legenda que não é legenda. A primeira imagem que chegou
neste sistema veio com `'` — uma aspa solta, provavelmente toque acidental. Para
`blank()` aquilo é conteúdo, e a foto ficou sem ser lida por causa de um
caractere. O critério é ter **letra ou número**: emoji sozinho, pontuação solta e
espaço não contam.

## Mídia antiga não volta, e isso não é erro

O WhatsApp Web guarda mídia por tempo limitado, e conversa que saiu da sessão
some junto. Uma figurinha de 28/07, num chat `@lid`, devolveu
`Conversa nao encontrada na sessao atual` — com a sessão conectada.

Isso fica registrado como `unavailable` e a tela diz "não foi possível recuperar
este arquivo". O teto de tentativas (`conversations.media_max_attempts`, padrão
3) existe porque, sem ele, cada carregamento da tela reabriria a tentativa para
sempre.

## O arquivo não é público

Ele mora em `storage/app/private/conversation-attachments/`, fora do alcance do
servidor web. Chegar até ele passa por:

```text
GET /admin/inbox/{conversation}/messages/{message}/media
```

que exige sessão e `inbox.view_message_content`, e confere que a mensagem é
mesmo daquela conversa — sem essa conferência, trocar o número no endereço leria
a mídia de qualquer conversa do sistema.

Servir de `storage/app/public` publicaria foto de eleitor numa URL que qualquer
um adivinha pelo identificador da mensagem.

## Na tela

```text
image, sticker   <img>, clicável para abrir em tamanho real
ptt, audio       <audio controls>
video            <video controls>
resto            link para abrir o arquivo
```

`loading="lazy"` e `preload="none"`: o que está fora da tela nem chega a ser
pedido, e um scroll de conversa longa não vira cinquenta idas ao Puppeteer.

## A transcrição estava sendo ignorada

`readableText()` existia no modelo e **não era chamada por ninguém**. O
classificador, os construtores de contexto e o gerador de resposta liam
`$message->body` direto, que é vazio para áudio.

O efeito: um áudio transcrito com sucesso chegava ao motor como texto vazio,
virava `ambiguous` e ia para atendimento humano. A transcrição era paga, gravada
e ignorada. Nunca apareceu porque a transcrição estava desligada em produção —
ligá-la sem corrigir isto gastaria API e não mudaria nada.

Agora leem `readableText()`:

```text
ConversationFlowService        classificacao de permissao e de resposta
ConversationFlowService        deteccao de assunto da escola
AiContextBuilder               classificacao e extracao
ResponseContextBuilder         bloco de resposta e historico
InboundAttendanceRouter        roteamento e exclusao
```

E as consultas de histórico usam `scopeWithReadableText()`, que inclui a
mensagem cujo texto veio de transcrição ou descrição. Antes elas filtravam
`whereNotNull('body')` e descartavam silenciosamente todo áudio e toda imagem do
contexto mandado ao modelo.

Teste: `AudioTranscritoViraRespostaTest`.

## A visão lê a imagem

```text
ai.vision.enabled
```

Imagem e figurinha passam por `ImageDescriptionService` antes do piso de
"recebi, mas não consigo ler". Quem fotografa uma rua esburacada está dizendo
alguma coisa, e pedir que ela redija aquilo é devolver o trabalho para quem já
se deu ao trabalho.

O resultado é gravado em `message_transcriptions`. A tabela chama-se assim por
ter nascido do áudio, e o conceito é o mesmo: texto que uma máquina extraiu de
uma mídia. Duas tabelas para a mesma coisa exigiriam duplicar `readableText()`,
a consulta de histórico e a marcação na linha do tempo — e um dos dois caminhos
ficaria para trás.

### O que o prompt proíbe

- descrever aparência física, roupa, raça, idade aparente ou estado de saúde;
- presumir intenção, opinião ou sentimento de quem enviou;
- inventar o que não está legível.

O que ele pede é o assunto e **o texto legível na imagem**, e o texto vem
primeiro na composição: quem fotografa uma conta de luz ou um cartaz está
mandando o que está escrito ali, e "papel branco sobre uma mesa" não diz nada.

`detail: low` na chamada. O que se quer saber é do que a foto trata, não o
detalhe do fundo, e alta resolução multiplica o custo por imagem sem mudar essa
resposta.

### Figurinha sem conteúdo não é resposta

Descrição vazia grava `empty`, e `usableAsAnswer()` continua falso. Uma
figurinha de "bom dia" tratada como opinião faria o fluxo perguntar sobre o
nada. Nesse caso o piso volta a valer e sai o pedido por escrito.

### O que continua ilegível

Vídeo e documento. O provedor recebe imagem; um quadro solto descreveria o
quadro, não o vídeo, e PDF exige extração de texto, que é outro caminho. Os dois
seguem no `UnreadableMediaResponder`.

## Custo

Cada imagem é uma chamada ao modelo de visão, e cada áudio uma chamada de
transcrição. Ambos aparecem em `ai_runs` com propósito próprio
(`describe_image`, `transcribe_audio`), então o painel de custos já os mostra
separados.

## Configurações

```text
conversations.media_storage_enabled   guardar arquivo em disco
conversations.media_retention_days    90
conversations.media_max_bytes         16777216
conversations.media_max_attempts      3
ai.vision.enabled                     descrever imagem e figurinha
ai.vision.queue                       ai-interpretation
ai.transcription.enabled              transcrever audio
```

## Testes

```bash
php artisan test --filter=FotoRecebidaELidaTest
php artisan test --filter=AudioTranscritoViraRespostaTest
```

## Solução de problemas

- Imagem não aparece: conferir `conversations.media_storage_enabled` e o
  registro em `conversation_message_media` — o motivo está em `error_message`.
- "Não foi possível recuperar": a conversa saiu da sessão do WhatsApp Web. Não
  há o que fazer do nosso lado; mídia nova continua funcionando.
- Áudio transcrito e conversa indo para humano: conferir se a transcrição
  gravou `succeeded` — só esse estado alimenta `readableText()`.
- Descrição não sai: `ai.enabled` e `ai.vision.enabled` são duas chaves, e as
  duas precisam estar ligadas.
