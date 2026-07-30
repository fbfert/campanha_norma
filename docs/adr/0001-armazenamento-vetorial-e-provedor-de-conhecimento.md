# ADR 0001 — Armazenamento vetorial e provedor de base de conhecimento

- **Status**: aceito
- **Data**: 2026-07-29
- **Contexto**: subetapa 9D, base de conhecimento oficial e aprovada
- **Decisores**: implementação da Etapa 9

## Contexto

A subetapa 9D precisa recuperar trechos de documentos aprovados para fundamentar respostas factuais. O escopo impoe três restrições duras:

1. Não exigir a substituição do MySQL.
2. Não introduzir PostgreSQL apenas para esta etapa sem ADR, plano operacional e autorização explícita.
3. Não implementar vetores em coluna MySQL sem justificar tecnicamente e testar limites.

O ambiente de produção e um VPS único com Apache, MariaDB 10.5, Redis e um serviço Node.js para WhatsApp Web. Não existe banco vetorial, não existe segundo servidor de banco e não existe contrato com serviço gerenciado de busca vetorial.

## Corpus real, não hipotético

O conteúdo admissível na base e enumerado pelo próprio escopo: biografia aprovada, histórico público, competências institucionais, propostas aprovadas, posições oficialmente publicadas, agenda pública autorizada, perguntas frequentes e canais de contato.

Isso não e um corpus de crescimento aberto. E um conjunto curado, aprovado documento por documento por um humano com permissão própria. A estimativa de trabalho e:

| Grandeza | Estimativa |
|---|---|
| Documentos aprovados simultaneamente | 20 a 100 |
| Trechos por documento | 10 a 200 |
| Trechos totais | 500 a 10.000 |
| Dimensão do vetor (modelo pequeno) | 1.536 |
| Bytes por vetor (float de 32 bits) | 6.144 |
| Volume de embeddings em 10.000 trechos | ~59 MB |

Cinquenta e nove megabytes de blob em MariaDB não e um problema de engenharia. E menos do que a tabela de mensagens de conversa já ocupa.

## Decisão

**Armazenar os embeddings em `knowledge_chunks.embedding`, coluna `blob`, e calcular similaridade por cosseno em PHP sobre um conjunto candidato limitado.**

Detalhes que tornam a decisão defensável:

- **Serialização compacta.** Sequência de floats de 32 bits em ordem de bytes fixa (`pack('g*')`), não JSON e não texto. JSON custaria cerca de trinta vezes mais espaço para a mesma informação e obrigaria a decodificar strings a cada consulta.
- **Teto da coluna, verificado.** `blob` em MariaDB comporta 65.535 bytes, o que da **16.383 dimensões** com floats de 32 bits. O modelo pequeno usa 1.536 e o maior modelo de embedding em uso comercial hoje usa 3.072 — folga de mais de cinco vezes sobre o maior caso real. `mediumblob` ou `longblob` acrescentariam apenas bytes de cabeçalho por linha para cobrir um cenário que nenhum modelo existente alcança. Se algum dia um modelo passar de 16.383 dimensões, a troca de tipo e um `ALTER TABLE` isolado, sem migração de dados.
- **Dimensão persistida.** `embedding_dimensions`, `embedding_provider` e `embedding_model` ficam na própria linha. Trocar de modelo não corrompe leitura: vetores com dimensão diferente da configurada são ignorados na busca e sinalizados pelo diagnostico.
- **Conjunto candidato limitado.** A busca vetorial filtra antes por base associada ao fluxo, status aprovado e versão vigente. Sobre o resultado disso ha um teto explícito, `knowledge.max_vector_candidates`, com padrão 5.000.
- **Recusa em vez de degradação.** Acima do teto a busca vetorial não fica lenta em silêncio: ela recusa, registra o motivo no log de recuperação e cai para a estratégia léxica. O sistema continua respondendo, com qualidade menor e rastro explícito.
- **Limite testado.** `tests/Feature/KnowledgeVectorLimitsTest.php` monta um corpus sintético, exercita a busca e falha se o comportamento sair da faixa documentada aqui.

**A estratégia padrão de recuperação e a léxica, não a vetorial.**

Esta parte da decisão e menos óbvia e mais importante. A busca vetorial depende de um provedor de embeddings com credencial. Se o padrão fosse vetorial, a base ficaria inerte em todo ambiente sem chave — incluindo homologação — e a única forma de exercitar o recurso seria pagar por chamadas externas. Com a léxica como padrão, a base funciona, e testável e e homologável sem nenhuma dependência externa. A vetorial e um ganho de qualidade que se liga depois, com uma linha de configuração.

A estratégia léxica normaliza acentuação e caixa, remove palavras vazias configuráveis e pontua por cobertura de termos, frequência e proximidade. Aplica o mesmo `top_k`, o mesmo threshold e o mesmo limite de contexto da vetorial. Toda a camada acima dela — filtro de aprovação, log, snapshot, validação de fundamentação — e identica.

A estratégia híbrida funde as duas pontuações por `min(1, max(lex, vec) + 0,10 x min(lex, vec))`: um trecho forte em qualquer uma das duas sobrevive, e concordância entre elas ganha um bonus pequeno. Uma média simples faria a híbrida ser pior que a melhor das partes sempre que uma das estratégias não reconhecesse o trecho, que e justamente o caso comum.

## Provedor de base de conhecimento

Duas implementações do contrato `KnowledgeBaseProvider`:

- **`null`** (padrão) — inerte. Não indexa e não recupera. Existe para que a camada possa estar instalada e desligada, no mesmo padrão do `NullAiProvider` da 9B.
- **`local`** — armazenamento relacional descrito acima. E a implementação de trabalho.

**Não implementamos um provedor gerenciado externo nesta subetapa.** Essa e uma decisão consciente e vale registrar o motivo: uma integração com armazenamento vetorial gerenciado que nunca foi executada contra a API real e código não verificado. Entregar isso como se estivesse pronto seria pior do que não entregar. O que a subetapa entrega e o **contrato** que torna a troca possível sem tocar em nenhum chamador, mais as colunas que um provedor externo precisaria (`knowledge_bases.external_store_id`, `knowledge_documents.provider_file_id`, `knowledge_chunks.external_chunk_id`), mais o procedimento de troca abaixo.

## Consequências

### Positivas

- Nenhuma infraestrutura nova em produção. Nenhum backup novo, nenhum serviço novo para monitorar, nenhuma credencial nova obrigatória.
- O recurso e utilizável e homologável desde o primeiro dia, sem credencial de embedding.
- Backup e restauração da base de conhecimento acompanham o dump do MySQL que já existe.
- Trocar de provedor não toca em chamador nenhum.

### Negativas

- A busca vetorial e O(n) sobre o conjunto candidato. Aceitável na faixa documentada, insustentável muito acima dela.
- Similaridade em PHP consome CPU do processo de fila. Mitigado por a indexação viver em fila própria e por a busca acontecer no job de geração, não no caminho de recebimento de mensagem.
- A estratégia léxica erra em sinonimia e parafrase. E o preço de funcionar sem credencial; quem quiser qualidade maior liga a vetorial ou a híbrida.

### Gatilho de migração

Migrar para armazenamento vetorial dedicado quando **qualquer** destas condições se sustentar:

1. Trechos aprovados passarem de 20.000 (o dobro do teto de trabalho estimado).
2. A latência mediana da recuperação passar de 500 ms com a estratégia vetorial ativa.
3. O diagnostico passar a recusar busca vetorial por limite de candidatos de forma recorrente.
4. A base precisar ser compartilhada entre mais de uma aplicação.

## Procedimento de troca de provedor

1. Implementar `App\Contracts\KnowledgeBaseProvider` para o destino, persistindo `external_store_id`, `provider_file_id` e `external_chunk_id`.
2. Registrar a implementação em `config/knowledge.php`, em `providers`.
3. Criar as bases no destino com `php artisan knowledge:sync --create-stores`.
4. Reindexar com `php artisan knowledge:index --base=<id> --status=approved`. A reindexação **não** rebaixa o status de aprovação quando o texto extraido não muda: o hash de conteúdo do trecho e a chave.
5. Conferir com `php artisan knowledge:diagnose` que não ha documento aprovado sem trecho, trecho sem identificador externo nem divergência de dimensão.
6. Trocar `KNOWLEDGE_PROVIDER` no `.env` e limpar cache de configuração.
7. Manter o provedor anterior disponível por um ciclo de homologação antes de remover.

Rollback: voltar `KNOWLEDGE_PROVIDER` para `local` e limpar o cache. Os trechos e embeddings relacionais não são apagados pela troca.

## Rollback para operar sem recuperação

`knowledge.enabled = 0` desliga a recuperação inteira. A geração volta ao comportamento da 9C: pergunta factual vira handoff humano ou texto institucional fixo. As subetapas 9A, 9B e 9C continuam integralmente funcionais.

## Pendências registradas

- **Extração de PDF.** `pdftotext` não esta instalado neste ambiente. O comando e configurável em `knowledge.pdf_text_command` e, na ausência dele, o documento falha com código `extrator_pdf_indisponivel`. Não existe fallback nativo por decisão explícita: texto de PDF parcialmente decodificado dentro de uma base oficial e pior do que uma falha limpa. Instalar com `dnf install poppler-utils`.
- **Antivirus.** ClamAV 1.4.3 com base de assinaturas atualizada esta presente no host e e usado. `knowledge.antivirus_required` nasce ligado: sem scanner disponível, o upload e recusado.
