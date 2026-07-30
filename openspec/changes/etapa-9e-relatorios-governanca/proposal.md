## Why

As subetapas 9A a 9D produzem dados e ninguem os le. O fluxo conversacional grava estagio, permissao e resposta; a interpretacao grava tema, problema, acao sugerida e confianca; a geracao grava sugestao, aprovacao, edicao e recusa; a base oficial grava recuperacao e citacao. Hoje isso so e visivel uma conversa por vez, na tela de quem atende.

A subetapa 9E fecha o ciclo: transforma o que foi coletado em leitura agregada. O objetivo e ouvir demanda, nao perfilar pessoa. Essa distincao decide o desenho inteiro — e a razao de existirem supressao de grupo pequeno, anonimizacao na exportacao e ausencia deliberada de filtro por atributo sensivel.

Um relatorio que permite descer ate o individuo e uma ferramenta de microdirecionamento com outro nome. A 9E e construida para nao permitir isso, mesmo para quem tem todas as permissoes.

## What Changes

- Adicionar painel executivo de participacao com abordados, permissoes concedidas e negadas, opt-outs, respostas, conclusoes, aguardando humano, taxas, tempo ate primeira resposta e media de turnos, por periodo e por fluxo.
- Adicionar relatorio de temas com mais mencionados, subtemas, tendencia por periodo, temas emergentes, nao classificados, confianca media e quantidade revisada por humano.
- Adicionar relatorio de geografia usando apenas cidade e regiao ja existentes no cadastro ou declaradas pela propria pessoa, com supressao de celulas abaixo do minimo configurado e sem cruzamento com atributo sensivel.
- Adicionar relatorio de demandas com problemas, acoes sugeridas, resultados desejados, urgencia, exemplos anonimizados e fila de baixa confianca.
- Adicionar relatorio de qualidade da IA com correcoes, baixa confianca, handoff, aprovacao sem edicao, aprovacao com edicao, recusas com motivo, falhas por provedor, modelo e versao de prompt, latencia e custo estimado.
- Adicionar relatorio de qualidade das perguntas com taxa de permissao, taxa de resposta, taxa de conclusao, comprimento medio da resposta e frequencia de handoff por pergunta.
- Adicionar relatorio de governanca com estado dos interruptores, fluxos ativos, documentos oficiais vigentes, prompts e modelos em uso, thresholds, eventos sensiveis, opt-outs, falhas, itens nao revisados, divergencias de configuracao, saude de filas e historico de alteracoes.
- Adicionar exportacao agregada por padrao e exportacao detalhada somente com permissao elevada e justificativa registrada, com anonimizacao, expiracao, arquivo privado e processamento assincrono.
- Adicionar neutralizacao de injecao de formula em CSV e XLSX, aplicada tambem a exportacao existente da Etapa 6.
- Adicionar materializacao diaria idempotente de metricas de conversa, reconstruivel por comando sem duplicacao.
- Adicionar nove permissoes separando ver agregado, ver conteudo, ver identificacao, exportar agregado, exportar detalhado, administrar taxonomia, administrar IA, ver custo e ver governanca.
- Adicionar comandos de retencao para anonimizar ou excluir conteudo, com reprocessamento dos agregados e registro de execucao.
- Adicionar documentacao de formulas com numerador, denominador e exclusoes de cada taxa.

## Impact

- Affected specs: `analytics-governance` (nova), `history-compliance`, `admin-foundation`, `project-foundation`
- Affected code: migrations de metricas diarias e de exportacao, enums, models, servicos de metricas, supressao, anonimizacao e sanitizacao, controllers, rotas, views, permissoes, comandos, jobs, seeders, testes e documentacao.
- Nao afetado: servico Node.js `whatsapp-service/` permanece inalterado; nenhuma mensagem e enviada por esta subetapa; a 9A continua deterministica; a 9B, a 9C e a 9D continuam com o comportamento anterior; os relatorios da Etapa 6 continuam funcionando.
- Alteracao de comportamento existente: a exportacao da Etapa 6 passa a neutralizar celulas iniciadas por `=`, `+`, `-` ou `@`. E correcao de uma vulnerabilidade real, nao mudanca de escopo, e esta coberta por teste.
- Compatibilidade: a 9E e somente leitura sobre dados ja gravados. Com 9B, 9C e 9D desligadas, os relatorios continuam abrindo e mostram estado vazio explicito, nunca erro.
- Seguranca e LGPD: agregacao por padrao, supressao de grupo pequeno, ausencia de filtro por atributo sensivel, telefone mascarado, nome removido, identificador de contato substituido por pseudonimo irreversivel por exportacao, finalidade declarada e registrada, arquivo privado com expiracao.
