## 1. Especificacao

- [x] 1.1 Ler README, documentacao de conversas, mensagens recebidas, filas, monitoramento e automacao conversacional.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` aplicaveis e a mudanca da subetapa 9A.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9B.
- [x] 1.4 Validar com OpenSpec.

## 2. Abstracao de provedor de IA

- [x] 2.1 Criar `config/ai.php` com provedor, URL, chave, modelo e timeouts vindos de ambiente.
- [x] 2.2 Criar contrato `AiProvider` e objetos de requisicao e resultado.
- [x] 2.3 Criar provedor compativel com API de chat com saida estruturada.
- [x] 2.4 Criar provedor nulo para ambiente sem IA configurada.
- [x] 2.5 Criar disjuntor simples, tentativas, backoff e excecao com codigo sanitizado.

## 3. Dados, enums e models

- [x] 3.1 Criar migration de execucoes de IA, classificacoes, insights, taxonomia, pivo de temas e correcoes.
- [x] 3.2 Criar enums de finalidade, status, classificacao, urgencia, sentimento, origem e motivo de revisao.
- [x] 3.3 Criar models, relacionamentos e factories.

## 4. Prompts, schemas e pipeline

- [x] 4.1 Criar prompts versionados em arquivo para classificacao e extracao.
- [x] 4.2 Criar registro de schemas e validador server-side.
- [x] 4.3 Criar montador de contexto minimo sem dado pessoal.
- [x] 4.4 Criar servicos de classificacao, extracao, mapeamento de tema e deteccao de conteudo sensivel.
- [x] 4.5 Criar pipeline idempotente e job em fila propria.
- [x] 4.6 Integrar ao fluxo conversacional da 9A sem alterar decisoes de envio.

## 5. Interface, permissoes e monitoramento

- [x] 5.1 Criar painel de interpretacao na conversa com marcacao de conteudo gerado por IA.
- [x] 5.2 Criar fila de revisao, tela de detalhe, correcao auditada e reprocessamento autorizado.
- [x] 5.3 Criar CRUD de taxonomia com sinonimos, ordenacao, cor, fallback e protecao de tema usado.
- [x] 5.4 Criar permissoes, gates, papeis e menu.
- [x] 5.5 Criar tela de monitoramento de execucoes de IA.
- [x] 5.6 Criar comandos de reprocessamento seguro e de retencao.
- [x] 5.7 Semear configuracoes `ai.*` desligadas por padrao.

## 6. Validacao

- [x] 6.1 Criar testes unitarios do validador de schema, do mapeador de temas e do detector de conteudo sensivel.
- [x] 6.2 Criar testes de feature dos criterios de aceitacao com HTTP fake.
- [x] 6.3 Criar testes de falha, timeout, limite de requisicoes, resposta invalida e sucesso.
- [x] 6.4 Criar testes de idempotencia, concorrencia, permissoes e regressao das etapas 1 a 8 e da 9A.
- [x] 6.5 Executar migrations, testes, build e Pint.
- [x] 6.6 Executar validacoes OpenSpec.

## 7. Documentacao

- [x] 7.1 Criar documentacao operacional e de configuracao do modulo.
- [x] 7.2 Criar roteiro de homologacao manual.
- [x] 7.3 Atualizar README na secao da Etapa 9.
