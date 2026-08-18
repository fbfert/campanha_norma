# Design — Etapa 10

## Contexto

O plano desta etapa foi escrito contra o commit `e867add`, quando nenhum ponto do pipeline de entrada criava contato e `ConversationFlowService` saía calado para quem escrevia primeiro. Os commits `a851867` e `17b5955` mudaram isso: `InboundAttendanceService` hoje roteia por conteúdo, cria contato com origem `recebido`, abre fluxo e responde.

Três premissas do plano caíram com isso, e o desenho abaixo é o que sobrou depois de reconciliar:

- o casamento por palavra inteira sobre texto normalizado **já existe**, em `InboundAttendanceRouter`;
- a criação de contato a partir de mensagem recebida **já existe**, e `contacts.phone_normalized` não tem índice único, então dois caminhos concorrentes de criação produzem contato duplicado;
- a resposta a quem escreve primeiro **já existe**, então uma confirmação de campanha somada a ela são duas mensagens para a mesma pessoa no mesmo minuto.

## Decisão 1 — O gatilho roda dentro do job de fluxo, antes do roteamento

**Escolhido:** `EvaluateConversationFlowJob` avalia as campanhas vigentes como primeiro passo, antes da decisão "esta conversa tem estado de fluxo?", e nunca escreve em `conversation_flow_states`.

```text
EvaluateConversationFlowJob  (segura o Cache::lock da conversa)
  1. gatilho de campanha
       casou? registra participação e marca a mensagem como atendida pela campanha
  2. tem estado de fluxo?
       sim -> motor da 9A, intacto
       não -> InboundAttendanceService, salvo se o passo 1 atendeu
```

**Alternativas descartadas:**

- *Job irmão, despachado ao lado do de fluxo depois do commit.* É o que o plano pedia, e era certo antes de `a851867`. Hoje os dois jobs criariam contato para o mesmo número desconhecido em paralelo, e sem índice único em `phone_normalized` o resultado é contato duplicado numa corrida que só aparece sob carga — exatamente quando a campanha está no ar. Fazer funcionar exigiria índice único novo, extração da criação de contato para um serviço com trava e um acordo entre dois jobs sobre quem responde: mais código e mais superfície de corrida para chegar ao mesmo lugar.
- *Campanha vira um perfil de atendimento de entrada.* Menos código novo, um só caminho de tudo. Descartado por um limite estrutural: o roteador só é consultado para conversa **sem** estado de fluxo. Quem está no meio de uma pesquisa nunca se inscreveria — e essa pessoa é a que já provou que responde.

Rodar dentro do job não é o mesmo que rodar dentro do fluxo. O gatilho não lê nem escreve estágio, não move `last_processed_message_id` e não consulta `ConversationFlowService`. O que ele toma emprestado é só a trava por conversa, que é o que impede duas mensagens seguidas da mesma pessoa abrirem duas inscrições.

## Decisão 2 — Participação é projeção da mensagem, não efeito colateral

**Escolhido:** toda participação grava `conversation_message_id`, obrigatório, e pode ser reconstruída a partir das mensagens já gravadas.

Isso é o que permite ao job falhar de forma visível, sem `catch` que engole, e ao comando `campanhas:reprocessar` ser a rede de segurança da etapa inteira. Job morto, fila limpa ou worker derrubado no meio de uma divulgação não perdem inscrição, porque a inscrição é derivável.

**Alternativa descartada:** *participação criada como efeito colateral do envio da confirmação.* Amarra o registro ao sucesso de uma chamada de rede. Falha de envio viraria pessoa não inscrita, e é a pessoa que menos vai entender o que aconteceu.

## Decisão 3 — Casamento determinístico, reaproveitando o que existe

**Escolhido:** `KeywordMatcherService` reaproveita a normalização e o casamento por palavra inteira de `InboundAttendanceRouter`, extraídos para um ponto comum. Sem IA, sem tolerância a erro de digitação.

A regra já está escrita e testada no repositório, e a razão dela também: `denuncia` dentro da lista de opt-out removia da base quem só queria fazer uma denúncia. `sorte` não pode casar dentro de `sorteio` pelo mesmo motivo.

Tolerância a erro de digitação fica fora do v1 deliberadamente: distância de edição também aproxima palavras legítimas e diferentes, e calibrar o limiar sem dado real é chute. Em lugar disso, a etapa expõe um relatório de **quase-casamentos** — palavra da mensagem a distância 1 de uma palavra-chave — que não decide nada e serve para decidir com número real depois da primeira campanha.

**Alternativa descartada:** *duplicar a normalização num serviço próprio.* Duas cópias da mesma regra divergem na primeira correção feita em uma só.

## Decisão 4 — Áudio não casa palavra-chave

**Escolhido:** o casamento lê o texto escrito da mensagem, não `readableText()`.

`readableText()` hoje devolve a transcrição do áudio quando não há texto, e `InboundAttendanceRouter` a usa. A campanha não. A inscrição é um ato com consequência — entra numa lista, concorre a prêmio — e transcrição automática erra. Uma inscrição criada por engano de transcrição é indistinguível, no banco, de uma inscrição de verdade, e quem não se inscreveu não tem como saber que está na lista.

**Alternativa descartada:** *casar sobre a transcrição.* Alcança quem responde por áudio, que numa divulgação por rádio não é pouca gente. O custo é uma lista com inscritos que não se inscreveram, num processo cuja única defesa é ser auditável.

Isso vai para o README como não implementado, e é a primeira coisa a reconsiderar se a divulgação for por áudio.

## Decisão 5 — Nome vem do perfil, e participação sem nome é válida

**Escolhido:** o nome do remetente é preenchido pelo serviço Node, preferindo o nome salvo na agenda do telefone conectado e caindo para o nome de perfil. Participação sem nome fica com situação `sem_nome` e conta como válida.

Perguntar o nome em outro turno dobra as mensagens da campanha e perde quem não responde a segunda. Bloquear por ausência de nome transforma um problema de cadastro em exclusão de participante.

Contato já cadastrado **não** tem o nome sobrescrito pelo nome de perfil do WhatsApp: o cadastro é mais confiável que um apelido escolhido pela pessoa.

## Decisão 6 — O gatilho cria contato, com consentimento e barreira

**Escolhido:** número desconhecido vira contato com `source = gatilho`, `consent_status = granted` e a finalidade gravada como participação na campanha, mais a etiqueta da campanha. Contato com essa origem fica fora da seleção padrão de destinatário de lote.

O consentimento é concedido porque a pessoa fez um ato inequívoco e específico: escreveu uma palavra que só existe no material da campanha. Isso difere do caso geral do atendimento de entrada, onde `InboundAttendanceService` grava `not_informed` — e grava certo, porque quem manda "bom dia" não consentiu com nada.

A contrapartida é que `granted` sozinho abriria a porta para essa pessoa entrar num lote qualquer, o que seria usar um consentimento de participação como consentimento de disparo. Por isso a barreira de finalidade é parte da mesma decisão, e fica em `ContactSelectionService`, não na tela: barreira que depende de a tela lembrar de aplicar é barreira que um dia falha. Incluir esses contatos exige marcação explícita, e a tela diz por que a restrição existe.

**Alternativa descartada:** *`not_informed`, como o atendimento de entrada faz.* Ganha a barreira de graça, porque o contato já não é elegível a lote. Descartado porque descreve errado o que aconteceu: a pessoa consentiu, e o registro precisa dizer isso — inclusive para o caso de ela pedir para ver o que temos sobre ela.

## Decisão 7 — Uma mensagem por pessoa: a campanha suprime a abertura do atendimento

**Escolhido:** quando a campanha registra a participação e responde, o atendimento de entrada não abre para aquela mensagem.

Quem escreveu a palavra-chave já disse o que queria. A abertura genérica do atendimento não acrescenta informação e é a segunda mensagem no mesmo minuto — em uma divulgação de centenas de pessoas, é o dobro do volume exatamente no pico, que é o risco descrito no Impact.

A supressão é da mensagem, não da pessoa: a próxima mensagem dela é roteada normalmente, e uma conversa que já tem fluxo continua com o fluxo.

**Alternativas descartadas:**

- *Responder as duas, espaçadas pelo limitador.* Dobra o volume no pico pelo ganho de uma saudação.
- *Só o atendimento responde, e a participação é registrada em silêncio.* Obriga a duplicar a palavra-chave nas expressões de um perfil e tira da campanha o controle do próprio texto de confirmação, que é o texto que precisa dizer que a inscrição foi aceita.

## Decisão 8 — Limitador próprio, atômico, e sem janela de horário

**Escolhido:** limitador global de confirmação, separado do limitador de lote, com teto por minuto e intervalo mínimo configuráveis, incremento atômico no cache, excedente adiado.

Três pontos, cada um corrigindo um defeito observado no repositório:

- **Atômico.** `SendingRateLimiterService` lê em `check()` e incrementa em `consume()` sem trava, e dois workers furam o teto. Não se repete o defeito aqui.
- **Adiado, não descartado.** Excedente sai depois. Ninguém perde confirmação por ter escrito no minuto errado.
- **Cota consumida no envio.** Existe no projeto o padrão inverso — incrementar na criação e ainda poder bloquear no envio — e ele infla contador.

A confirmação **não** obedece à janela de horário da automação de conversas. Quem escreve às 23h está com o celular na mão; segurar até as 8h produz a segunda e a terceira mensagem da mesma pessoa perguntando se deu certo, o que é pior para a reputação do número do que ter respondido.

O limite de `ConversationAutomationGuard` também não serve aqui: ele é por conversa (`automated_messages_count >= max`), não global por unidade de tempo, e rajada é um problema global.

## Decisão 9 — Elegibilidade é marcada por importação, não verificada na entrada

**Escolhido:** qualquer pessoa se inscreve; um CSV de telefones de alunos marca as participações; o que não casou vai para uma fila de conferência manual; o congelamento exige a fila vazia.

O problema que isso resolve: a campanha é entre alunos, mas a entrada não verifica nada. Sem tratamento, a lista congelada conteria inelegíveis, o sorteio apontaria um deles e seria preciso resortear — e um sorteio refeito porque o ganhador não servia é indistinguível, para quem está de fora, de um sorteio refeito porque o ganhador não agradou. É exatamente o que o congelamento e a semente registrada existem para evitar.

**Alternativas descartadas:**

- *Verificar na entrada e recusar quem não é aluno.* Cria atrito no único momento em que a pessoa está engajada, e recusa por engano quem trocou de número.
- *Conferir depois do sorteio.* Só funciona se o regulamento disser, por escrito e antes do anúncio, que ganhador inelegível é substituído. Passa a depender de um documento em vez de uma trava.

## Decisão 10 — Sorteio reproduzível, com a semente de verdade

**Escolhido:** sorteio apenas sobre lista congelada, gravando hash da lista, semente, quantidade, resultado na ordem sorteada, quem executou e quando. Reexecutar com a mesma semente e a mesma lista produz exatamente o mesmo resultado.

`RandomSelectionService` deriva o estado do gerador com `mt_srand(abs(crc32($seed)))`, o que reduz a semente a 32 bits e joga fora entropia. Para escolher destinatário de lote isso é irrelevante. Para um sorteio cuja auditabilidade é a semente registrada, a semente registrada precisa ser a semente de verdade. A derivação é corrigida sem alterar o comportamento observável do uso em lotes.

Depois de congelada, invalidar uma participação não altera a lista congelada nem um sorteio já executado. Novo sorteio exige novo congelamento — porque a alternativa é uma lista que muda depois de sorteada, que é o mesmo que não ter congelado.

Sorteio é ato deliberado de um humano, com confirmação na tela. Não há agendamento automático.

## Decisão 11 — Unicidade no banco, não na aplicação

**Escolhido:** índice único em `(keyword_campaign_id, contact_id)`. A violação é capturada e tratada como "já inscrito", sem erro.

Duas mensagens quase simultâneas da mesma pessoa perdem a corrida em qualquer verificação feita na aplicação. A trava por conversa da Decisão 1 cobre o caso comum; o índice cobre o caso em que a mesma pessoa escreve de dois números que casam com o mesmo contato, ou em que a trava expira.

## Decisão 12 — Cupom é valor

**Escolhido:** o código do cupom nunca aparece em log, em mensagem de erro, em evento de auditoria ou em exportação. A tela mostra o código apenas para quem tem a permissão de administrar cupons. O corpo da mensagem enviada ao ganhador não é gravado em claro no histórico: grava-se uma referência ao cupom.

Um cupom vazado é resgatado por quem o encontrar, e o histórico de conversa é lido por muito mais gente do que a tela de cupons.
