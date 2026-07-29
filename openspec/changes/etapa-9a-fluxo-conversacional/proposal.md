## Why

Etapa 9 introduz pesquisa conversacional com a pergunta central da Professora Norma sobre o que ela pode fazer pela Assembleia Legislativa de Santa Catarina. A subetapa 9A cria a fundacao deterministica desse fluxo: cadastro de fluxos e perguntas, estado persistido por conversa, classificacao de permissao por regras e envio de uma unica pergunta sorteada.

Nenhuma decisao desta subetapa usa LLM, embeddings, RAG ou classificacao por IA. Toda transicao deriva de estado persistido e de listas de expressoes configuraveis, para que o comportamento seja auditavel, reproduzivel e homologavel antes de qualquer camada de inteligencia nas subetapas seguintes.

## What Changes

- Adicionar administracao de fluxos conversacionais com status, textos de apresentacao e agradecimento, limites de perguntas, validade e transparencia sobre automacao.
- Adicionar administracao de perguntas por fluxo com peso para sorteio, categoria, versao, ordem administrativa e exclusao apenas logica.
- Adicionar estado persistente por conversa com maquina de estados explicita, contadores, motivos de encerramento, pausa e revisao humana.
- Adicionar historico de transicoes e registro de uso de perguntas, impedindo que a mesma pergunta seja sorteada duas vezes na mesma conversa.
- Adicionar classificador deterministico de respostas curtas de permissao com prioridade absoluta para opt-out.
- Adicionar selecao transacional e travada de pergunta, com congelamento do texto e criacao de uma unica mensagem automatica pendente.
- Integrar o inicio do fluxo ao envio de campanhas por associacao opcional entre lote e fluxo, preservando snapshot e sem alterar campanhas antigas.
- Integrar a avaliacao do fluxo ao recebimento de mensagens apos o commit, em filas proprias, sem atrasar o registro da mensagem recebida.
- Adicionar telas administrativas, permissoes, configuracoes, auditoria, monitoramento, testes e documentacao operacional.

## Impact

- Affected specs: `conversation-automation` (nova), `admin-foundation`, `batch-queue`, `contact-management`, `history-compliance`, `project-foundation`
- Affected code: migrations, enums, models, factories, services, jobs, controllers, rotas, views, seeders, monitoramento, testes e documentacao Laravel.
- Nao afetado: servico Node.js `whatsapp-service/` permanece inalterado; o envio continua pelo `WhatsAppProvider` existente.
- Constraints desta subetapa: sem IA, sem embeddings, sem RAG, sem aprofundamento de perguntas, sem conversa infinita, sem resposta automatica fora do fluxo especificado, sem API oficial da Meta.
- Seguranca e LGPD: automacao desligada por padrao, transparencia configuravel sobre atendimento automatizado, opt-out imediato e prioritario, minimizacao de dados e ausencia de microdirecionamento individual.
