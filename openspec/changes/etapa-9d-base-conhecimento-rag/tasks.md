## 1. Especificacao

- [x] 1.1 Ler README, documentacao das subetapas 9A, 9B e 9C, filas, monitoramento e implantacao.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` e as mudancas 9A, 9B e 9C.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9D.
- [x] 1.4 Escrever ADR de escolha de provedor, limites medidos e procedimento de troca.
- [x] 1.5 Validar com OpenSpec.

## 2. Contratos e configuracao

- [x] 2.1 Criar contratos `KnowledgeBaseProvider`, `EmbeddingProvider`, `KnowledgeRetriever` e `AnswerGroundingValidator`.
- [x] 2.2 Criar objetos de dados de consulta, resultado, trecho recuperado e veredito de fundamentacao.
- [x] 2.3 Criar `config/knowledge.php` com provedor, credencial, modelo de embedding, limites de transporte e comandos externos.
- [x] 2.4 Criar provedor inerte e provedor local com armazenamento relacional.
- [x] 2.5 Criar provedor de embeddings compativel com API no formato OpenAI e implementacao inerte.
- [x] 2.6 Criar resolvedores de provedor por configuracao.

## 3. Dados, enums e models

- [x] 3.1 Criar migration de bases, documentos, trechos, associacao base-fluxo, log de recuperacao e citacoes.
- [x] 3.2 Adicionar colunas de fundamentacao em `conversation_reply_suggestions` em migration propria.
- [x] 3.3 Criar enums de status de base, status de documento, tipo de documento, estrategia de recuperacao e status de fundamentacao.
- [x] 3.4 Criar models, relacionamentos, escopos de recuperabilidade e factories.
- [x] 3.5 Acrescentar motivos de handoff de fundamentacao insuficiente e evidencia ausente.

## 4. Ingestao

- [x] 4.1 Criar servico de upload com disco privado, normalizacao de nome, validacao de MIME e tamanho.
- [x] 4.2 Criar hash de conteudo e deduplicacao por base.
- [x] 4.3 Criar verificacao antivirus configuravel com comportamento explicito na ausencia do scanner.
- [x] 4.4 Criar extratores de texto plano, Markdown, HTML e DOCX nativos.
- [x] 4.5 Criar extrator de PDF por binario configuravel, com falha limpa quando ausente.
- [x] 4.6 Criar sanitizador de injecao de prompt com padroes configuraveis e registro do achado.
- [x] 4.7 Criar chunker configuravel com metadados de pagina e secao.
- [x] 4.8 Criar servico de indexacao e job em fila propria.
- [x] 4.9 Criar reprocessamento e exclusao sincronizada com o provedor.
- [x] 4.10 Exigir aprovacao humana antes da disponibilidade para busca.

## 5. Recuperacao e fundamentacao

- [x] 5.1 Criar retriever com estrategias lexica, vetorial e hibrida.
- [x] 5.2 Aplicar filtro de base associada ao fluxo, status aprovado e versao vigente.
- [x] 5.3 Aplicar `top_k`, threshold, limite de contexto e deduplicacao.
- [x] 5.4 Registrar log de recuperacao com snapshot de conteudo dos trechos retornados.
- [x] 5.5 Aplicar limite de candidatos com recusa explicita e queda para a estrategia lexica.
- [x] 5.6 Criar validador de fundamentacao deterministico com os sete motivos de reprovacao.

## 6. Integracao com a geracao

- [x] 6.1 Criar prompt e schema versionados proprios com `grounded` e `citations`.
- [x] 6.2 Separar bloco oficial e bloco de conversa no montador de contexto, com delimitacao de dado.
- [x] 6.3 Selecionar versoes fundamentadas apenas quando houver base ativa associada ao fluxo.
- [x] 6.4 Persistir referencia de recuperacao, veredito e citacoes na sugestao.
- [x] 6.5 Bloquear envio e autoenvio de resposta com fundamentacao invalida.
- [x] 6.6 Encaminhar para atendimento humano quando faltar evidencia.

## 7. Interface, permissoes e operacao

- [x] 7.1 Criar telas de bases com status, aprovacao e associacao a fluxos.
- [x] 7.2 Criar telas de documentos com upload, status, aprovacao, rejeicao, obsolescencia, reprocessamento e exclusao.
- [x] 7.3 Criar previa de texto extraido e de trechos.
- [x] 7.4 Criar teste de busca e teste de resposta sem envio.
- [x] 7.5 Exibir as fontes usadas na tela da sugestao.
- [x] 7.6 Criar download autorizado de arquivo privado.
- [x] 7.7 Criar permissoes, gates, papeis e menu.
- [x] 7.8 Semear configuracoes `knowledge.*` desligadas por padrao.
- [x] 7.9 Criar comandos de indexacao, sincronizacao e diagnostico.

## 8. Validacao

- [x] 8.1 Criar testes unitarios de chunker, sanitizador de injecao e validador de fundamentacao.
- [x] 8.2 Criar testes de feature dos criterios de aceitacao com provedor fake e HTTP fake.
- [x] 8.3 Criar testes de documento duplicado, MIME invalido, falha de indexacao, documento nao aprovado e documento obsoleto.
- [x] 8.4 Criar testes de recuperacao vazia, citacao invalida, injecao em documento, exclusao, troca de versao e provedor indisponivel.
- [x] 8.5 Criar teste de limites do armazenamento vetorial.
- [x] 8.6 Criar teste de isolamento estrutural entre recuperacao e dados de conversa.
- [x] 8.7 Criar testes de regressao das etapas 1 a 9C.
- [x] 8.8 Executar migrations, testes, build e Pint.
- [x] 8.9 Executar validacoes OpenSpec.

## 9. Documentacao

- [x] 9.1 Criar documentacao operacional e de seguranca da subetapa.
- [x] 9.2 Criar roteiro de homologacao manual.
- [x] 9.3 Atualizar README na secao da Etapa 9.
