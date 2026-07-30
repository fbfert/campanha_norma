## 1. Especificação

- [x] 1.1 Ler README, documentação de conversas, mensagens recebidas, filas, monitoramento e automação conversacional.
- [x] 1.2 Ler as specs aprovadas em `openspec/specs/` aplicáveis e a mudança da subetapa 9A.
- [x] 1.3 Criar proposta, design e deltas de spec da subetapa 9B.
- [x] 1.4 Validar com OpenSpec.

## 2. Abstração de provedor de IA

- [x] 2.1 Criar `config/ai.php` com provedor, URL, chave, modelo e timeouts vindos de ambiente.
- [x] 2.2 Criar contrato `AiProvider` e objetos de requisição e resultado.
- [x] 2.3 Criar provedor compatível com API de chat com saída estruturada.
- [x] 2.4 Criar provedor nulo para ambiente sem IA configurada.
- [x] 2.5 Criar disjuntor simples, tentativas, backoff e exceção com código sanitizado.

## 3. Dados, enums e models

- [x] 3.1 Criar migration de execuções de IA, classificações, insights, taxonomia, pivô de temas e correções.
- [x] 3.2 Criar enums de finalidade, status, classificação, urgência, sentimento, origem e motivo de revisão.
- [x] 3.3 Criar models, relacionamentos e factories.

## 4. Prompts, schemas e pipeline

- [x] 4.1 Criar prompts versionados em arquivo para classificação e extração.
- [x] 4.2 Criar registro de schemas e validador server-side.
- [x] 4.3 Criar montador de contexto mínimo sem dado pessoal.
- [x] 4.4 Criar serviços de classificação, extração, mapeamento de tema e detecção de conteúdo sensível.
- [x] 4.5 Criar pipeline idempotente e job em fila própria.
- [x] 4.6 Integrar ao fluxo conversacional da 9A sem alterar decisões de envio.

## 5. Interface, permissões e monitoramento

- [x] 5.1 Criar painel de interpretação na conversa com marcação de conteúdo gerado por IA.
- [x] 5.2 Criar fila de revisão, tela de detalhe, correção auditada e reprocessamento autorizado.
- [x] 5.3 Criar CRUD de taxonomia com sinônimos, ordenação, cor, fallback e proteção de tema usado.
- [x] 5.4 Criar permissões, gates, papeis e menu.
- [x] 5.5 Criar tela de monitoramento de execuções de IA.
- [x] 5.6 Criar comandos de reprocessamento seguro e de retenção.
- [x] 5.7 Semear configurações `ai.*` desligadas por padrão.

## 6. Validação

- [x] 6.1 Criar testes unitários do validador de schema, do mapeador de temas e do detector de conteúdo sensível.
- [x] 6.2 Criar testes de feature dos critérios de aceitação com HTTP fake.
- [x] 6.3 Criar testes de falha, timeout, limite de requisições, resposta invalida e sucesso.
- [x] 6.4 Criar testes de idempotência, concorrência, permissões e regressão das etapas 1 a 8 e da 9A.
- [x] 6.5 Executar migrations, testes, build e Pint.
- [x] 6.6 Executar validações OpenSpec.

## 7. Documentação

- [x] 7.1 Criar documentação operacional e de configuração do módulo.
- [x] 7.2 Criar roteiro de homologação manual.
- [x] 7.3 Atualizar README na seção da Etapa 9.
