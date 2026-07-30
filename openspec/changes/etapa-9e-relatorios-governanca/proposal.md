## Why

As subetapas 9A a 9D produzem dados e ninguém os le. O fluxo conversacional grava estagio, permissão e resposta; a interpretação grava tema, problema, ação sugerida e confiança; a geração grava sugestão, aprovação, edição e recusa; a base oficial grava recuperação e citação. Hoje isso so e visível uma conversa por vez, na tela de quem atende.

A subetapa 9E fecha o ciclo: transforma o que foi coletado em leitura agregada. O objetivo e ouvir demanda, não perfilar pessoa. Essa distinção decide o desenho inteiro — e a razão de existirem supressão de grupo pequeno, anonimização na exportação e ausência deliberada de filtro por atributo sensível.

Um relatório que permite descer até o indivíduo e uma ferramenta de microdirecionamento com outro nome. A 9E e construida para não permitir isso, mesmo para quem tem todas as permissões.

## What Changes

- Adicionar painel executivo de participação com abordados, permissões concedidas e negadas, opt-outs, respostas, conclusões, aguardando humano, taxas, tempo até primeira resposta e média de turnos, por período e por fluxo.
- Adicionar relatório de temas com mais mencionados, subtemas, tendência por período, temas emergentes, não classificados, confiança média e quantidade revisada por humano.
- Adicionar relatório de geografia usando apenas cidade e região já existentes no cadastro ou declaradas pela própria pessoa, com supressão de células abaixo do mínimo configurado e sem cruzamento com atributo sensível.
- Adicionar relatório de demandas com problemas, ações sugeridas, resultados desejados, urgência, exemplos anonimizados e fila de baixa confiança.
- Adicionar relatório de qualidade da IA com correções, baixa confiança, handoff, aprovação sem edição, aprovação com edição, recusas com motivo, falhas por provedor, modelo e versão de prompt, latência e custo estimado.
- Adicionar relatório de qualidade das perguntas com taxa de permissão, taxa de resposta, taxa de conclusão, comprimento médio da resposta e frequência de handoff por pergunta.
- Adicionar relatório de governança com estado dos interruptores, fluxos ativos, documentos oficiais vigentes, prompts e modelos em uso, thresholds, eventos sensíveis, opt-outs, falhas, itens não revisados, divergências de configuração, saúde de filas e histórico de alterações.
- Adicionar exportação agregada por padrão e exportação detalhada somente com permissão elevada e justificativa registrada, com anonimização, expiração, arquivo privado e processamento assíncrono.
- Adicionar neutralização de injeção de fórmula em CSV e XLSX, aplicada também a exportação existente da Etapa 6.
- Adicionar materialização diária idempotente de métricas de conversa, reconstruível por comando sem duplicação.
- Adicionar nove permissões separando ver agregado, ver conteúdo, ver identificação, exportar agregado, exportar detalhado, administrar taxonomia, administrar IA, ver custo e ver governança.
- Adicionar comandos de retenção para anonimizar ou excluir conteúdo, com reprocessamento dos agregados e registro de execução.
- Adicionar documentação de fórmulas com numerador, denominador e exclusões de cada taxa.

## Impact

- Affected specs: `analytics-governance` (nova), `history-compliance`, `admin-foundation`, `project-foundation`
- Affected code: migrations de métricas diárias e de exportação, enums, models, serviços de métricas, supressão, anonimização e sanitização, controllers, rotas, views, permissões, comandos, jobs, seeders, testes e documentação.
- Não afetado: serviço Node.js `whatsapp-service/` permanece inalterado; nenhuma mensagem e enviada por esta subetapa; a 9A continua determinística; a 9B, a 9C e a 9D continuam com o comportamento anterior; os relatórios da Etapa 6 continuam funcionando.
- Alteração de comportamento existente: a exportação da Etapa 6 passa a neutralizar células iniciadas por `=`, `+`, `-` ou `@`. E correção de uma vulnerabilidade real, não mudança de escopo, e esta coberta por teste.
- Compatibilidade: a 9E e somente leitura sobre dados já gravados. Com 9B, 9C e 9D desligadas, os relatórios continuam abrindo e mostram estado vazio explícito, nunca erro.
- Segurança e LGPD: agregação por padrão, supressão de grupo pequeno, ausência de filtro por atributo sensível, telefone mascarado, nome removido, identificador de contato substituído por pseudônimo irreversível por exportação, finalidade declarada e registrada, arquivo privado com expiração.
