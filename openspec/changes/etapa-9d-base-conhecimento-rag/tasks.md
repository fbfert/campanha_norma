## 1. Especificação

- [x] 1.1 Ler README, documentação das subetapas 9A, 9B e 9C, filas, monitoramento e implantação.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` e as mudanças 9A, 9B e 9C.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9D.
- [x] 1.4 Escrever ADR de escolha de provedor, limites medidos e procedimento de troca.
- [x] 1.5 Validar com OpenSpec.

## 2. Contratos e configuração

- [x] 2.1 Criar contratos `KnowledgeBaseProvider`, `EmbeddingProvider`, `KnowledgeRetriever` e `AnswerGroundingValidator`.
- [x] 2.2 Criar objetos de dados de consulta, resultado, trecho recuperado e veredito de fundamentação.
- [x] 2.3 Criar `config/knowledge.php` com provedor, credencial, modelo de embedding, limites de transporte e comandos externos.
- [x] 2.4 Criar provedor inerte e provedor local com armazenamento relacional.
- [x] 2.5 Criar provedor de embeddings compatível com API no formato OpenAI e implementação inerte.
- [x] 2.6 Criar resolvedores de provedor por configuração.

## 3. Dados, enums e models

- [x] 3.1 Criar migration de bases, documentos, trechos, associação base-fluxo, log de recuperação e citações.
- [x] 3.2 Adicionar colunas de fundamentação em `conversation_reply_suggestions` em migration própria.
- [x] 3.3 Criar enums de status de base, status de documento, tipo de documento, estratégia de recuperação e status de fundamentação.
- [x] 3.4 Criar models, relacionamentos, escopos de recuperabilidade e factories.
- [x] 3.5 Acrescentar motivos de handoff de fundamentação insuficiente e evidência ausente.

## 4. Ingestão

- [x] 4.1 Criar serviço de upload com disco privado, normalização de nome, validação de MIME e tamanho.
- [x] 4.2 Criar hash de conteúdo e deduplicação por base.
- [x] 4.3 Criar verificação antivirus configurável com comportamento explícito na ausência do scanner.
- [x] 4.4 Criar extratores de texto plano, Markdown, HTML e DOCX nativos.
- [x] 4.5 Criar extrator de PDF por binário configurável, com falha limpa quando ausente.
- [x] 4.6 Criar sanitizador de injeção de prompt com padrões configuráveis e registro do achado.
- [x] 4.7 Criar chunker configurável com metadados de página e seção.
- [x] 4.8 Criar serviço de indexação e job em fila própria.
- [x] 4.9 Criar reprocessamento e exclusão sincronizada com o provedor.
- [x] 4.10 Exigir aprovação humana antes da disponibilidade para busca.

## 5. Recuperação e fundamentação

- [x] 5.1 Criar retriever com estratégias léxica, vetorial e híbrida.
- [x] 5.2 Aplicar filtro de base associada ao fluxo, status aprovado e versão vigente.
- [x] 5.3 Aplicar `top_k`, threshold, limite de contexto e deduplicação.
- [x] 5.4 Registrar log de recuperação com snapshot de conteúdo dos trechos retornados.
- [x] 5.5 Aplicar limite de candidatos com recusa explícita e queda para a estratégia léxica.
- [x] 5.6 Criar validador de fundamentação determinístico com os sete motivos de reprovação.

## 6. Integração com a geração

- [x] 6.1 Criar prompt e schema versionados próprios com `grounded` e `citations`.
- [x] 6.2 Separar bloco oficial e bloco de conversa no montador de contexto, com delimitação de dado.
- [x] 6.3 Selecionar versões fundamentadas apenas quando houver base ativa associada ao fluxo.
- [x] 6.4 Persistir referência de recuperação, veredito e citações na sugestão.
- [x] 6.5 Bloquear envio e autoenvio de resposta com fundamentação invalida.
- [x] 6.6 Encaminhar para atendimento humano quando faltar evidência.

## 7. Interface, permissões e operação

- [x] 7.1 Criar telas de bases com status, aprovação e associação a fluxos.
- [x] 7.2 Criar telas de documentos com upload, status, aprovação, rejeição, obsolescência, reprocessamento e exclusão.
- [x] 7.3 Criar previa de texto extraido e de trechos.
- [x] 7.4 Criar teste de busca e teste de resposta sem envio.
- [x] 7.5 Exibir as fontes usadas na tela da sugestão.
- [x] 7.6 Criar download autorizado de arquivo privado.
- [x] 7.7 Criar permissões, gates, papeis e menu.
- [x] 7.8 Semear configurações `knowledge.*` desligadas por padrão.
- [x] 7.9 Criar comandos de indexação, sincronização e diagnostico.

## 8. Validação

- [x] 8.1 Criar testes unitários de chunker, sanitizador de injeção e validador de fundamentação.
- [x] 8.2 Criar testes de feature dos critérios de aceitação com provedor fake e HTTP fake.
- [x] 8.3 Criar testes de documento duplicado, MIME inválido, falha de indexação, documento não aprovado e documento obsoleto.
- [x] 8.4 Criar testes de recuperação vazia, citação invalida, injeção em documento, exclusão, troca de versão e provedor indisponível.
- [x] 8.5 Criar teste de limites do armazenamento vetorial.
- [x] 8.6 Criar teste de isolamento estrutural entre recuperação e dados de conversa.
- [x] 8.7 Criar testes de regressão das etapas 1 a 9C.
- [x] 8.8 Executar migrations, testes, build e Pint.
- [x] 8.9 Executar validações OpenSpec.

## 9. Documentação

- [x] 9.1 Criar documentação operacional e de segurança da subetapa.
- [x] 9.2 Criar roteiro de homologação manual.
- [x] 9.3 Atualizar README na seção da Etapa 9.
