# Design — Subetapa 9D

## Contexto

A 9A decide, a 9B interpreta, a 9C propoe. A 9D **fundamenta**. E a primeira subetapa em que o sistema pode afirmar algo factual, e por isso o desenho parte de uma pergunta única: como garantir que nada seja afirmado sem evidência aprovada atrás?

A resposta não esta no prompt. Esta em três barreiras independentes: somente conteúdo aprovado entra na busca, somente trechos recuperados podem ser citados, e um validador determinístico confere depois do modelo se cada afirmação factual tem suporte. As três precisam falhar juntas para que uma invenção chegue a alguém.

## Decisões

### 1. Vetores em MySQL, com justificativa e limite medido

O escopo proibe introduzir PostgreSQL sem ADR e autorização, e exige justificar tecnicamente vetores em coluna MySQL. Escolhemos MySQL e justificamos.

A base oficial desta aplicação e pequena e cresce devagar: biografia aprovada, competências institucionais, propostas aprovadas, posições publicadas, agenda autorizada, perguntas frequentes e canais de contato. Isso e da ordem de dezenas de documentos e alguns milhares de trechos, não milhões. Nessa faixa, similaridade por cosseno calculada em PHP sobre um conjunto candidato limitado por base e status e mais barata operacionalmente do que introduzir um segundo banco de dados, e a latência fica dominada pela chamada de embedding, não pela aritmética.

O embedding fica em `knowledge_chunks.embedding`, `longblob`, serializado como sequência de floats de 32 bits — não JSON, não texto. A dimensão e persistida junto, então trocar de modelo não corrompe leitura: vetores de dimensão diferente da configurada são ignorados na busca e sinalizados pelo diagnostico.

Existe um teto explícito e configurável (`knowledge.max_vector_candidates`). Acima dele a busca vetorial não degrada em silêncio: ela recusa, registra o motivo e cai para a estratégia léxica. O teste `KnowledgeVectorLimitsTest` mede o comportamento real com um corpus sintético e falha se o custo por consulta sair da faixa documentada.

O ADR registra o gatilho de migração: quando o corpus passar do teto documentado, a troca e implementar `KnowledgeBaseProvider` para um armazenamento externo. Nenhum chamador muda, porque nenhum chamador conhece o armazenamento.

### 2. Três estratégias de recuperação, e a léxica e a padrão

`knowledge.retrieval_strategy` aceita `lexical`, `vector` e `hybrid`. O padrão e `lexical`.

O motivo e operacional e honesto: a estratégia vetorial depende de um provedor de embeddings com credencial. Se o padrão fosse vetorial, a base ficaria inerte em qualquer ambiente sem chave — inclusive em homologação — e a única forma de testar seria pagar por chamadas externas. Com a léxica como padrão, a base funciona, e testável e e homologável desde o primeiro dia, sem nenhuma chamada externa. A vetorial e um ganho de qualidade que se liga depois, e a híbrida combina as duas por fusão de posição.

A estratégia léxica não e um placeholder: normaliza acentuação e caixa, remove palavras vazias configuráveis, pontua por cobertura de termos, frequência e proximidade, e aplica o mesmo threshold e o mesmo `top_k` da vetorial. Toda a camada acima dela e identica.

### 3. Aprovação humana e a condição de existência na busca

Um documento so participa da recuperação em `approved`. Nenhum outro estado e recuperável — nem `ready`, que significa apenas "indexado com sucesso e aguardando alguém ler".

A separação entre `ready` e `approved` e deliberada. Indexar e uma operação técnica que a fila faz sozinha. Aprovar e uma afirmação de que aquele conteúdo pode ser dito a uma pessoa em nome de alguém, e isso exige um humano com permissão própria. O filtro fica no escopo do model (`KnowledgeDocument::scopeRetrievable`) e e reafirmado no retriever, porque uma regra dessa natureza não deve depender de o chamador lembrar.

### 4. Rastreabilidade sobrevive a exclusão do trecho

O log de recuperação guarda **snapshot** do conteúdo do trecho, do título e da versão do documento, além da chave estrangeira. A chave permite navegar; o snapshot permite auditar.

Sem o snapshot, "documento obsoleto deixa de ser recuperado sem apagar histórico" seria impossível de cumprir: bastaria alguém excluir um documento para que toda sugestão antiga perdesse o rastro do que a sustentou. Com o snapshot, a resposta de três meses atrás continua explicável mesmo que a base tenha sido inteiramente substituída.

O custo e duplicação de texto. E o custo certo: espaço em disco e barato, auditoria perdida e irrecuperável.

### 5. Conteúdo recuperado e dado, nunca instrução

Duas defesas, em momentos diferentes.

Na **ingestão**, `PromptInjectionSanitizer` varre o texto extraido contra padrões configuráveis de instrução ("ignore as instruções anteriores", "você agora e", "system:", marcadores de papel, tentativas de redefinir ferramentas). Linhas correspondentes são neutralizadas antes do chunking, o documento e marcado com `injection_flagged` e a detecção vai para `injection_findings` e para o log. Um documento sinalizado ainda pode ser aprovado, mas a tela exige que o revisor veja o achado.

Na **recuperação**, os trechos entram no prompt dentro de um bloco delimitado, precedido de uma declaração explícita de que aquele conteúdo e material de referência e que instruções ali dentro não devem ser obedecidas. O prompt de sistema vem antes e prevalece.

Nenhuma das duas e suficiente sozinha. A primeira erra por padrão incompleto, a segunda erra por o modelo ignorar a delimitação. Juntas, exigem duas falhas simultaneas.

Documento nunca altera ferramenta, segredo ou política: não existe caminho de código em que conteúdo de documento seja interpretado como configuração.

### 6. O validador de fundamentação roda depois do modelo, sempre

`GroundingValidator` recebe o texto gerado, as citações declaradas e o conjunto efetivamente recuperado. Reprova por:

```text
sem_evidencia              afirmacao factual sem nenhuma citacao
citacao_invalida           id citado fora do conjunto recuperado
citacao_de_obsoleto        id citado aponta para documento nao recuperavel
numero_sem_suporte         numero no texto ausente dos trechos citados
data_sem_suporte           data no texto ausente dos trechos citados
compromisso_sem_suporte    promessa ou compromisso sem suporte explicito
grounded_sem_citacao       modelo declarou fundamentado e nao citou nada
```

O critério de "afirmação factual" e determinístico e configurável: presença de número, data, valor monetário ou expressão de compromisso, mais listas em `system_settings`. Reprovação não produz texto alternativo: produz handoff.

Como na 9C, o campo que o modelo devolve e sinal, nunca autorização. `grounded: true` sem citação valida e exatamente o caso que o validador existe para pegar.

### 7. Obsolescência não apaga

Uma nova versão de documento não sobrescreve a anterior: cria uma linha nova com `supersedes_document_id`, e a anterior vai para `obsolete`. Documento obsoleto sai da busca imediatamente e continua legível na administração e nas citações antigas.

Exclusão definitiva existe, exige permissão própria, sincroniza a remoção no provedor e apaga o arquivo privado — mas não apaga o log de recuperação nem as citações, por causa da decisão 4.

### 8. Prompt e schema fundamentados são versões próprias, selecionadas por condição explícita

A 9C usa `ai.response.prompt_version = v1` e `ai.response.schema_version = 1`, sem campo de citação. A geração fundamentada usa `ai.response.grounded_prompt_version = v2` e `ai.response.grounded_schema_version = 2`, com `grounded` e `citations`.

A seleção e uma condição única e legível: existe base ativa e aprovada associada ao fluxo, e `knowledge.enabled` esta ligado. Não ha magia implícita e não ha dupla configuração para ligar o recurso — as duas versões ficam configuradas de forma independente e o `knowledge:diagnose` reclama se alguém apontar a versão fundamentada para um schema sem citações.

Alternativa descartada: promover o schema da 9C para 2 e adicionar campos opcionais. Quebraria a promessa de versionamento e faria todo run da 9C carregar um contrato que ela não usa.

### 9. Falha do RAG não interrompe nada

A recuperação acontece no job de geração, na fila `ai-response-generation`, depois de a mensagem estar persistida. Provedor de embedding fora do ar, base vazia, indexação pendente: em todos os casos a recuperação devolve conjunto vazio, o comportamento cai para o da 9C (handoff ou texto institucional) e o registro de mensagens recebidas não e afetado.

A indexação vive em fila própria, `knowledge-indexing`. Um PDF grande nunca atrasa geração, interpretação ou recebimento.

### 10. Extração de texto: o que o ambiente realmente faz

`DocumentTextExtractor` despacha por MIME:

```text
text/plain          nativo
text/markdown       nativo
text/html           DOMDocument, com remocao de script e style
docx                ZipArchive sobre word/document.xml
pdf                 binario externo configuravel
```

TXT, Markdown, HTML e DOCX funcionam sem nenhuma dependência nova: `ZipArchive` e `DOMDocument` estão presentes. PDF textual exige `pdftotext`, que **não existe** neste ambiente. O comando fica em `knowledge.pdf_text_command`, configurável e sem caminho fixo.

Decisão explícita: quando o extrator de PDF não esta disponível, o documento vai para `failed` com erro sanitizado `extrator_pdf_indisponivel`. Não existe fallback nativo que tente decodificar streams do PDF por conta própria. Um extrator improvisado produz texto parcialmente correto, e texto parcialmente correto dentro de uma base de conhecimento oficial e pior do que uma falha limpa: a falha alguém conserta, o texto corrompido alguém cita.

### 11. Antivirus esta disponível e e usado

O escopo pede antivirus "se já houver infraestrutura". Ha: ClamAV 1.4.3 com base de assinaturas atualizada esta instalado no host. `AntivirusScanner` executa o comando configurado em `knowledge.antivirus_command`, com timeout próprio.

O comportamento quando o scanner esta ausente e configurável em `knowledge.antivirus_required`. O padrão e exigir: sem scanner, o upload e recusado. Um padrão permissivo transformaria a indisponibilidade do antivirus em ausência silenciosa de verificação.

### 12. Opinião da população nunca e fonte de resposta

Os insights da 9B e a base oficial da 9D são dois universos que nunca se cruzam na direção da resposta. O insight da própria conversa entra no contexto como histórico daquela pessoa, exatamente como na 9C. Nenhum insight de terceiro, nenhuma agregação de opiniões e nenhuma mensagem de outra conversa alcança o prompt.

Estruturalmente: `KnowledgeRetriever` consulta `knowledge_chunks` e nada mais. Não tem acesso ao model `ConversationInsight`, ao model `Contact` nem a nenhuma tabela de conversa. Um teste le o código-fonte e falha se essa fronteira for cruzada.

### 13. Duas separações visíveis no prompt

O contexto oficial e o contexto da conversa entram em blocos distintos e rotulados. O prompt instrui que afirmação factual sai exclusivamente do bloco oficial e que a opinião da pessoa nunca deve ser tratada como fato do mundo.

Isso importa para além da qualidade: e o que impede o modelo de citar como posição oficial algo que a própria pessoa acabou de dizer.

### 14. Citações são internas por padrão

No WhatsApp a resposta sai sem lista de referências — uma mensagem com rodape de citações parece burocrática e não ajuda quem le. Na administração as fontes são obrigatoriamente visíveis: a tela da sugestão mostra cada trecho usado, o documento, a versão, a página e a seção.

A configuração `knowledge.show_citations_to_contact` existe e nasce desligada, para o caso de a decisão de UX mudar.

## Alternativas descartadas

- **PostgreSQL com pgvector.** Melhor tecnicamente em escala, mas exigiria um segundo banco em produção, backup próprio, plano operacional e autorização explícita — tudo isso para um corpus de dezenas de documentos. O ADR registra o gatilho que justificaria a mudança.
- **Vetores em coluna JSON.** Legível e trinta vezes maior. `longblob` com floats de 32 bits e a mesma informação em espaço previsível.
- **Estratégia vetorial como padrão.** Deixaria o recurso inerte em qualquer ambiente sem credencial de embedding, inclusive homologação.
- **Extrator de PDF nativo improvisado.** Descartado pela decisão 10.
- **Deixar o modelo decidir se esta fundamentado.** O campo `grounded` e sinal. Quem decide e o validador determinístico, comparando contra o conjunto recuperado.
- **Reaproveitar a fila da 9C para indexação.** Acoplaria a latência de um PDF de duzentas páginas a geração de uma sugestão.
- **Recuperar sobre a base de opiniões.** Proibido pelo escopo e impedido estruturalmente pela decisão 12.

## Riscos

- **Documento aprovado com conteúdo errado.** O sistema garante procedência, não veracidade. Mitigado por aprovação humana com permissão própria, exibição do texto extraido antes de aprovar e versionamento com obsolescência.
- **Injeção de prompt em documento aprovado.** Mitigado pelas duas defesas da decisão 5, e por o revisor ver o achado antes de aprovar.
- **Fundamentação aparente.** Um trecho citado corretamente pode sustentar mal a frase gerada. Mitigado pela verificação de números, datas e compromissos contra o conteúdo citado, e pelo handoff em caso de duvida.
- **Base desatualizada respondendo como atual.** Mitigado por `document_date`, versão vigente, obsolescência e relatório de documentos sem indexação ou inconsistentes.
- **Extração silenciosamente ruim.** Mitigado por previa obrigatória do texto extraido na tela, contagem de trechos e falha limpa quando o extrator não existe.
- **Crescimento do corpus além do teto.** Mitigado por limite configurável, recusa explícita com queda para a estratégia léxica, e diagnostico que avisa antes de doer.
