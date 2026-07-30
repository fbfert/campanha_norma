## Why

A 9A envia uma pergunta e encerra. A 9B interpreta a resposta, mas não devolve nada a pessoa. O resultado e uma pesquisa de turno único: quando alguém escreve "falta médico aqui", não ha como pedir o detalhe que transforma esse relato em informação útil.

A subetapa 9C adiciona geração de resposta contextualizada com um propósito estreito: **aprofundar a opinião da própria pessoa**, com no máximo duas perguntas, sempre a partir do que ela mesma escreveu.

O modo padrão e obrigatório de entrada em produção e sugerir para aprovação humana. Nenhum texto gerado chega ao contato sem um operador aprovar. O autoenvio existe, nasce desligado, e so pode funcionar para categorias explicitamente permitidas, com confiança alta e sob todos os guards.

## What Changes

- Adicionar gerador de resposta por interface independente de fornecedor, com saída JSON validada por schema, prompts versionados e execução ligada ao run de classificação e ao insight da 9B.
- Adicionar contrato estruturado com seis ações permitidas: `suggest_reply`, `thank_and_complete`, `request_clarification`, `handoff_human`, `no_reply` e `opt_out`.
- Adicionar validador determinístico do texto gerado, aplicado depois do modelo, cobrindo idioma, tamanho, quantidade de perguntas, promessa, pedido de voto, comparação com adversários, urgência artificial, simulação de intimidade e alegação de leitura pessoal.
- Adicionar caixa de aprovação com edição antes do envio, aprovação individual, rejeição, regeneração com justificativa, assunção manual e bloqueio de sugestão obsoleta.
- Adicionar quatro modos de operação: `disabled`, `draft_only`, `approval_required` e `auto_send_limited`, com o modo do fluxo podendo ser mais restritivo que o global, nunca menos.
- Adicionar autoenvio limitado, desligado por padrão, condicionado a lista de categorias permitidas, threshold de confiança, ausência de sinalização sensível, elegibilidade do contato, janela de horário, limite de turnos, trava e validação determinística do texto.
- Adicionar handoff humano com treze motivos, pausa da automação, mudança de estado, elevação de prioridade, evento e exibição do motivo, sem nenhum texto improvisado.
- Adicionar limite de aprofundamento com contagem idempotente de turnos, agradecimento e encerramento ao atingir o limite, e agrupamento de mensagens consecutivas por debounce configurável.
- Refatorar o envio manual e o automático para compartilhar um serviço de saída único, preservando integralmente o comportamento do envio manual atual.
- Adicionar metadados de origem e autoria de IA nas mensagens de conversa, com selo na linha do tempo.
- Adicionar feedback operacional por sugestão, sem qualquer efeito automático sobre prompt ou modelo.
- Adicionar telas, permissões próprias, observabilidade, testes e documentação.

## Impact

- Affected specs: `ai-response-generation` (nova), `ai-interpretation`, `conversation-automation`, `conversations-sync`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, contrato e serviços de geração, serviço de saída unificado, jobs, controllers, rotas, views, seeders, testes e documentação Laravel.
- Não afetado: serviço Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente; a interpretação da 9B continua sem gerar texto.
- Correção de divergência: a spec `conversations-sync` afirmava que a continuação permaneceria manual e que nenhuma resposta automática existiria. A 9A já havia introduzido envio automático de pergunta sem atualizar essa spec. Esta mudança corrige o texto para descrever o comportamento real e os limites que o cercam.
- Constraints desta subetapa: sem recuperação vetorial, sem base de conhecimento oficial, sem relatórios finais da 9E, sem conversa infinita e sem qualquer envio não aprovado no modo padrão.
- Segurança e LGPD: aprovação humana por padrão, opt-out prevalecendo sobre qualquer sugestão pendente, contexto restrito a própria conversa, ausência de microdirecionamento e transparência sobre atendimento automatizado.
