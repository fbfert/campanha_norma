# Migração para a API oficial da Meta

O sistema nasceu falando com o WhatsApp Web, por um serviço Node que carrega uma
sessão pareada por QR. Isso validou o produto, mas não sustenta uma campanha: a
sessão cai sozinha, o número pode ser bloqueado sem aviso, e não existe
confirmação de entrega.

A API oficial (WhatsApp Cloud API) resolve esses três pontos e cobra um preço
que muda o desenho do sistema. Este documento é sobre esse preço.

## O que muda de verdade

**Fora da janela de 24 horas, só sai template aprovado.** Quando nós abrimos a
conversa — que é exatamente o caso de todo lote — o texto não é escrito na tela:
ele é submetido à Meta, revisado por ela, e só depois pode ser enviado. O que
viaja na hora do envio não é a frase pronta, são **as variáveis, em ordem**,
para a Meta encaixar no template dela.

Depois que a pessoa responde, abre uma janela de 24 horas em que texto livre
volta a valer. É nessa janela que a automação de conversa e a IA operam, e ali
nada muda.

**A entrega vira um evento.** A Meta manda `sent`, `delivered`, `read` e
`failed` por webhook, num campo separado das mensagens recebidas.

**Mídia vem por URL autenticada**, e não por download da sessão — o que deve
destravar leitura de áudio e imagem, hoje bloqueada no `downloadMedia`.

## Um lote usa um texto só

O sistema sorteava até dez modelos por lote, um por destinatário, para testar
abordagens. O lote 15 chegou a espalhar 90 pessoas por 9 textos diferentes.

Isso saiu da criação de lotes. Dois motivos, e o segundo é o que decidiu:

1. cada variação vira um template submetido e aprovado individualmente — nove
   textos são nove aprovações, cada uma sujeita a recusa;
2. **o sorteio deixava a ordem das variáveis indefinida.** O lote guarda em
   `placeholders_snapshot` as variáveis que o envio vai preencher, e no modo
   sorteado esse campo recebia a **união** das variáveis de todos os modelos. O
   lote 15 ficou com `[primeiro_nome, cidade]` enquanto seis dos nove modelos
   usavam só `primeiro_nome`. Enviar assim manda duas variáveis para um template
   que espera uma: a Meta recusa, ou pior, a cidade cai no lugar do nome.

O sorteio de **posição** continua: ele existe para o lote não sair na ordem do
cadastro, e não tem relação com o texto.

O que ficou de pé é a leitura. Lote antigo continua mostrando quais modelos
sorteou e qual coube a cada pessoa — apagar isso reescreveria o registro de
mensagens que foram realmente enviadas. As colunas `is_campaign` e
`campaign_templates_snapshot` seguem no banco por isso.

Testes: `LoteUsaUmModeloSoTest`, `ConverterLoteParaModeloUnicoTest`.

### Converter um lote preparado

Para lote que ficou pronto antes da mudança e ainda não enviou:

```bash
php artisan message-batches:single-template 14 15 --template=4          # simula
php artisan message-batches:single-template 14 15 --template=4 --aplicar
```

Recusa lote em que alguém já recebeu. Reescrever a mensagem de quem já leu
apagaria o registro do que foi enviado de fato.

Os lotes 14 e 15 (247 pessoas, nenhum envio) foram convertidos para o modelo
`Convite - Pergunta Única`:

> Oi {primeiro_nome}, sou o prof Felipe, do polo Rainbow. Prof Norma pediu pra te
> fazer uma pergunta, tudo bem?

Uma variável só. Foi escolhido por dizer quem escreve, de onde e por quê — o que
mais tende a passar na revisão da Meta. As taxas de resposta medidas não
separavam os candidatos: as amostras iam de 3 a 10 envios.

## As peças que já existem

| Peça | Arquivo | O que faz |
| --- | --- | --- |
| Provedor | `app/Services/WhatsApp/MetaCloudProvider.php` | Envia texto livre e template. Preserva o código de erro da Meta como `META_{código}` com o `fbtrace_id` no contexto. |
| Webhook | `app/Http/Controllers/Internal/MetaWebhookController.php` | Verificação por desafio e recepção assinada. |
| Tradutor | `app/Services/IncomingMessages/MetaWebhookTranslator.php` | Converte o envelope da Meta para o formato que `ProcessIncomingMessageJob` já entende. |
| Escolha do caminho | `app/Services/MessageProcessing/TemplateDispatch.php` | Decide entre texto livre e template pelo contrato do provedor. |

A separação de contratos (`WhatsAppProvider`, `PairsBySession`,
`ReadsConversationHistory`, `SendsTemplates`) existe para isso: o provedor da
Meta não pareia por QR nem lê histórico, e declarar isso no tipo evita que a
tela ofereça o que o provedor não faz.

### Detalhes que custam caro se passarem

**A assinatura é do corpo cru.** `hash_hmac('sha256', $request->getContent(), $segredo)`.
Reserializar o JSON muda espaço e ordem, e a conta deixa de bater sem que nada
apareça no log.

**O desafio volta em texto puro.** Devolver JSON faz o cadastro do webhook falhar
sem explicar por quê.

**O identificador do evento é derivado, não sorteado.** A Meta reenvia enquanto
não receber 200, e um identificador novo a cada tentativa faria a mesma mensagem
entrar duas vezes. É `uuid5` sobre `meta:{wamid}`.

**A resposta é sempre 200 depois de enfileirar.** O que falhar depois falha na
fila, com tentativa própria.

**As variáveis vêm do instantâneo do destinatário, não do cadastro de hoje.** O
lote foi preparado com um estado do contato, e é esse estado que a pessoa vai
ver. Buscar o valor atual faria a mensagem dizer a cidade nova para quem foi
selecionada pela antiga.

## Configuração

Em `config/whatsapp.php`, bloco `meta`:

| Variável | Para que serve |
| --- | --- |
| `META_PHONE_NUMBER_ID` | Número emissor, dado pela Meta |
| `META_BUSINESS_ACCOUNT_ID` | Conta WABA |
| `META_TOKEN` | Token de acesso |
| `META_APP_SECRET` | Segredo do app, usado na assinatura do webhook |
| `META_VERIFY_TOKEN` | Combinado por nós, conferido na verificação |
| `META_INVITE_TEMPLATE` | Nome do template aprovado que abre a conversa |
| `META_INVITE_LANGUAGE` | `pt_BR` |

O lote pode ter template próprio em `message_batches.meta_template_name`, que
vence o padrão da configuração.

URL do webhook: `/internal/whatsapp/meta` — `GET` verifica, `POST` recebe.

## O que ainda falta

- **Credenciais e template aprovado.** Nada sai enquanto o número não estiver na
  conta e o template `Convite - Pergunta Única` não for submetido e aprovado. O
  nome que a Meta aprovar precisa ir para `META_INVITE_TEMPLATE` ou para
  `meta_template_name` dos lotes 14 e 15 — hoje está vazio nos dois.
- **Política de conteúdo eleitoral da Meta.** Precisa ser confirmada antes de
  submeter; ela pode recusar a campanha inteira, não só o texto.
- **Confirmações de entrega não são aplicadas.** Chegam e vão para o log como
  `meta_webhook.status_received` com `'aplicado' => false`. Ficam no log em vez
  de sumir justamente para não parecerem implementadas.
- **A tela de conexão ainda oferece QR** independentemente do provedor.
- **Os nomes de campo da v21.0 não foram conferidos** contra a documentação
  vigente.

Enquanto isso, **nenhuma mensagem sai**: a decisão foi voltar a enviar só depois
da integração com a Meta.
