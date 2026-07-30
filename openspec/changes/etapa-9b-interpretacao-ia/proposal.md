## Why

A subetapa 9A registra respostas da pesquisa conversacional como texto livre. Esse texto e a fonte primária e imutável, mas não e pesquisável: não existe tema, problema identificado, ação sugerida, urgência nem agrupamento por assunto. Ler manualmente milhares de respostas não escala.

A subetapa 9B adiciona uma camada de interpretação por IA que apenas **le** a resposta e produz dados estruturados derivados. A IA não conversa, não gera texto de resposta e não envia nada. Toda decisão de envio continua determinística e continua sendo a da 9A.

O resultado da IA e derivado, versionado, reprocessável e nunca substitui a mensagem original. Se a interpretação falhar, estiver invalida ou tiver baixa confiança, o item vai para revisão humana em vez de produzir efeito automático.

## What Changes

- Adicionar abstração de provedor de IA independente de fornecedor, com modelo, URL e chave apenas em configuração e variáveis de ambiente, saída JSON obrigatoriamente validada por schema, timeout, tentativas, backoff, disjuntor simples e logs sem dado pessoal.
- Adicionar registro auditável de cada execução de IA com finalidade, provedor, modelo, versão de prompt, versão de schema, status, hash da requisição, resultado estruturado, uso de tokens, latência, custo estimado opcional, confiança, erro sanitizado e tentativa.
- Adicionar classificação ampliada de mensagens abertas em treze categorias, preservando a precedência absoluta da regra determinística da 9A para opt-out e respostas curtas claras.
- Adicionar extração estruturada e pesquisável de opiniões com resumo, tema principal, temas secundários, problema identificado, ação sugerida, resultado desejado, grupo afetado, localidade declarada, urgência, sentimento descritivo, palavras-chave, confiança e sinalização de revisão.
- Adicionar taxonomia administrativa de temas e subtemas com sinônimos, ativo/inativo, ordenação, cor de interface, tema de fallback obrigatório e proteção contra exclusão de tema já utilizado.
- Adicionar pipeline assíncrono em fila própria que persiste a mensagem antes de qualquer análise, aplica guarda, classifica, valida, extrai, persiste e sinaliza revisão, sem enviar nenhuma resposta gerada.
- Adicionar prompts versionados em arquivos, com uma versão ativa por finalidade e reprocessamento controlado por versão.
- Adicionar thresholds de confiança configuráveis e regras deterministicas de encaminhamento para atendimento humano em situações sensíveis.
- Adicionar controles de privacidade: anonimização para relatórios, mascaramento de telefone em telas analíticas, retenção configurável das execuções de IA e ausência de dado pessoal em log técnico.
- Adicionar telas de painel na conversa, fila de revisão, correção manual auditada, reprocessamento autorizado, histórico de versões, CRUD de taxonomia e monitoramento, todos com permissões próprias.
- Adicionar comandos de reprocessamento seguro por identificador ou período e de aplicação de retenção, com confirmação explícita.

## Impact

- Affected specs: `ai-interpretation` (nova), `conversation-automation`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, factories, contrato e provedores de IA, serviços, jobs, comandos, controllers, rotas, views, seeders, testes e documentação Laravel.
- Não afetado: serviço Node.js `whatsapp-service/` permanece inalterado; nenhuma decisão de envio da 9A muda; a mensagem original nunca e alterada.
- Constraints desta subetapa: sem geração de resposta contextual, sem autoenvio, sem RAG, sem embeddings, sem dashboards analíticos completos, sem inferência de atributo sensível e sem microdirecionamento individual.
- Segurança e LGPD: IA desligada por padrão, contexto mínimo enviado ao modelo, nenhuma mensagem de terceiros no prompt, chave apenas em segredo de ambiente, telefone mascarado em telas analíticas, retenção configurável e correções humanas auditadas sem treinamento automático.
