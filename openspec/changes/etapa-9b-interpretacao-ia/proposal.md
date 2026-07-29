## Why

A subetapa 9A registra respostas da pesquisa conversacional como texto livre. Esse texto e a fonte primaria e imutavel, mas nao e pesquisavel: nao existe tema, problema identificado, acao sugerida, urgencia nem agrupamento por assunto. Ler manualmente milhares de respostas nao escala.

A subetapa 9B adiciona uma camada de interpretacao por IA que apenas **le** a resposta e produz dados estruturados derivados. A IA nao conversa, nao gera texto de resposta e nao envia nada. Toda decisao de envio continua deterministica e continua sendo a da 9A.

O resultado da IA e derivado, versionado, reprocessavel e nunca substitui a mensagem original. Se a interpretacao falhar, estiver invalida ou tiver baixa confianca, o item vai para revisao humana em vez de produzir efeito automatico.

## What Changes

- Adicionar abstracao de provedor de IA independente de fornecedor, com modelo, URL e chave apenas em configuracao e variaveis de ambiente, saida JSON obrigatoriamente validada por schema, timeout, tentativas, backoff, disjuntor simples e logs sem dado pessoal.
- Adicionar registro auditavel de cada execucao de IA com finalidade, provedor, modelo, versao de prompt, versao de schema, status, hash da requisicao, resultado estruturado, uso de tokens, latencia, custo estimado opcional, confianca, erro sanitizado e tentativa.
- Adicionar classificacao ampliada de mensagens abertas em treze categorias, preservando a precedencia absoluta da regra deterministica da 9A para opt-out e respostas curtas claras.
- Adicionar extracao estruturada e pesquisavel de opinioes com resumo, tema principal, temas secundarios, problema identificado, acao sugerida, resultado desejado, grupo afetado, localidade declarada, urgencia, sentimento descritivo, palavras-chave, confianca e sinalizacao de revisao.
- Adicionar taxonomia administrativa de temas e subtemas com sinonimos, ativo/inativo, ordenacao, cor de interface, tema de fallback obrigatorio e protecao contra exclusao de tema ja utilizado.
- Adicionar pipeline assincrono em fila propria que persiste a mensagem antes de qualquer analise, aplica guarda, classifica, valida, extrai, persiste e sinaliza revisao, sem enviar nenhuma resposta gerada.
- Adicionar prompts versionados em arquivos, com uma versao ativa por finalidade e reprocessamento controlado por versao.
- Adicionar thresholds de confianca configuraveis e regras deterministicas de encaminhamento para atendimento humano em situacoes sensiveis.
- Adicionar controles de privacidade: anonimizacao para relatorios, mascaramento de telefone em telas analiticas, retencao configuravel das execucoes de IA e ausencia de dado pessoal em log tecnico.
- Adicionar telas de painel na conversa, fila de revisao, correcao manual auditada, reprocessamento autorizado, historico de versoes, CRUD de taxonomia e monitoramento, todos com permissoes proprias.
- Adicionar comandos de reprocessamento seguro por identificador ou periodo e de aplicacao de retencao, com confirmacao explicita.

## Impact

- Affected specs: `ai-interpretation` (nova), `conversation-automation`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, factories, contrato e provedores de IA, servicos, jobs, comandos, controllers, rotas, views, seeders, testes e documentacao Laravel.
- Nao afetado: servico Node.js `whatsapp-service/` permanece inalterado; nenhuma decisao de envio da 9A muda; a mensagem original nunca e alterada.
- Constraints desta subetapa: sem geracao de resposta contextual, sem autoenvio, sem RAG, sem embeddings, sem dashboards analiticos completos, sem inferencia de atributo sensivel e sem microdirecionamento individual.
- Seguranca e LGPD: IA desligada por padrao, contexto minimo enviado ao modelo, nenhuma mensagem de terceiros no prompt, chave apenas em segredo de ambiente, telefone mascarado em telas analiticas, retencao configuravel e correcoes humanas auditadas sem treinamento automatico.
