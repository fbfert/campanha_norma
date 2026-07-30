# Design — Subetapa 9D

## Contexto

A 9A decide, a 9B interpreta, a 9C propoe. A 9D **fundamenta**. E a primeira subetapa em que o sistema pode afirmar algo factual, e por isso o desenho parte de uma pergunta unica: como garantir que nada seja afirmado sem evidencia aprovada atras?

A resposta nao esta no prompt. Esta em tres barreiras independentes: somente conteudo aprovado entra na busca, somente trechos recuperados podem ser citados, e um validador deterministico confere depois do modelo se cada afirmacao factual tem suporte. As tres precisam falhar juntas para que uma invencao chegue a alguem.

## Decisoes

### 1. Vetores em MySQL, com justificativa e limite medido

O escopo proibe introduzir PostgreSQL sem ADR e autorizacao, e exige justificar tecnicamente vetores em coluna MySQL. Escolhemos MySQL e justificamos.

A base oficial desta aplicacao e pequena e cresce devagar: biografia aprovada, competencias institucionais, propostas aprovadas, posicoes publicadas, agenda autorizada, perguntas frequentes e canais de contato. Isso e da ordem de dezenas de documentos e alguns milhares de trechos, nao milhoes. Nessa faixa, similaridade por cosseno calculada em PHP sobre um conjunto candidato limitado por base e status e mais barata operacionalmente do que introduzir um segundo banco de dados, e a latencia fica dominada pela chamada de embedding, nao pela aritmetica.

O embedding fica em `knowledge_chunks.embedding`, `longblob`, serializado como sequencia de floats de 32 bits — nao JSON, nao texto. A dimensao e persistida junto, entao trocar de modelo nao corrompe leitura: vetores de dimensao diferente da configurada sao ignorados na busca e sinalizados pelo diagnostico.

Existe um teto explicito e configuravel (`knowledge.max_vector_candidates`). Acima dele a busca vetorial nao degrada em silencio: ela recusa, registra o motivo e cai para a estrategia lexica. O teste `KnowledgeVectorLimitsTest` mede o comportamento real com um corpus sintetico e falha se o custo por consulta sair da faixa documentada.

O ADR registra o gatilho de migracao: quando o corpus passar do teto documentado, a troca e implementar `KnowledgeBaseProvider` para um armazenamento externo. Nenhum chamador muda, porque nenhum chamador conhece o armazenamento.

### 2. Tres estrategias de recuperacao, e a lexica e a padrao

`knowledge.retrieval_strategy` aceita `lexical`, `vector` e `hybrid`. O padrao e `lexical`.

O motivo e operacional e honesto: a estrategia vetorial depende de um provedor de embeddings com credencial. Se o padrao fosse vetorial, a base ficaria inerte em qualquer ambiente sem chave — inclusive em homologacao — e a unica forma de testar seria pagar por chamadas externas. Com a lexica como padrao, a base funciona, e testavel e e homologavel desde o primeiro dia, sem nenhuma chamada externa. A vetorial e um ganho de qualidade que se liga depois, e a hibrida combina as duas por fusao de posicao.

A estrategia lexica nao e um placeholder: normaliza acentuacao e caixa, remove palavras vazias configuraveis, pontua por cobertura de termos, frequencia e proximidade, e aplica o mesmo threshold e o mesmo `top_k` da vetorial. Toda a camada acima dela e identica.

### 3. Aprovacao humana e a condicao de existencia na busca

Um documento so participa da recuperacao em `approved`. Nenhum outro estado e recuperavel — nem `ready`, que significa apenas "indexado com sucesso e aguardando alguem ler".

A separacao entre `ready` e `approved` e deliberada. Indexar e uma operacao tecnica que a fila faz sozinha. Aprovar e uma afirmacao de que aquele conteudo pode ser dito a uma pessoa em nome de alguem, e isso exige um humano com permissao propria. O filtro fica no escopo do model (`KnowledgeDocument::scopeRetrievable`) e e reafirmado no retriever, porque uma regra dessa natureza nao deve depender de o chamador lembrar.

### 4. Rastreabilidade sobrevive a exclusao do trecho

O log de recuperacao guarda **snapshot** do conteudo do trecho, do titulo e da versao do documento, alem da chave estrangeira. A chave permite navegar; o snapshot permite auditar.

Sem o snapshot, "documento obsoleto deixa de ser recuperado sem apagar historico" seria impossivel de cumprir: bastaria alguem excluir um documento para que toda sugestao antiga perdesse o rastro do que a sustentou. Com o snapshot, a resposta de tres meses atras continua explicavel mesmo que a base tenha sido inteiramente substituida.

O custo e duplicacao de texto. E o custo certo: espaco em disco e barato, auditoria perdida e irrecuperavel.

### 5. Conteudo recuperado e dado, nunca instrucao

Duas defesas, em momentos diferentes.

Na **ingestao**, `PromptInjectionSanitizer` varre o texto extraido contra padroes configuraveis de instrucao ("ignore as instrucoes anteriores", "voce agora e", "system:", marcadores de papel, tentativas de redefinir ferramentas). Linhas correspondentes sao neutralizadas antes do chunking, o documento e marcado com `injection_flagged` e a deteccao vai para `injection_findings` e para o log. Um documento sinalizado ainda pode ser aprovado, mas a tela exige que o revisor veja o achado.

Na **recuperacao**, os trechos entram no prompt dentro de um bloco delimitado, precedido de uma declaracao explicita de que aquele conteudo e material de referencia e que instrucoes ali dentro nao devem ser obedecidas. O prompt de sistema vem antes e prevalece.

Nenhuma das duas e suficiente sozinha. A primeira erra por padrao incompleto, a segunda erra por o modelo ignorar a delimitacao. Juntas, exigem duas falhas simultaneas.

Documento nunca altera ferramenta, segredo ou politica: nao existe caminho de codigo em que conteudo de documento seja interpretado como configuracao.

### 6. O validador de fundamentacao roda depois do modelo, sempre

`GroundingValidator` recebe o texto gerado, as citacoes declaradas e o conjunto efetivamente recuperado. Reprova por:

```text
sem_evidencia              afirmacao factual sem nenhuma citacao
citacao_invalida           id citado fora do conjunto recuperado
citacao_de_obsoleto        id citado aponta para documento nao recuperavel
numero_sem_suporte         numero no texto ausente dos trechos citados
data_sem_suporte           data no texto ausente dos trechos citados
compromisso_sem_suporte    promessa ou compromisso sem suporte explicito
grounded_sem_citacao       modelo declarou fundamentado e nao citou nada
```

O criterio de "afirmacao factual" e deterministico e configuravel: presenca de numero, data, valor monetario ou expressao de compromisso, mais listas em `system_settings`. Reprovacao nao produz texto alternativo: produz handoff.

Como na 9C, o campo que o modelo devolve e sinal, nunca autorizacao. `grounded: true` sem citacao valida e exatamente o caso que o validador existe para pegar.

### 7. Obsolescencia nao apaga

Uma nova versao de documento nao sobrescreve a anterior: cria uma linha nova com `supersedes_document_id`, e a anterior vai para `obsolete`. Documento obsoleto sai da busca imediatamente e continua legivel na administracao e nas citacoes antigas.

Exclusao definitiva existe, exige permissao propria, sincroniza a remocao no provedor e apaga o arquivo privado — mas nao apaga o log de recuperacao nem as citacoes, por causa da decisao 4.

### 8. Prompt e schema fundamentados sao versoes proprias, selecionadas por condicao explicita

A 9C usa `ai.response.prompt_version = v1` e `ai.response.schema_version = 1`, sem campo de citacao. A geracao fundamentada usa `ai.response.grounded_prompt_version = v2` e `ai.response.grounded_schema_version = 2`, com `grounded` e `citations`.

A selecao e uma condicao unica e legivel: existe base ativa e aprovada associada ao fluxo, e `knowledge.enabled` esta ligado. Nao ha magia implicita e nao ha dupla configuracao para ligar o recurso — as duas versoes ficam configuradas de forma independente e o `knowledge:diagnose` reclama se alguem apontar a versao fundamentada para um schema sem citacoes.

Alternativa descartada: promover o schema da 9C para 2 e adicionar campos opcionais. Quebraria a promessa de versionamento e faria todo run da 9C carregar um contrato que ela nao usa.

### 9. Falha do RAG nao interrompe nada

A recuperacao acontece no job de geracao, na fila `ai-response-generation`, depois de a mensagem estar persistida. Provedor de embedding fora do ar, base vazia, indexacao pendente: em todos os casos a recuperacao devolve conjunto vazio, o comportamento cai para o da 9C (handoff ou texto institucional) e o registro de mensagens recebidas nao e afetado.

A indexacao vive em fila propria, `knowledge-indexing`. Um PDF grande nunca atrasa geracao, interpretacao ou recebimento.

### 10. Extracao de texto: o que o ambiente realmente faz

`DocumentTextExtractor` despacha por MIME:

```text
text/plain          nativo
text/markdown       nativo
text/html           DOMDocument, com remocao de script e style
docx                ZipArchive sobre word/document.xml
pdf                 binario externo configuravel
```

TXT, Markdown, HTML e DOCX funcionam sem nenhuma dependencia nova: `ZipArchive` e `DOMDocument` estao presentes. PDF textual exige `pdftotext`, que **nao existe** neste ambiente. O comando fica em `knowledge.pdf_text_command`, configuravel e sem caminho fixo.

Decisao explicita: quando o extrator de PDF nao esta disponivel, o documento vai para `failed` com erro sanitizado `extrator_pdf_indisponivel`. Nao existe fallback nativo que tente decodificar streams do PDF por conta propria. Um extrator improvisado produz texto parcialmente correto, e texto parcialmente correto dentro de uma base de conhecimento oficial e pior do que uma falha limpa: a falha alguem conserta, o texto corrompido alguem cita.

### 11. Antivirus esta disponivel e e usado

O escopo pede antivirus "se ja houver infraestrutura". Ha: ClamAV 1.4.3 com base de assinaturas atualizada esta instalado no host. `AntivirusScanner` executa o comando configurado em `knowledge.antivirus_command`, com timeout proprio.

O comportamento quando o scanner esta ausente e configuravel em `knowledge.antivirus_required`. O padrao e exigir: sem scanner, o upload e recusado. Um padrao permissivo transformaria a indisponibilidade do antivirus em ausencia silenciosa de verificacao.

### 12. Opiniao da populacao nunca e fonte de resposta

Os insights da 9B e a base oficial da 9D sao dois universos que nunca se cruzam na direcao da resposta. O insight da propria conversa entra no contexto como historico daquela pessoa, exatamente como na 9C. Nenhum insight de terceiro, nenhuma agregacao de opinioes e nenhuma mensagem de outra conversa alcanca o prompt.

Estruturalmente: `KnowledgeRetriever` consulta `knowledge_chunks` e nada mais. Nao tem acesso ao model `ConversationInsight`, ao model `Contact` nem a nenhuma tabela de conversa. Um teste le o codigo-fonte e falha se essa fronteira for cruzada.

### 13. Duas separacoes visiveis no prompt

O contexto oficial e o contexto da conversa entram em blocos distintos e rotulados. O prompt instrui que afirmacao factual sai exclusivamente do bloco oficial e que a opiniao da pessoa nunca deve ser tratada como fato do mundo.

Isso importa para alem da qualidade: e o que impede o modelo de citar como posicao oficial algo que a propria pessoa acabou de dizer.

### 14. Citacoes sao internas por padrao

No WhatsApp a resposta sai sem lista de referencias — uma mensagem com rodape de citacoes parece burocratica e nao ajuda quem le. Na administracao as fontes sao obrigatoriamente visiveis: a tela da sugestao mostra cada trecho usado, o documento, a versao, a pagina e a secao.

A configuracao `knowledge.show_citations_to_contact` existe e nasce desligada, para o caso de a decisao de UX mudar.

## Alternativas descartadas

- **PostgreSQL com pgvector.** Melhor tecnicamente em escala, mas exigiria um segundo banco em producao, backup proprio, plano operacional e autorizacao explicita — tudo isso para um corpus de dezenas de documentos. O ADR registra o gatilho que justificaria a mudanca.
- **Vetores em coluna JSON.** Legivel e trinta vezes maior. `longblob` com floats de 32 bits e a mesma informacao em espaco previsivel.
- **Estrategia vetorial como padrao.** Deixaria o recurso inerte em qualquer ambiente sem credencial de embedding, inclusive homologacao.
- **Extrator de PDF nativo improvisado.** Descartado pela decisao 10.
- **Deixar o modelo decidir se esta fundamentado.** O campo `grounded` e sinal. Quem decide e o validador deterministico, comparando contra o conjunto recuperado.
- **Reaproveitar a fila da 9C para indexacao.** Acoplaria a latencia de um PDF de duzentas paginas a geracao de uma sugestao.
- **Recuperar sobre a base de opinioes.** Proibido pelo escopo e impedido estruturalmente pela decisao 12.

## Riscos

- **Documento aprovado com conteudo errado.** O sistema garante procedencia, nao veracidade. Mitigado por aprovacao humana com permissao propria, exibicao do texto extraido antes de aprovar e versionamento com obsolescencia.
- **Injecao de prompt em documento aprovado.** Mitigado pelas duas defesas da decisao 5, e por o revisor ver o achado antes de aprovar.
- **Fundamentacao aparente.** Um trecho citado corretamente pode sustentar mal a frase gerada. Mitigado pela verificacao de numeros, datas e compromissos contra o conteudo citado, e pelo handoff em caso de duvida.
- **Base desatualizada respondendo como atual.** Mitigado por `document_date`, versao vigente, obsolescencia e relatorio de documentos sem indexacao ou inconsistentes.
- **Extracao silenciosamente ruim.** Mitigado por previa obrigatoria do texto extraido na tela, contagem de trechos e falha limpa quando o extrator nao existe.
- **Crescimento do corpus alem do teto.** Mitigado por limite configuravel, recusa explicita com queda para a estrategia lexica, e diagnostico que avisa antes de doer.
