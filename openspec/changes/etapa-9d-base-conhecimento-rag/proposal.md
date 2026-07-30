## Why

A 9C gera perguntas de aprofundamento, mas nao tem como responder nada factual. Quando alguem pergunta "quais sao as propostas dela para a saude?", o unico destino possivel e encaminhar para a equipe ou repetir um texto institucional fixo. O prompt declara explicitamente que o modelo nao possui informacao factual, e isso e correto: sem base aprovada, qualquer resposta seria invencao.

A subetapa 9D adiciona uma base de conhecimento oficial e aprovada, com recuperacao de trechos e validacao de fundamentacao. O objetivo e estreito: **permitir resposta factual apenas quando existir evidencia aprovada que a sustente**. O RAG e ferramenta de fundamentacao, nao mecanismo de controle: nao decide estado de fluxo, nao substitui o banco de opinioes da 9B e nao pode usar conversa de terceiros como fonte.

A premissa invertida da 9C continua valendo e fica mais forte: sem evidencia suficiente, o sistema encaminha. A ausencia de resposta e sempre preferivel a uma resposta plausivel e errada.

## What Changes

- Adicionar abstracoes independentes de fornecedor: `KnowledgeBaseProvider`, `EmbeddingProvider`, `KnowledgeRetriever` e `AnswerGroundingValidator`, com provedor inerte para ambiente sem credencial.
- Adicionar administracao de bases de conhecimento com nome, descricao, finalidade, status, versao, provedor, identificador externo, politica de uso, aprovador e datas.
- Adicionar administracao de documentos com titulo, tipo, fonte, data, arquivo privado, hash, status em sete estados, versao, metadados, autor, aprovador, identificador de arquivo no provedor e erro sanitizado.
- Adicionar ingestao com upload privado, validacao de MIME e tamanho, hash e deduplicacao, verificacao antivirus, extracao de texto, chunking configuravel, metadados de pagina e secao, indexacao em fila separada, reprocessamento, exclusao sincronizada e aprovacao humana obrigatoria antes da disponibilidade.
- Adicionar recuperacao restrita as bases associadas ao fluxo, filtrada por status aprovado e versao vigente, com `top_k` e threshold configuraveis, limite de contexto, deduplicacao e log dos trechos recuperados.
- Adicionar geracao fundamentada: prompt e schema versionados proprios, com `grounded` e `citations` na saida estruturada.
- Adicionar validacao de fundamentacao deterministica: resposta factual exige evidencia, identificadores citados devem pertencer ao conjunto recuperado, e termos numericos, datas e compromissos precisam de suporte explicito nos trechos citados.
- Adicionar defesa contra injecao de prompt em documentos: conteudo recuperado e tratado como dado delimitado, instrucoes embutidas sao neutralizadas na ingestao e a deteccao e registrada.
- Adicionar versionamento com marcacao de obsolescencia, preservacao de rastreabilidade de respostas antigas por snapshot e relatorio de documentos sem indexacao ou inconsistentes.
- Adicionar telas de bases, documentos, aprovacao, reprocessamento, previa de trechos, teste de busca, teste de resposta sem envio, visualizacao das fontes usadas em uma sugestao e associacao base-fluxo.
- Adicionar permissoes proprias, auditoria, comandos de sincronizacao, diagnostico e limpeza, metricas de ingestao e health do provedor.
- Adicionar ADR documentando a escolha de provedor, os limites medidos e o procedimento de troca.

## Impact

- Affected specs: `knowledge-base-rag` (nova), `ai-response-generation`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, contratos, servicos de ingestao e recuperacao, validador de fundamentacao, integracao com a geracao da 9C, jobs, comandos, controllers, rotas, views, seeders, testes e documentacao Laravel.
- Nao afetado: servico Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente; a 9A continua deterministica; a 9B continua sem gerar texto; o banco de opinioes da populacao nunca e usado como fonte de resposta.
- Compatibilidade: com `knowledge.enabled` desligado o comportamento da 9A, 9B e 9C e identico ao anterior. A 9C mantem seu prompt e schema `v1`; a geracao fundamentada usa versoes proprias, selecionadas apenas quando a base esta ativa para o fluxo.
- Seguranca e LGPD: arquivos fora do diretorio publico, download somente autorizado, nomes normalizados, protecao contra path traversal, verificacao antivirus, minimizacao de dados nos trechos recuperados, ausencia de dado pessoal privado na base e proibicao de microdirecionamento persuasivo.
- Constraints desta subetapa: sem relatorios finais da 9E, sem PostgreSQL, sem conversa aberta fora do fluxo e sem resposta factual sem evidencia aprovada.
