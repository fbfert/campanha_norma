## Why

A 9C gera perguntas de aprofundamento, mas não tem como responder nada factual. Quando alguém pergunta "quais são as propostas dela para a saúde?", o único destino possível e encaminhar para a equipe ou repetir um texto institucional fixo. O prompt declara explicitamente que o modelo não possui informação factual, e isso e correto: sem base aprovada, qualquer resposta seria invenção.

A subetapa 9D adiciona uma base de conhecimento oficial e aprovada, com recuperação de trechos e validação de fundamentação. O objetivo e estreito: **permitir resposta factual apenas quando existir evidência aprovada que a sustente**. O RAG e ferramenta de fundamentação, não mecanismo de controle: não decide estado de fluxo, não substitui o banco de opiniões da 9B e não pode usar conversa de terceiros como fonte.

A premissa invertida da 9C continua valendo e fica mais forte: sem evidência suficiente, o sistema encaminha. A ausência de resposta e sempre preferível a uma resposta plausível e errada.

## What Changes

- Adicionar abstrações independentes de fornecedor: `KnowledgeBaseProvider`, `EmbeddingProvider`, `KnowledgeRetriever` e `AnswerGroundingValidator`, com provedor inerte para ambiente sem credencial.
- Adicionar administração de bases de conhecimento com nome, descrição, finalidade, status, versão, provedor, identificador externo, política de uso, aprovador e datas.
- Adicionar administração de documentos com título, tipo, fonte, data, arquivo privado, hash, status em sete estados, versão, metadados, autor, aprovador, identificador de arquivo no provedor e erro sanitizado.
- Adicionar ingestão com upload privado, validação de MIME e tamanho, hash e deduplicação, verificação antivirus, extração de texto, chunking configurável, metadados de página e seção, indexação em fila separada, reprocessamento, exclusão sincronizada e aprovação humana obrigatória antes da disponibilidade.
- Adicionar recuperação restrita as bases associadas ao fluxo, filtrada por status aprovado e versão vigente, com `top_k` e threshold configuráveis, limite de contexto, deduplicação e log dos trechos recuperados.
- Adicionar geração fundamentada: prompt e schema versionados próprios, com `grounded` e `citations` na saída estruturada.
- Adicionar validação de fundamentação determinística: resposta factual exige evidência, identificadores citados devem pertencer ao conjunto recuperado, e termos numericos, datas e compromissos precisam de suporte explícito nos trechos citados.
- Adicionar defesa contra injeção de prompt em documentos: conteúdo recuperado e tratado como dado delimitado, instruções embutidas são neutralizadas na ingestão e a detecção e registrada.
- Adicionar versionamento com marcação de obsolescência, preservação de rastreabilidade de respostas antigas por snapshot e relatório de documentos sem indexação ou inconsistentes.
- Adicionar telas de bases, documentos, aprovação, reprocessamento, previa de trechos, teste de busca, teste de resposta sem envio, visualização das fontes usadas em uma sugestão e associação base-fluxo.
- Adicionar permissões próprias, auditoria, comandos de sincronização, diagnostico e limpeza, métricas de ingestão e health do provedor.
- Adicionar ADR documentando a escolha de provedor, os limites medidos e o procedimento de troca.

## Impact

- Affected specs: `knowledge-base-rag` (nova), `ai-response-generation`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, contratos, serviços de ingestão e recuperação, validador de fundamentação, integração com a geração da 9C, jobs, comandos, controllers, rotas, views, seeders, testes e documentação Laravel.
- Não afetado: serviço Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente; a 9A continua determinística; a 9B continua sem gerar texto; o banco de opiniões da população nunca e usado como fonte de resposta.
- Compatibilidade: com `knowledge.enabled` desligado o comportamento da 9A, 9B e 9C e identico ao anterior. A 9C mantem seu prompt e schema `v1`; a geração fundamentada usa versões próprias, selecionadas apenas quando a base esta ativa para o fluxo.
- Segurança e LGPD: arquivos fora do diretório público, download somente autorizado, nomes normalizados, proteção contra path traversal, verificação antivirus, minimização de dados nos trechos recuperados, ausência de dado pessoal privado na base e proibição de microdirecionamento persuasivo.
- Constraints desta subetapa: sem relatórios finais da 9E, sem PostgreSQL, sem conversa aberta fora do fluxo e sem resposta factual sem evidência aprovada.
