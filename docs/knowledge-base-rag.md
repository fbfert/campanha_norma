# Base de conhecimento oficial (Etapa 9D)

Operação e segurança da camada que permite responder perguntas factuais com base
em documentos oficiais aprovados.

A regra que organiza tudo o que vem abaixo: **aprovação humana e a condição de
existência de um trecho na busca**. Não ha caminho automático que torne conteúdo
recuperável, e nenhuma resposta factual sai sem evidência conferida.

---

## 1. O que esta camada faz e o que ela não faz

Faz:

- guarda documentos oficiais, segmentados em trechos, com procedência completa;
- recupera trechos aprovados para a pergunta da pessoa;
- entrega esses trechos ao modelo em um bloco declarado como **dado**;
- confere, depois da resposta, se o que o modelo escreveu se sustenta nos trechos citados;
- registra o que foi buscado e o que foi usado.

Não faz:

- não usa conversa de outra pessoa como fonte;
- não usa a base de opiniões coletadas na pesquisa como fonte de resposta individual;
- não completa lacuna com conhecimento geral do modelo;
- não envia nada: continua valendo a aprovação humana da Etapa 9C.

---

## 2. Ligar e desligar

| Chave | Padrão | Efeito |
| --- | --- | --- |
| `knowledge.enabled` | `0` | Chave global. Em `0`, nenhuma busca acontece e a geração usa o contrato da 9C. |
| associação base ↔ fluxo | nenhuma | A base e opt-in por fluxo. Fluxo sem base associada não produz recuperação. |
| situação da base | `draft` | Somente base `active` participa da busca. |
| situação do documento | `draft` | Somente documento `approved` participa da busca. |

As quatro condições são conjuntivas. Desligar qualquer uma interrompe a
recuperação sem apagar nada.

**Rollback:** `knowledge.enabled = 0`. A geração volta ao prompt `v1` e ao schema
`1` da 9C no run seguinte. Não requer deploy, migration nem limpeza de dados.

---

## 3. Configurações

Tudo em `system_settings`, grupo `knowledge`. Nenhum valor operacional esta em
código.

### Recuperação

| Chave | Padrão | Observação |
| --- | --- | --- |
| `knowledge.retrieval_strategy` | `lexical` | `lexical`, `vector` ou `hybrid`. |
| `knowledge.top_k` | `5` | Trechos devolvidos por busca. |
| `knowledge.score_threshold` | `0.25` | Abaixo disso o trecho não entra no contexto. |
| `knowledge.max_context_chars` | `4000` | Teto do bloco oficial no prompt. |
| `knowledge.max_lexical_candidates` | `2000` | Teto de trechos avaliados na busca léxica. |
| `knowledge.max_vector_candidates` | `5000` | Teto do ADR 0001. Acima disso a busca vetorial recusa e o resultado sai da léxica. |
| `knowledge.proximity_window` | `400` | Janela para pontuar proximidade entre termos. |
| `knowledge.min_term_length` | `3` | Termos menores são ignorados. |
| `knowledge.stop_words` | lista | Separadas por barra vertical. |

### Ingestão

| Chave | Padrão | Observação |
| --- | --- | --- |
| `knowledge.queue` | `knowledge-indexing` | Fila própria. |
| `knowledge.chunk_size` | `1200` | Tamanho alvo do trecho em caracteres. |
| `knowledge.chunk_overlap` | `150` | Sobreposição entre trechos. |
| `knowledge.max_file_size_mb` | `20` | Teto de upload. |
| `knowledge.accepted_mime_types` | lista | Conferido pelo MIME **real**, nunca pelo declarado no upload. |
| `knowledge.antivirus_required` | `1` | Em `1`, upload e recusado quando o scanner esta ausente. |
| `knowledge.injection_patterns` | lista | Expressões tratadas como injeção dentro de documentos. |

### Fundamentação

| Chave | Padrão | Observação |
| --- | --- | --- |
| `knowledge.factual_markers` | lista | Expressões que caracterizam afirmação factual e exigem evidência. |
| `knowledge.commitment_markers` | lista | Expressões de compromisso. |
| `knowledge.commitment_support_markers` | lista | O que, no trecho citado, sustenta um compromisso. |
| `knowledge.show_citations_to_contact` | `0` | Citações são internas por padrão. |
| `knowledge.retrieval_retention_days` | `180` | Retenção do log de busca. `0` desliga a limpeza. |

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

Chave de embeddings (`KNOWLEDGE_EMBEDDING_KEY`) so e necessária nas estratégias
`vector` e `hybrid`. Com `lexical` a base funciona sem nenhuma credencial externa.

---

## 4. Implantação

1. `php84 artisan migrate` — cria sete tabelas e acrescenta colunas de fundamentação em `conversation_reply_suggestions`.
2. `php84 artisan db:seed --class=SystemSettingSeeder` — grava as chaves `knowledge.*` desligadas.
3. `php84 artisan db:seed --class=RolePermissionSeeder` — cria as permissões `knowledge.*`.
4. `php84 artisan cache:clear` — `SystemSettingService` cacheia para sempre.
5. Acrescentar `knowledge-indexing` as filas do worker systemd.
6. `php84 artisan knowledge:diagnose` — confere provedores, ferramentas externas e estado.

Filas do worker após esta etapa:

```
whatsapp-send,conversation-automation,conversation-automation-send,ai-interpretation,ai-response-generation,ai-response-send,knowledge-indexing
```

### Dependência externa pendente

`pdftotext` **não esta instalado** neste servidor. Enquanto isso, upload de PDF
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

- **processing** — enfileirado para extração, sanitização, segmentação e indexação.
- **ready** — indexado com sucesso. **Ainda invisível para a busca.**
- **approved** — única situação recuperável, e somente dentro de base `active`.
- **rejected** — recusado na revisão.
- **obsolete** — sai da busca sem apagar histórico. Aplicado automaticamente ao documento substituído quando a nova versão e aprovada.
- **failed** — extração ou indexação falhou. O motivo fica em `error_message` como código, nunca como mensagem do provedor.

Reprocessar **revoga a aprovação**: conteúdo novo exige leitura humana nova.

Excluir remove arquivo e trechos, mas nunca toca em `knowledge_retrievals`,
`knowledge_retrieval_chunks` ou `reply_suggestion_citations`.

---

## 6. Tipos de conteúdo permitidos

Somente estes oito, aplicados por enum no banco e por validação na tela:

biografia, histórico público, competência institucional, proposta aprovada,
posição oficial, agenda pública, perguntas frequentes, canais de contato.

Não envie: conversa de cidadão, opinião coletada na pesquisa, dado pessoal,
documento sigiloso, material de terceiro sem autorização, conteúdo de campanha
eleitoral, promessa ou compromisso não formalizado.

---

## 7. Segurança

### Arquivo

- Disco privado, fora de `public/`.
- O nome em disco e um UUID gerado no servidor. O nome enviado **nunca** participa da montagem do caminho: não existe superfície para path traversal.
- O nome original sobrevive apenas como rótulo higienizado.
- MIME conferido pelo conteúdo real (`getMimeType()`), não pelo cabeçalho do upload.
- Antivirus roda após a gravação; arquivo suspeito ou não verificável e apagado do disco.
- Download exige `knowledge.download_documents`, usa o caminho gravado no banco e e auditado.

### Injeção de prompt

Duas defesas, porque nenhuma basta sozinha:

1. **Na ingestão** — cada linha e comparada, já normalizada, contra `knowledge.injection_patterns`. A linha que casa e substituída por um marcador e o documento e sinalizado (`injection_flagged`), com os achados visíveis na tela antes da aprovação.
2. **No prompt** — os trechos vao em um bloco delimitado, declarado como material de referência, com a instrução explícita de que ordens dentro dele devem ser ignoradas. O prompt de sistema prevalece sempre.

A primeira erra por padrão incompleto; a segunda erra se o modelo ignorar a
delimitação. Juntas cobrem o buraco uma da outra.

Nenhum documento pode alterar ferramenta, credencial ou política: o conteúdo
recuperado so alcança o prompt como texto.

### Dados pessoais

- `RetrievalQuery` não carrega identificador de contato. Um teste le o arquivo para garantir que continue assim.
- `LocalKnowledgeRetriever` consulta apenas `knowledge_*`. Um teste le o código (sem comentários) e falha se `Conversation`, `Contact` ou `ConversationInsight` aparecerem.
- A consulta gravada no log e truncada em 1000 caracteres.
- Logs registram código de erro, contagem e duração — nunca credencial, caminho de arquivo ou corpo de requisição.

---

## 8. Fundamentação

O campo `grounded` devolvido pelo modelo e **sinal**, nunca autorização. A
validação acontece depois, e e determinística.

| Veredito | Significado | Envio |
| --- | --- | --- |
| `not_required` | O texto não afirma nada factual. | permitido |
| `grounded` | Afirmação factual sustentada pelos trechos citados. | permitido |
| `no_evidence` | Afirmação factual sem nenhuma citação valida. | bloqueado |
| `invalid_citation` | Citou algo fora do conjunto recuperado. | bloqueado |
| `obsolete_citation` | Citou documento que deixou de ser recuperável. | bloqueado |
| `unsupported_number` | Número que não esta no trecho citado. | bloqueado |
| `unsupported_date` | Data que não esta no trecho citado. | bloqueado |
| `unsupported_commitment` | Compromisso sem suporte explícito. | bloqueado |
| `grounded_without_citation` | Declarou-se fundamentado sem citar nada. | bloqueado |

Reprovação nunca produz texto alternativo: produz bloqueio e handoff
(`insufficient_evidence` para `no_evidence`, `ungrounded_answer` para os demais).

Data e conferida antes de número de propósito: uma data e feita de números, e a
ordem inversa reportaria motivo enganoso.

---

## 9. Rastreabilidade

Cada busca grava `knowledge_retrievals` e uma linha por trecho devolvido em
`knowledge_retrieval_chunks`, com **snapshot** de conteúdo, título e versão.
Cada sugestão fundamentada grava `reply_suggestion_citations`, com o mesmo
snapshot.

E duplicação de texto deliberada. Sem ela, substituir ou excluir um documento
apagaria a explicação de toda resposta que ele sustentou. Citação recusada
também vira linha, com o motivo e sem vínculo de documento — o identificador que
o modelo inventou não aponta para nada.

Retenção: `knowledge:prune-retrievals` limpa o log de busca conforme
`knowledge.retrieval_retention_days`. **Não** toca nas citações: a justificativa
de algo que chegou a uma pessoa tem ciclo de vida mais longo.

---

## 10. Comandos

| Comando | Uso |
| --- | --- |
| `knowledge:diagnose` | Configuração, provedores, ferramentas externas e estado por base. Não chama provedor externo. |
| `knowledge:index` | Enfileira reindexação. `--base=`, `--document=`, `--failed`, `--dry-run`. Revoga a aprovação. |
| `knowledge:sync` | Reconcilia contagem de trechos, remove trecho órfão e relata arquivo ausente ou documento parado em processamento. `--base=`, `--dry-run`. Nunca aprova, nunca reindexa, nunca exclui documento. |
| `knowledge:prune-retrievals` | Retenção do log de busca. `--days=`, `--dry-run`. |

---

## 11. Operação no dia a dia

**Publicar conteúdo novo**

1. Criar ou escolher a base. Base nova nasce em `draft`.
2. Enviar o documento. Conferir o texto extraido e os trechos na tela do documento.
3. Se houver aviso de instrução detectada, revisar o material antes de qualquer coisa.
4. Testar em *Teste de busca na base* com perguntas reais.
5. Aprovar o documento.
6. Ativar a base e associa-la ao fluxo.
7. Ligar `knowledge.enabled`.

**Editar a ficha de uma base**

O botão **Editar** aparece em cada linha da listagem em *Inteligência > Base de
conhecimento* e também dentro da base, ao lado de *Alterar situação*. Exige
`knowledge.manage_bases`, então `operador` e `consulta` não veem o botão nem
alcançam a rota.

São editáveis o nome, a descrição, a finalidade, a política de uso e os fluxos
que podem consultar a base.

Ficam de fora `slug`, `provider` e `version`, que registram como a base foi
criada, e a **situação**, que tem ação própria. Salvar o formulário nunca ativa
nem desativa uma base: ativar muda o que a IA pode afirmar e não pode ser efeito
colateral de salvar um formulário.

Uma ressalva sobre os fluxos: a base e opt-in por fluxo, então mexer nessa lista
tem efeito imediato se a base estiver `active` e `knowledge.enabled` ligado.
Tirar um fluxo corta a recuperação dele na hora; acrescentar um passa a
fundamentar respostas daquele fluxo com o conteúdo já aprovado da base.

Toda edição entra em auditoria como `knowledge_base.updated`, com o antes e o
depois dos campos alterados.

**Substituir uma versão**

Enviar o novo documento apontando `supersedes_document_id` para o antigo. Ao
aprovar o novo, o antigo vira `obsolete` sozinho.

**Tirar algo do ar rapidamente**

Marcar o documento como obsoleto, ou desativar a base. Os dois interrompem a
recuperação imediatamente e não apagam histórico.

---

## 12. Diagnostico de problemas

| Sintoma | Verificar |
| --- | --- |
| Busca sempre vazia | `knowledge.enabled`; base `active`; base associada ao fluxo; documento `approved`; `knowledge.score_threshold` alto demais. |
| `degraded_reason = sem_base_associada` | O fluxo não tem base ativa associada. |
| `degraded_reason = consulta_sem_termos` | A mensagem so tem stop words ou termos curtos. |
| `degraded_reason = limite_de_candidatos_excedido` | Corpus acima de `knowledge.max_vector_candidates`. Ver ADR 0001 antes de simplesmente subir o teto. |
| `degraded_reason = embeddings_nao_configurados` | Estratégia vetorial sem provedor configurado. |
| Documento em `failed` com `extrator_pdf_indisponivel` | `pdftotext` ausente. |
| Upload recusado com `antivirus_indisponivel` | ClamAV parado. |
| Muitas respostas em `no_evidence` | A base não cobre as perguntas que chegam, ou `top_k`/threshold estão restritivos demais. |
| Muitas respostas em `unsupported_number` | O modelo esta arredondando ou combinando trechos. Revisar o prompt antes de afrouxar a validação. |

---

## 13. Documentos relacionados

- `docs/adr/0001-armazenamento-vetorial-e-provedor-de-conhecimento.md` — escolha de armazenamento, limites medidos e procedimento de troca de provedor.
- `docs/tests/knowledge-base-manual-etapa-9d.md` — roteiro de homologação manual.
- `openspec/changes/etapa-9d-base-conhecimento-rag/` — proposta, design e specs.
