# Base de conhecimento oficial (Etapa 9D)

Operacao e seguranca da camada que permite responder perguntas factuais com base
em documentos oficiais aprovados.

A regra que organiza tudo o que vem abaixo: **aprovacao humana e a condicao de
existencia de um trecho na busca**. Nao ha caminho automatico que torne conteudo
recuperavel, e nenhuma resposta factual sai sem evidencia conferida.

---

## 1. O que esta camada faz e o que ela nao faz

Faz:

- guarda documentos oficiais, segmentados em trechos, com procedencia completa;
- recupera trechos aprovados para a pergunta da pessoa;
- entrega esses trechos ao modelo em um bloco declarado como **dado**;
- confere, depois da resposta, se o que o modelo escreveu se sustenta nos trechos citados;
- registra o que foi buscado e o que foi usado.

Nao faz:

- nao usa conversa de outra pessoa como fonte;
- nao usa a base de opinioes coletadas na pesquisa como fonte de resposta individual;
- nao completa lacuna com conhecimento geral do modelo;
- nao envia nada: continua valendo a aprovacao humana da Etapa 9C.

---

## 2. Ligar e desligar

| Chave | Padrao | Efeito |
| --- | --- | --- |
| `knowledge.enabled` | `0` | Chave global. Em `0`, nenhuma busca acontece e a geracao usa o contrato da 9C. |
| associacao base ↔ fluxo | nenhuma | A base e opt-in por fluxo. Fluxo sem base associada nao produz recuperacao. |
| situacao da base | `draft` | Somente base `active` participa da busca. |
| situacao do documento | `draft` | Somente documento `approved` participa da busca. |

As quatro condicoes sao conjuntivas. Desligar qualquer uma interrompe a
recuperacao sem apagar nada.

**Rollback:** `knowledge.enabled = 0`. A geracao volta ao prompt `v1` e ao schema
`1` da 9C no run seguinte. Nao requer deploy, migration nem limpeza de dados.

---

## 3. Configuracoes

Tudo em `system_settings`, grupo `knowledge`. Nenhum valor operacional esta em
codigo.

### Recuperacao

| Chave | Padrao | Observacao |
| --- | --- | --- |
| `knowledge.retrieval_strategy` | `lexical` | `lexical`, `vector` ou `hybrid`. |
| `knowledge.top_k` | `5` | Trechos devolvidos por busca. |
| `knowledge.score_threshold` | `0.25` | Abaixo disso o trecho nao entra no contexto. |
| `knowledge.max_context_chars` | `4000` | Teto do bloco oficial no prompt. |
| `knowledge.max_lexical_candidates` | `2000` | Teto de trechos avaliados na busca lexica. |
| `knowledge.max_vector_candidates` | `5000` | Teto do ADR 0001. Acima disso a busca vetorial recusa e o resultado sai da lexica. |
| `knowledge.proximity_window` | `400` | Janela para pontuar proximidade entre termos. |
| `knowledge.min_term_length` | `3` | Termos menores sao ignorados. |
| `knowledge.stop_words` | lista | Separadas por barra vertical. |

### Ingestao

| Chave | Padrao | Observacao |
| --- | --- | --- |
| `knowledge.queue` | `knowledge-indexing` | Fila propria. |
| `knowledge.chunk_size` | `1200` | Tamanho alvo do trecho em caracteres. |
| `knowledge.chunk_overlap` | `150` | Sobreposicao entre trechos. |
| `knowledge.max_file_size_mb` | `20` | Teto de upload. |
| `knowledge.accepted_mime_types` | lista | Conferido pelo MIME **real**, nunca pelo declarado no upload. |
| `knowledge.antivirus_required` | `1` | Em `1`, upload e recusado quando o scanner esta ausente. |
| `knowledge.injection_patterns` | lista | Expressoes tratadas como injecao dentro de documentos. |

### Fundamentacao

| Chave | Padrao | Observacao |
| --- | --- | --- |
| `knowledge.factual_markers` | lista | Expressoes que caracterizam afirmacao factual e exigem evidencia. |
| `knowledge.commitment_markers` | lista | Expressoes de compromisso. |
| `knowledge.commitment_support_markers` | lista | O que, no trecho citado, sustenta um compromisso. |
| `knowledge.show_citations_to_contact` | `0` | Citacoes sao internas por padrao. |
| `knowledge.retrieval_retention_days` | `180` | Retencao do log de busca. `0` desliga a limpeza. |

### Ambiente (`.env`)

```
KNOWLEDGE_PROVIDER=local
KNOWLEDGE_DISK=local
KNOWLEDGE_DIRECTORY=knowledge-documents
KNOWLEDGE_EMBEDDING_PROVIDER=null
KNOWLEDGE_EMBEDDING_MODEL=text-embedding-3-small
KNOWLEDGE_EMBEDDING_DIMENSIONS=1536
KNOWLEDGE_PDF_TEXT_COMMAND="pdftotext -layout -enc UTF-8 :input -"
KNOWLEDGE_ANTIVIRUS_COMMAND="clamscan --no-summary --stdout :input"
```

Chave de embeddings (`KNOWLEDGE_EMBEDDING_KEY`) so e necessaria nas estrategias
`vector` e `hybrid`. Com `lexical` a base funciona sem nenhuma credencial externa.

---

## 4. Implantacao

1. `php84 artisan migrate` — cria sete tabelas e acrescenta colunas de fundamentacao em `conversation_reply_suggestions`.
2. `php84 artisan db:seed --class=SystemSettingSeeder` — grava as chaves `knowledge.*` desligadas.
3. `php84 artisan db:seed --class=RolePermissionSeeder` — cria as permissoes `knowledge.*`.
4. `php84 artisan cache:clear` — `SystemSettingService` cacheia para sempre.
5. Acrescentar `knowledge-indexing` as filas do worker systemd.
6. `php84 artisan knowledge:diagnose` — confere provedores, ferramentas externas e estado.

Filas do worker apos esta etapa:

```
whatsapp-send,conversation-automation,conversation-automation-send,ai-interpretation,ai-response-generation,ai-response-send,knowledge-indexing
```

### Dependencia externa pendente

`pdftotext` **nao esta instalado** neste servidor. Enquanto isso, upload de PDF
falha de forma limpa com `extrator_pdf_indisponivel` e o documento fica em
`failed`. Para habilitar:

```
dnf install poppler-utils
```

ClamAV **esta** presente (`/bin/clamscan`, base de assinaturas atualizada) e
`knowledge.antivirus_required` vem ligado.

---

## 5. Ciclo de vida de um documento

```
draft ─► processing ─► ready ─► approved ─► obsolete
                  │        │
                  │        └─► rejected
                  └─► failed
```

- **processing** — enfileirado para extracao, sanitizacao, segmentacao e indexacao.
- **ready** — indexado com sucesso. **Ainda invisivel para a busca.**
- **approved** — unica situacao recuperavel, e somente dentro de base `active`.
- **rejected** — recusado na revisao.
- **obsolete** — sai da busca sem apagar historico. Aplicado automaticamente ao documento substituido quando a nova versao e aprovada.
- **failed** — extracao ou indexacao falhou. O motivo fica em `error_message` como codigo, nunca como mensagem do provedor.

Reprocessar **revoga a aprovacao**: conteudo novo exige leitura humana nova.

Excluir remove arquivo e trechos, mas nunca toca em `knowledge_retrievals`,
`knowledge_retrieval_chunks` ou `reply_suggestion_citations`.

---

## 6. Tipos de conteudo permitidos

Somente estes oito, aplicados por enum no banco e por validacao na tela:

biografia, historico publico, competencia institucional, proposta aprovada,
posicao oficial, agenda publica, perguntas frequentes, canais de contato.

Nao envie: conversa de cidadao, opiniao coletada na pesquisa, dado pessoal,
documento sigiloso, material de terceiro sem autorizacao, conteudo de campanha
eleitoral, promessa ou compromisso nao formalizado.

---

## 7. Seguranca

### Arquivo

- Disco privado, fora de `public/`.
- O nome em disco e um UUID gerado no servidor. O nome enviado **nunca** participa da montagem do caminho: nao existe superficie para path traversal.
- O nome original sobrevive apenas como rotulo higienizado.
- MIME conferido pelo conteudo real (`getMimeType()`), nao pelo cabecalho do upload.
- Antivirus roda apos a gravacao; arquivo suspeito ou nao verificavel e apagado do disco.
- Download exige `knowledge.download_documents`, usa o caminho gravado no banco e e auditado.

### Injecao de prompt

Duas defesas, porque nenhuma basta sozinha:

1. **Na ingestao** — cada linha e comparada, ja normalizada, contra `knowledge.injection_patterns`. A linha que casa e substituida por um marcador e o documento e sinalizado (`injection_flagged`), com os achados visiveis na tela antes da aprovacao.
2. **No prompt** — os trechos vao em um bloco delimitado, declarado como material de referencia, com a instrucao explicita de que ordens dentro dele devem ser ignoradas. O prompt de sistema prevalece sempre.

A primeira erra por padrao incompleto; a segunda erra se o modelo ignorar a
delimitacao. Juntas cobrem o buraco uma da outra.

Nenhum documento pode alterar ferramenta, credencial ou politica: o conteudo
recuperado so alcanca o prompt como texto.

### Dados pessoais

- `RetrievalQuery` nao carrega identificador de contato. Um teste le o arquivo para garantir que continue assim.
- `LocalKnowledgeRetriever` consulta apenas `knowledge_*`. Um teste le o codigo (sem comentarios) e falha se `Conversation`, `Contact` ou `ConversationInsight` aparecerem.
- A consulta gravada no log e truncada em 1000 caracteres.
- Logs registram codigo de erro, contagem e duracao — nunca credencial, caminho de arquivo ou corpo de requisicao.

---

## 8. Fundamentacao

O campo `grounded` devolvido pelo modelo e **sinal**, nunca autorizacao. A
validacao acontece depois, e e deterministica.

| Veredito | Significado | Envio |
| --- | --- | --- |
| `not_required` | O texto nao afirma nada factual. | permitido |
| `grounded` | Afirmacao factual sustentada pelos trechos citados. | permitido |
| `no_evidence` | Afirmacao factual sem nenhuma citacao valida. | bloqueado |
| `invalid_citation` | Citou algo fora do conjunto recuperado. | bloqueado |
| `obsolete_citation` | Citou documento que deixou de ser recuperavel. | bloqueado |
| `unsupported_number` | Numero que nao esta no trecho citado. | bloqueado |
| `unsupported_date` | Data que nao esta no trecho citado. | bloqueado |
| `unsupported_commitment` | Compromisso sem suporte explicito. | bloqueado |
| `grounded_without_citation` | Declarou-se fundamentado sem citar nada. | bloqueado |

Reprovacao nunca produz texto alternativo: produz bloqueio e handoff
(`insufficient_evidence` para `no_evidence`, `ungrounded_answer` para os demais).

Data e conferida antes de numero de proposito: uma data e feita de numeros, e a
ordem inversa reportaria motivo enganoso.

---

## 9. Rastreabilidade

Cada busca grava `knowledge_retrievals` e uma linha por trecho devolvido em
`knowledge_retrieval_chunks`, com **snapshot** de conteudo, titulo e versao.
Cada sugestao fundamentada grava `reply_suggestion_citations`, com o mesmo
snapshot.

E duplicacao de texto deliberada. Sem ela, substituir ou excluir um documento
apagaria a explicacao de toda resposta que ele sustentou. Citacao recusada
tambem vira linha, com o motivo e sem vinculo de documento — o identificador que
o modelo inventou nao aponta para nada.

Retencao: `knowledge:prune-retrievals` limpa o log de busca conforme
`knowledge.retrieval_retention_days`. **Nao** toca nas citacoes: a justificativa
de algo que chegou a uma pessoa tem ciclo de vida mais longo.

---

## 10. Comandos

| Comando | Uso |
| --- | --- |
| `knowledge:diagnose` | Configuracao, provedores, ferramentas externas e estado por base. Nao chama provedor externo. |
| `knowledge:index` | Enfileira reindexacao. `--base=`, `--document=`, `--failed`, `--dry-run`. Revoga a aprovacao. |
| `knowledge:sync` | Reconcilia contagem de trechos, remove trecho orfao e relata arquivo ausente ou documento parado em processamento. `--base=`, `--dry-run`. Nunca aprova, nunca reindexa, nunca exclui documento. |
| `knowledge:prune-retrievals` | Retencao do log de busca. `--days=`, `--dry-run`. |

---

## 11. Operacao no dia a dia

**Publicar conteudo novo**

1. Criar ou escolher a base. Base nova nasce em `draft`.
2. Enviar o documento. Conferir o texto extraido e os trechos na tela do documento.
3. Se houver aviso de instrucao detectada, revisar o material antes de qualquer coisa.
4. Testar em *Teste de busca na base* com perguntas reais.
5. Aprovar o documento.
6. Ativar a base e associa-la ao fluxo.
7. Ligar `knowledge.enabled`.

**Substituir uma versao**

Enviar o novo documento apontando `supersedes_document_id` para o antigo. Ao
aprovar o novo, o antigo vira `obsolete` sozinho.

**Tirar algo do ar rapidamente**

Marcar o documento como obsoleto, ou desativar a base. Os dois interrompem a
recuperacao imediatamente e nao apagam historico.

---

## 12. Diagnostico de problemas

| Sintoma | Verificar |
| --- | --- |
| Busca sempre vazia | `knowledge.enabled`; base `active`; base associada ao fluxo; documento `approved`; `knowledge.score_threshold` alto demais. |
| `degraded_reason = sem_base_associada` | O fluxo nao tem base ativa associada. |
| `degraded_reason = consulta_sem_termos` | A mensagem so tem stop words ou termos curtos. |
| `degraded_reason = limite_de_candidatos_excedido` | Corpus acima de `knowledge.max_vector_candidates`. Ver ADR 0001 antes de simplesmente subir o teto. |
| `degraded_reason = embeddings_nao_configurados` | Estrategia vetorial sem provedor configurado. |
| Documento em `failed` com `extrator_pdf_indisponivel` | `pdftotext` ausente. |
| Upload recusado com `antivirus_indisponivel` | ClamAV parado. |
| Muitas respostas em `no_evidence` | A base nao cobre as perguntas que chegam, ou `top_k`/threshold estao restritivos demais. |
| Muitas respostas em `unsupported_number` | O modelo esta arredondando ou combinando trechos. Revisar o prompt antes de afrouxar a validacao. |

---

## 13. Documentos relacionados

- `docs/adr/0001-armazenamento-vetorial-e-provedor-de-conhecimento.md` — escolha de armazenamento, limites medidos e procedimento de troca de provedor.
- `docs/tests/knowledge-base-manual-etapa-9d.md` — roteiro de homologacao manual.
- `openspec/changes/etapa-9d-base-conhecimento-rag/` — proposta, design e specs.
