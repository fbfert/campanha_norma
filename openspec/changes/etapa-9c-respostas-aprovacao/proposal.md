## Why

A 9A envia uma pergunta e encerra. A 9B interpreta a resposta, mas nao devolve nada a pessoa. O resultado e uma pesquisa de turno unico: quando alguem escreve "falta medico aqui", nao ha como pedir o detalhe que transforma esse relato em informacao util.

A subetapa 9C adiciona geracao de resposta contextualizada com um proposito estreito: **aprofundar a opiniao da propria pessoa**, com no maximo duas perguntas, sempre a partir do que ela mesma escreveu.

O modo padrao e obrigatorio de entrada em producao e sugerir para aprovacao humana. Nenhum texto gerado chega ao contato sem um operador aprovar. O autoenvio existe, nasce desligado, e so pode funcionar para categorias explicitamente permitidas, com confianca alta e sob todos os guards.

## What Changes

- Adicionar gerador de resposta por interface independente de fornecedor, com saida JSON validada por schema, prompts versionados e execucao ligada ao run de classificacao e ao insight da 9B.
- Adicionar contrato estruturado com seis acoes permitidas: `suggest_reply`, `thank_and_complete`, `request_clarification`, `handoff_human`, `no_reply` e `opt_out`.
- Adicionar validador deterministico do texto gerado, aplicado depois do modelo, cobrindo idioma, tamanho, quantidade de perguntas, promessa, pedido de voto, comparacao com adversarios, urgencia artificial, simulacao de intimidade e alegacao de leitura pessoal.
- Adicionar caixa de aprovacao com edicao antes do envio, aprovacao individual, rejeicao, regeneracao com justificativa, assuncao manual e bloqueio de sugestao obsoleta.
- Adicionar quatro modos de operacao: `disabled`, `draft_only`, `approval_required` e `auto_send_limited`, com o modo do fluxo podendo ser mais restritivo que o global, nunca menos.
- Adicionar autoenvio limitado, desligado por padrao, condicionado a lista de categorias permitidas, threshold de confianca, ausencia de sinalizacao sensivel, elegibilidade do contato, janela de horario, limite de turnos, trava e validacao deterministica do texto.
- Adicionar handoff humano com treze motivos, pausa da automacao, mudanca de estado, elevacao de prioridade, evento e exibicao do motivo, sem nenhum texto improvisado.
- Adicionar limite de aprofundamento com contagem idempotente de turnos, agradecimento e encerramento ao atingir o limite, e agrupamento de mensagens consecutivas por debounce configuravel.
- Refatorar o envio manual e o automatico para compartilhar um servico de saida unico, preservando integralmente o comportamento do envio manual atual.
- Adicionar metadados de origem e autoria de IA nas mensagens de conversa, com selo na linha do tempo.
- Adicionar feedback operacional por sugestao, sem qualquer efeito automatico sobre prompt ou modelo.
- Adicionar telas, permissoes proprias, observabilidade, testes e documentacao.

## Impact

- Affected specs: `ai-response-generation` (nova), `ai-interpretation`, `conversation-automation`, `conversations-sync`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, contrato e servicos de geracao, servico de saida unificado, jobs, controllers, rotas, views, seeders, testes e documentacao Laravel.
- Nao afetado: servico Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente; a interpretacao da 9B continua sem gerar texto.
- Correcao de divergencia: a spec `conversations-sync` afirmava que a continuacao permaneceria manual e que nenhuma resposta automatica existiria. A 9A ja havia introduzido envio automatico de pergunta sem atualizar essa spec. Esta mudanca corrige o texto para descrever o comportamento real e os limites que o cercam.
- Constraints desta subetapa: sem recuperacao vetorial, sem base de conhecimento oficial, sem relatorios finais da 9E, sem conversa infinita e sem qualquer envio nao aprovado no modo padrao.
- Seguranca e LGPD: aprovacao humana por padrao, opt-out prevalecendo sobre qualquer sugestao pendente, contexto restrito a propria conversa, ausencia de microdirecionamento e transparencia sobre atendimento automatizado.
