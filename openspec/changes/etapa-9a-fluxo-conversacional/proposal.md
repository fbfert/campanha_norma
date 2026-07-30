## Why

Etapa 9 introduz pesquisa conversacional com a pergunta central da Professora Norma sobre o que ela pode fazer pela Assembleia Legislativa de Santa Catarina. A subetapa 9A cria a fundação determinística desse fluxo: cadastro de fluxos e perguntas, estado persistido por conversa, classificação de permissão por regras e envio de uma única pergunta sorteada.

Nenhuma decisão desta subetapa usa LLM, embeddings, RAG ou classificação por IA. Toda transição deriva de estado persistido e de listas de expressões configuráveis, para que o comportamento seja auditável, reproduzível e homologável antes de qualquer camada de inteligência nas subetapas seguintes.

## What Changes

- Adicionar administração de fluxos conversacionais com status, textos de apresentação e agradecimento, limites de perguntas, validade e transparência sobre automação.
- Adicionar administração de perguntas por fluxo com peso para sorteio, categoria, versão, ordem administrativa e exclusão apenas lógica.
- Adicionar estado persistente por conversa com maquina de estados explícita, contadores, motivos de encerramento, pausa e revisão humana.
- Adicionar histórico de transições e registro de uso de perguntas, impedindo que a mesma pergunta seja sorteada duas vezes na mesma conversa.
- Adicionar classificador determinístico de respostas curtas de permissão com prioridade absoluta para opt-out.
- Adicionar seleção transacional e travada de pergunta, com congelamento do texto e criação de uma única mensagem automática pendente.
- Integrar o início do fluxo ao envio de campanhas por associação opcional entre lote e fluxo, preservando snapshot e sem alterar campanhas antigas.
- Integrar a avaliação do fluxo ao recebimento de mensagens após o commit, em filas próprias, sem atrasar o registro da mensagem recebida.
- Adicionar telas administrativas, permissões, configurações, auditoria, monitoramento, testes e documentação operacional.

## Impact

- Affected specs: `conversation-automation` (nova), `admin-foundation`, `batch-queue`, `contact-management`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, factories, services, jobs, controllers, rotas, views, seeders, monitoramento, testes e documentação Laravel.
- Não afetado: serviço Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente.
- Constraints desta subetapa: sem IA, sem embeddings, sem RAG, sem aprofundamento de perguntas, sem conversa infinita, sem resposta automática fora do fluxo especificado, sem API oficial da Meta.
- Segurança e LGPD: automação desligada por padrão, transparência configurável sobre atendimento automatizado, opt-out imediato e prioritário, minimização de dados e ausência de microdirecionamento individual.
