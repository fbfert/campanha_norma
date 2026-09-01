# Painel de relatórios e pauta de resposta — Subetapa 9F

A subetapa 9E resolveu o dado e parou na tela. A 9F acrescenta o que faltava: o
cruzamento de onde as pessoas estão com o que elas falaram, a pergunta sobre o
que a campanha ainda não escreveu, e o caminho de volta — um dossiê por pessoa,
para alguém responder à mão.

**Nada aqui envia.** Nenhuma tela desta subetapa manda mensagem, agenda envio,
grava áudio ou liga automação. O contrato `App\Contracts\WhatsAppProvider` não
foi tocado e o serviço Node não foi tocado.

---

## As telas e suas permissões

| Tela | Rota | Exige |
|---|---|---|
| Cidade e tema | `admin.analytics.cidade-tema` | `analytics.view_aggregates` |
| Posicionamento | `admin.analytics.posicionamento` | `analytics.view_aggregates` |
| Fila da pauta | `admin.pauta.index` | as três abaixo, juntas |
| Dossiê | `admin.pauta.show` | as três abaixo, juntas |
| Caderno impresso | `admin.pauta.caderno` | as três abaixo, juntas |
| Marcar respondida | `admin.pauta.responder` (POST) | as três abaixo, juntas |

As três da pauta são `response_agenda.view`, `analytics.view_identification` e
`analytics.view_content`. Elas são exigidas **juntas** porque o dossiê expõe
nome, cidade e o texto que a pessoa escreveu: três exposições distintas, três
permissões. A da pauta sozinha daria acesso a tudo isso, que é exatamente o que
as outras duas existem para separar.

Só o papel administrador recebe `response_agenda.view`. Operador e consulta
continuam com as listas que já tinham.

As duas telas agregadas **não** ganharam permissão nova: elas contam menções e
documentos, não mostram quem falou, e permissão que não separa nada só dificulta
a administração.

### Por que dois módulos

O painel agregado vive em `app/Http/Controllers/Admin/Analytics/`. A pauta vive
em `app/Http/Controllers/Admin/ResponseAgenda/`.

A separação não é organização de pastas. As duas coisas obedecem a regras
**opostas**: o painel suprime célula com poucos registros para ninguém ser
identificado a partir de um agregado; a pauta expõe uma pessoa por vez, porque
identificar é o ponto. Duas regras contrárias no mesmo módulo é onde o vazamento
nasce — basta alguém confundir qual caminho está editando.

---

## Configurações

Grupo `pauta`, em `system_settings`:

| Chave | Padrão | O que faz |
|---|---|---|
| `pauta.priority_weight_urgency` | 3 | Peso da urgência na ordenação da fila |
| `pauta.priority_weight_length` | 1 | Peso do tamanho da resposta |
| `pauta.priority_weight_emerging` | 2 | Peso de o tema ser emergente |
| `pauta.answered_lookback_days` | 30 | Janela da detecção de resposta já enviada |

A fórmula está em `docs/analytics-formulas.md`. Os pesos são configuração porque
nenhum deles foi calibrado com dado real: cravá-los no código transformaria três
chutes em regra permanente.

A 9F também usa duas chaves que já existiam: `analytics.minimum_cell_size`, que
governa a supressão do cruzamento, e `analytics.low_confidence_threshold`, que
decide quando o dossiê avisa para conferir a mensagem original.

---

## Como o dossiê é montado, campo a campo

| Bloco | Origem exata |
|---|---|
| Nome e cidade | `contacts.first_name` (ou `name`), `contacts.city`, `contacts.state` |
| A frase da pessoa | `conversation_messages.body` da mensagem de origem, **literal** |
| Localidade declarada | `conversation_insights.locality_text` |
| Tema, urgência, sentimento | `insight_topics.name`, `conversation_insights.urgency`, `.sentiment` |
| Problema, ação, resultado | `.identified_problem`, `.suggested_action`, `.desired_result` |
| O que a campanha defende | `insight_topics.response_guidance` |
| Linha vermelha | `insight_topics.red_lines` |
| Trecho oficial | primeiro `knowledge_chunks.content` de documento aprovado, em base ativa, com `knowledge_documents.insight_topic_id` igual ao tema |
| Aviso de confiança baixa | `conversation_insights.confidence` comparado ao limiar |

### Por que não há IA nessa montagem

A subetapa 9B já extrai, por mensagem e com saída validada por esquema, tudo o
que um roteiro precisaria. Isso não é matéria-prima para um briefing: já é o
briefing, estruturado.

Quatro consequências:

1. **Sem custo por pessoa e sem latência.** Abrir um dossiê é uma consulta.
2. **Sem frase inventada.** Um modelo que parafraseia introduz uma afirmação que
   ninguém escreveu, dentro de um documento que será lido como o que a pessoa
   disse.
3. **Citação literal é mais forte.** Quem responde lê o que o eleitor escreveu de
   verdade. Nenhuma paráfrase melhora isso.
4. **É verificável.** Gerar o mesmo dossiê duas vezes produz o mesmo texto, e
   existe teste afirmando que abrir a pauta não cria nenhum registro de execução
   de modelo. Esse teste é o que impede a 9F de passar a gerar texto sem ninguém
   perceber.

### A linha vermelha, e o que acontece sem ela

Promessa dita pela própria candidata, na voz dela, é pior que promessa de
sistema em texto: não existe "foi o sistema", e não há retratação possível de um
áudio já enviado.

Por isso o dossiê tem uma seção fixa de linha vermelha, escrita por uma pessoa,
em destaque forte. É o único bloco que o sistema não sabe preencher sozinho.

Quando o tema não tem linha vermelha escrita, **o dossiê diz que não tem**.
Seção ausente em silêncio seria lida como "não há nada a evitar aqui", que é o
oposto do que a ausência significa.

Preencher `response_guidance` e `red_lines` por tema é trabalho humano que
nenhum passo automatiza. A tela de posicionamento diz exatamente quais temas
concentram as menções, ordenados pelo que mais apareceu — é por ali que se
começa.

---

## Marcar como respondida

Duas origens, e a fila mostra qual delas marcou cada linha, com a data. Origem
diferente é confiança diferente, e quem lê precisa saber a diferença.

**Detecção pela sincronização.** Existe mensagem de saída com mídia, na mesma
conversa, posterior ao insight e dentro de `pauta.answered_lookback_days`. Se a
candidata gravar o áudio na mesma conta pareada ao sistema, ele chega na próxima
sincronização e a fila se marca sozinha — nenhum botão, nenhuma disciplina
exigida dela.

**Condição, e ela está na tela.** Se ela responder de outro número, isso não
funciona: nada é detectado e vale apenas a marcação à mão. Condição que só o
manual conhece é condição que ninguém conhece.

**Marcação manual.** O botão no dossiê grava `answered_at`, `answered_by` e um
registro de auditoria. Ele **não envia nada**: não manda mensagem, não abre o
WhatsApp e não agenda. Tem precedência sobre a detecção, porque é a afirmação de
uma pessoa.

A regra **não usa `conversation_messages.origin`**. A coluna tem valor padrão
`manual` e o serviço de sincronização não a preenche ao criar a mensagem, de
modo que uma mensagem vinda do WhatsApp Web fica gravada como `manual`. Filtrar
por `sync` pareceria mais preciso e não casaria com nada, em silêncio.

Nada disso é fila nem agendamento: é consulta sobre o que já está gravado.

---

## Como sai o PDF

Pelo próprio navegador. Layout de impressão em Blade
(`x-layouts.impressao`), regras `@media print` em `resources/css/app.css` com os
tokens declarados no `:root`, e um botão que chama `window.print()`.

Nenhuma dependência nova e nada carregado de fora — a mesma razão pela qual a 9E
recusou biblioteca de gráfico: o sistema roda em servidor próprio e precisa
abrir com internet ruim.

**A capa é obrigatória** e traz título, período, fluxo, tamanho da amostra, data
de geração, quem gerou e o aviso de que o material é escuta de demanda e **não é
pesquisa eleitoral registrada**. O aviso vai na capa, não em rodapé: rodapé de
página impressa não é lido, e um documento com números sobre opinião da
população que circula sem essa frase é lido como pesquisa.

No painel a capa aparece só na impressão (classe `.so-impressa`): na tela, o
período e o fluxo já estão nos filtros, à vista e editáveis.

**O caderno leva marca-d'água** em cada página, com quem gerou e a data, e a
geração fica na auditoria (`response_agenda.notebook_generated`). Documento
nominal que vaza precisa ter origem, pelo mesmo motivo que a exportação
detalhada da 9E carrega sal próprio.

O caderno herda os filtros da fila e a ordem de prioridade: quem imprime imprime
o que está vendo.

### O caderno pela linha de comando

Existe antes das telas, e continua útil para gerar um arquivo fora do navegador:

```bash
php artisan relatorios:caderno --de=2026-08-01 --ate=2026-08-31 --fluxo=1 \
  --por="Nome de quem gerou" --saida=storage/app/private/caderno.html
```

O HTML é autocontido, sem CDN e sem JavaScript. O estilo vive em
`resources/caderno/caderno.css` e é embutido no arquivo gerado: o caderno abre
num navegador que não conhece a folha de estilo do sistema, e um arquivo que
dependesse de CSS externo chegaria sem formatação nenhuma.

---

## O que a 9F deliberadamente não faz

- Não envia áudio nem qualquer mídia, e não grava áudio.
- Não gera texto por IA para o roteiro.
- Não dispara em massa e não agenda resposta.
- Não acrescenta dependência, CDN ou biblioteca de gráfico.
- Não toca o recuperador da 9D. A coluna
  `knowledge_documents.insight_topic_id` existe para a pauta de posicionamento e
  há teste barrando o nome dela no recuperador e no objeto de consulta: usá-la
  para escolher o que recuperar faria a opinião coletada decidir a resposta
  oficial.

---

## Limitação conhecida, de fora desta subetapa

`InsightExtractionService` grava `locality_normalized` como nulo mesmo quando a
pessoa declarou onde mora. Hoje, na base de produção, são centenas de insights
com `locality_text` preenchido e **nenhum** normalizado.

Isso afeta também a tela de geografia da 9E, que lê só a coluna normalizada e
por isso mostra a seção de localidade declarada vazia.

O cruzamento da 9F contorna lendo a declaração com reserva no `locality_text`.
Corrigir a extração e preencher o histórico é trabalho de outra subetapa.
