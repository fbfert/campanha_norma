# Etapa 9A — Preparação e proposta de fluxo

**Data:** 2026-07-30
**Executado por:** Claude Code, no servidor de produção
**Roteiro:** `docs/tests/conversational-manual-etapa-9a.md`

## Preparação — completa

| # | Item | Resultado |
| --- | --- | --- |
| 1 | Migrations e seeders | **Feito.** Nenhuma migration pendente; `RolePermissionSeeder`, `SystemSettingSeeder` e `InsightTopicSeeder` aplicados; cache limpo. |
| 2 | Filas no worker | **Feito.** `conversation-automation` e `conversation-automation-send` no `gerenciador-mensagens-queue`, serviço ativo. |
| 3 | WhatsApp conectado | **Confirmado.** Conexão 1 em `connected`, número 554991888242. |

Estado inicial correto para começar: `conversation_automation.enabled = 0` e
`auto_send_enabled = 0`. A Fase 2 liga o primeiro; a Fase 3 liga o segundo.

Usuários de homologação criados para as Fases 6 e 4.2 do roteiro da 9D:
`operador@contato.tars.art.br` e `consulta@contato.tars.art.br`, ambos ativos e
obrigados a trocar a senha no primeiro acesso.

## Proposta de fluxo para a Fase 1

Conteúdo sugerido, **pendente de aprovação**. As perguntas são de escuta: nenhuma
pede voto, nenhuma promete ação e nenhuma coleta dado sensível. Isso não e
preferência de estilo, e o que as regras da etapa exigem.

### Textos do fluxo

- **Apresentação:** "Ola! Aqui e da equipe da Professora Norma. Estamos ouvindo
  moradores da região para entender o que mais preocupa as pessoas hoje. Posso
  te fazer uma pergunta rapida?"
- **Agradecimento:** "Muito obrigado por compartilhar. Sua resposta foi
  registrada e vai ajudar a equipe a entender melhor as demandas da região."
- **Recusa:** "Tudo bem, obrigado pela atenção! Se mudar de ideia, e so
  responder por aqui."
- **Aviso de transparência:** já configurado como sufixo — "Esta e uma mensagem
  automática. Responda para falar com nossa equipe."
- **Máximo de aprofundamentos:** zero, conforme o item 3 do roteiro.

### Perguntas propostas

Peso maior nas de tema aberto, que rendem mais informação por resposta.

| # | Pergunta | Peso |
| --- | --- | --- |
| 1 | Qual e o principal problema do seu bairro hoje? | 5 |
| 2 | Se você pudesse resolver uma única coisa na sua cidade, qual seria? | 5 |
| 3 | O que mais te preocupa no dia a dia da sua família? | 5 |
| 4 | Como esta a saúde pública na sua região? | 4 |
| 5 | Como esta a educação na sua região? | 4 |
| 6 | Como esta a segurança no lugar onde você mora? | 4 |
| 7 | Você tem dificuldade para conseguir atendimento médico? Conte como e. | 3 |
| 8 | Como e o transporte público que você usa? | 3 |
| 9 | Falta alguma coisa na escola dos seus filhos ou netos? | 3 |
| 10 | Como estão as ruas e calçadas do seu bairro? | 3 |
| 11 | Você sente falta de espaços de lazer ou esporte por perto? | 3 |
| 12 | O que você acha do atendimento nos serviços públicos da sua cidade? | 3 |
| 13 | Existe algum problema de água, esgoto ou lixo onde você mora? | 3 |
| 14 | Você ou alguém próximo teve dificuldade para conseguir emprego este ano? | 2 |
| 15 | Como e o acesso a internet na sua região? | 2 |
| 16 | Falta algum serviço público perto da sua casa? | 2 |
| 17 | O que funciona bem na sua cidade e merece ser mantido? | 2 |
| 18 | Se você pudesse dar um recado para quem decide as políticas públicas, qual seria? | 2 |
| 19 | Você conhece o trabalho da Professora Norma? O que sabe sobre ele? | 1 |
| 20 | Tem alguma coisa que você gostaria que a equipe soubesse? | 1 |

### Observações sobre o conteúdo

- A pergunta 19 e a única que cita a Professora Norma e e formulada como
  pergunta aberta, não como afirmação a ser confirmada. Se o objetivo for medir
  conhecimento espontaneo, ela deve ficar; se houver risco de soar como
  divulgação, o mais seguro e remover.
- Nenhuma pergunta menciona eleição, voto, candidatura ou número de urna.
- Nenhuma pergunta pede idade, renda, religião, orientação, filiação partidária
  ou documento.
- Perguntas 7, 14 e 9 tocam em situação pessoal. Estão formuladas como convite,
  não como exigência, e a pessoa pode responder de forma genérica.

## Próximo passo

Aprovar, ajustar ou substituir a lista acima. Com o conteúdo definido, a Fase 1
e cadastro pela tela `Fluxos conversacionais`, e da para seguir até a Fase 2 sem
enviar nada para ninguém: a Fase 2 liga so a avaliação, com o envio automático
ainda desligado.
