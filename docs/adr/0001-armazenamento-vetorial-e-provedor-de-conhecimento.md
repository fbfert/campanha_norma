# ADR 0001 — Armazenamento vetorial e provedor de base de conhecimento

- **Status**: aceito
- **Data**: 2026-07-29
- **Contexto**: subetapa 9D, base de conhecimento oficial e aprovada
- **Decisores**: implementacao da Etapa 9

## Contexto

A subetapa 9D precisa recuperar trechos de documentos aprovados para fundamentar respostas factuais. O escopo impoe tres restricoes duras:

1. Nao exigir a substituicao do MySQL.
2. Nao introduzir PostgreSQL apenas para esta etapa sem ADR, plano operacional e autorizacao explicita.
3. Nao implementar vetores em coluna MySQL sem justificar tecnicamente e testar limites.

O ambiente de producao e um VPS unico com Apache, MariaDB 10.5, Redis e um servico Node.js para WhatsApp Web. Nao existe banco vetorial, nao existe segundo servidor de banco e nao existe contrato com servico gerenciado de busca vetorial.

## Corpus real, nao hipotetico

O conteudo admissivel na base e enumerado pelo proprio escopo: biografia aprovada, historico publico, competencias institucionais, propostas aprovadas, posicoes oficialmente publicadas, agenda publica autorizada, perguntas frequentes e canais de contato.

Isso nao e um corpus de crescimento aberto. E um conjunto curado, aprovado documento por documento por um humano com permissao propria. A estimativa de trabalho e:

| Grandeza | Estimativa |
|---|---|
| Documentos aprovados simultaneamente | 20 a 100 |
| Trechos por documento | 10 a 200 |
| Trechos totais | 500 a 10.000 |
| Dimensao do vetor (modelo pequeno) | 1.536 |
| Bytes por vetor (float de 32 bits) | 6.144 |
| Volume de embeddings em 10.000 trechos | ~59 MB |

Cinquenta e nove megabytes de blob em MariaDB nao e um problema de engenharia. E menos do que a tabela de mensagens de conversa ja ocupa.

## Decisao

**Armazenar os embeddings em `knowledge_chunks.embedding`, coluna `blob`, e calcular similaridade por cosseno em PHP sobre um conjunto candidato limitado.**

Detalhes que tornam a decisao defensavel:

- **Serializacao compacta.** Sequencia de floats de 32 bits em ordem de bytes fixa (`pack('g*')`), nao JSON e nao texto. JSON custaria cerca de trinta vezes mais espaco para a mesma informacao e obrigaria a decodificar strings a cada consulta.
- **Teto da coluna, verificado.** `blob` em MariaDB comporta 65.535 bytes, o que da **16.383 dimensoes** com floats de 32 bits. O modelo pequeno usa 1.536 e o maior modelo de embedding em uso comercial hoje usa 3.072 — folga de mais de cinco vezes sobre o maior caso real. `mediumblob` ou `longblob` acrescentariam apenas bytes de cabecalho por linha para cobrir um cenario que nenhum modelo existente alcanca. Se algum dia um modelo passar de 16.383 dimensoes, a troca de tipo e um `ALTER TABLE` isolado, sem migracao de dados.
- **Dimensao persistida.** `embedding_dimensions`, `embedding_provider` e `embedding_model` ficam na propria linha. Trocar de modelo nao corrompe leitura: vetores com dimensao diferente da configurada sao ignorados na busca e sinalizados pelo diagnostico.
- **Conjunto candidato limitado.** A busca vetorial filtra antes por base associada ao fluxo, status aprovado e versao vigente. Sobre o resultado disso ha um teto explicito, `knowledge.max_vector_candidates`, com padrao 5.000.
- **Recusa em vez de degradacao.** Acima do teto a busca vetorial nao fica lenta em silencio: ela recusa, registra o motivo no log de recuperacao e cai para a estrategia lexica. O sistema continua respondendo, com qualidade menor e rastro explicito.
- **Limite testado.** `tests/Feature/KnowledgeVectorLimitsTest.php` monta um corpus sintetico, exercita a busca e falha se o comportamento sair da faixa documentada aqui.

**A estrategia padrao de recuperacao e a lexica, nao a vetorial.**

Esta parte da decisao e menos obvia e mais importante. A busca vetorial depende de um provedor de embeddings com credencial. Se o padrao fosse vetorial, a base ficaria inerte em todo ambiente sem chave — incluindo homologacao — e a unica forma de exercitar o recurso seria pagar por chamadas externas. Com a lexica como padrao, a base funciona, e testavel e e homologavel sem nenhuma dependencia externa. A vetorial e um ganho de qualidade que se liga depois, com uma linha de configuracao.

A estrategia lexica normaliza acentuacao e caixa, remove palavras vazias configuraveis e pontua por cobertura de termos, frequencia e proximidade. Aplica o mesmo `top_k`, o mesmo threshold e o mesmo limite de contexto da vetorial. Toda a camada acima dela — filtro de aprovacao, log, snapshot, validacao de fundamentacao — e identica.

A estrategia hibrida funde as duas pontuacoes por `min(1, max(lex, vec) + 0,10 x min(lex, vec))`: um trecho forte em qualquer uma das duas sobrevive, e concordancia entre elas ganha um bonus pequeno. Uma media simples faria a hibrida ser pior que a melhor das partes sempre que uma das estrategias nao reconhecesse o trecho, que e justamente o caso comum.

## Provedor de base de conhecimento

Duas implementacoes do contrato `KnowledgeBaseProvider`:

- **`null`** (padrao) — inerte. Nao indexa e nao recupera. Existe para que a camada possa estar instalada e desligada, no mesmo padrao do `NullAiProvider` da 9B.
- **`local`** — armazenamento relacional descrito acima. E a implementacao de trabalho.

**Nao implementamos um provedor gerenciado externo nesta subetapa.** Essa e uma decisao consciente e vale registrar o motivo: uma integracao com armazenamento vetorial gerenciado que nunca foi executada contra a API real e codigo nao verificado. Entregar isso como se estivesse pronto seria pior do que nao entregar. O que a subetapa entrega e o **contrato** que torna a troca possivel sem tocar em nenhum chamador, mais as colunas que um provedor externo precisaria (`knowledge_bases.external_store_id`, `knowledge_documents.provider_file_id`, `knowledge_chunks.external_chunk_id`), mais o procedimento de troca abaixo.

## Consequencias

### Positivas

- Nenhuma infraestrutura nova em producao. Nenhum backup novo, nenhum servico novo para monitorar, nenhuma credencial nova obrigatoria.
- O recurso e utilizavel e homologavel desde o primeiro dia, sem credencial de embedding.
- Backup e restauracao da base de conhecimento acompanham o dump do MySQL que ja existe.
- Trocar de provedor nao toca em chamador nenhum.

### Negativas

- A busca vetorial e O(n) sobre o conjunto candidato. Aceitavel na faixa documentada, insustentavel muito acima dela.
- Similaridade em PHP consome CPU do processo de fila. Mitigado por a indexacao viver em fila propria e por a busca acontecer no job de geracao, nao no caminho de recebimento de mensagem.
- A estrategia lexica erra em sinonimia e parafrase. E o preco de funcionar sem credencial; quem quiser qualidade maior liga a vetorial ou a hibrida.

### Gatilho de migracao

Migrar para armazenamento vetorial dedicado quando **qualquer** destas condicoes se sustentar:

1. Trechos aprovados passarem de 20.000 (o dobro do teto de trabalho estimado).
2. A latencia mediana da recuperacao passar de 500 ms com a estrategia vetorial ativa.
3. O diagnostico passar a recusar busca vetorial por limite de candidatos de forma recorrente.
4. A base precisar ser compartilhada entre mais de uma aplicacao.

## Procedimento de troca de provedor

1. Implementar `App\Contracts\KnowledgeBaseProvider` para o destino, persistindo `external_store_id`, `provider_file_id` e `external_chunk_id`.
2. Registrar a implementacao em `config/knowledge.php`, em `providers`.
3. Criar as bases no destino com `php artisan knowledge:sync --create-stores`.
4. Reindexar com `php artisan knowledge:index --base=<id> --status=approved`. A reindexacao **nao** rebaixa o status de aprovacao quando o texto extraido nao muda: o hash de conteudo do trecho e a chave.
5. Conferir com `php artisan knowledge:diagnose` que nao ha documento aprovado sem trecho, trecho sem identificador externo nem divergencia de dimensao.
6. Trocar `KNOWLEDGE_PROVIDER` no `.env` e limpar cache de configuracao.
7. Manter o provedor anterior disponivel por um ciclo de homologacao antes de remover.

Rollback: voltar `KNOWLEDGE_PROVIDER` para `local` e limpar o cache. Os trechos e embeddings relacionais nao sao apagados pela troca.

## Rollback para operar sem recuperacao

`knowledge.enabled = 0` desliga a recuperacao inteira. A geracao volta ao comportamento da 9C: pergunta factual vira handoff humano ou texto institucional fixo. As subetapas 9A, 9B e 9C continuam integralmente funcionais.

## Pendencias registradas

- **Extracao de PDF.** `pdftotext` nao esta instalado neste ambiente. O comando e configuravel em `knowledge.pdf_text_command` e, na ausencia dele, o documento falha com codigo `extrator_pdf_indisponivel`. Nao existe fallback nativo por decisao explicita: texto de PDF parcialmente decodificado dentro de uma base oficial e pior do que uma falha limpa. Instalar com `dnf install poppler-utils`.
- **Antivirus.** ClamAV 1.4.3 com base de assinaturas atualizada esta presente no host e e usado. `knowledge.antivirus_required` nasce ligado: sem scanner disponivel, o upload e recusado.
