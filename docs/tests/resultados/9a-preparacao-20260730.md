# Etapa 9A — Preparacao e proposta de fluxo

**Data:** 2026-07-30
**Executado por:** Claude Code, no servidor de producao
**Roteiro:** `docs/tests/conversational-manual-etapa-9a.md`

## Preparacao — completa

| # | Item | Resultado |
| --- | --- | --- |
| 1 | Migrations e seeders | **Feito.** Nenhuma migration pendente; `RolePermissionSeeder`, `SystemSettingSeeder` e `InsightTopicSeeder` aplicados; cache limpo. |
| 2 | Filas no worker | **Feito.** `conversation-automation` e `conversation-automation-send` no `gerenciador-mensagens-queue`, servico ativo. |
| 3 | WhatsApp conectado | **Confirmado.** Conexao 1 em `connected`, numero 554991888242. |

Estado inicial correto para comecar: `conversation_automation.enabled = 0` e
`auto_send_enabled = 0`. A Fase 2 liga o primeiro; a Fase 3 liga o segundo.

Usuarios de homologacao criados para as Fases 6 e 4.2 do roteiro da 9D:
`operador@contato.tars.art.br` e `consulta@contato.tars.art.br`, ambos ativos e
obrigados a trocar a senha no primeiro acesso.

## Proposta de fluxo para a Fase 1

Conteudo sugerido, **pendente de aprovacao**. As perguntas sao de escuta: nenhuma
pede voto, nenhuma promete acao e nenhuma coleta dado sensivel. Isso nao e
preferencia de estilo, e o que as regras da etapa exigem.

### Textos do fluxo

- **Apresentacao:** "Ola! Aqui e da equipe da Professora Norma. Estamos ouvindo
  moradores da regiao para entender o que mais preocupa as pessoas hoje. Posso
  te fazer uma pergunta rapida?"
- **Agradecimento:** "Muito obrigado por compartilhar. Sua resposta foi
  registrada e vai ajudar a equipe a entender melhor as demandas da regiao."
- **Recusa:** "Tudo bem, obrigado pela atencao! Se mudar de ideia, e so
  responder por aqui."
- **Aviso de transparencia:** ja configurado como sufixo — "Esta e uma mensagem
  automatica. Responda para falar com nossa equipe."
- **Maximo de aprofundamentos:** zero, conforme o item 3 do roteiro.

### Perguntas propostas

Peso maior nas de tema aberto, que rendem mais informacao por resposta.

| # | Pergunta | Peso |
| --- | --- | --- |
| 1 | Qual e o principal problema do seu bairro hoje? | 5 |
| 2 | Se voce pudesse resolver uma unica coisa na sua cidade, qual seria? | 5 |
| 3 | O que mais te preocupa no dia a dia da sua familia? | 5 |
| 4 | Como esta a saude publica na sua regiao? | 4 |
| 5 | Como esta a educacao na sua regiao? | 4 |
| 6 | Como esta a seguranca no lugar onde voce mora? | 4 |
| 7 | Voce tem dificuldade para conseguir atendimento medico? Conte como e. | 3 |
| 8 | Como e o transporte publico que voce usa? | 3 |
| 9 | Falta alguma coisa na escola dos seus filhos ou netos? | 3 |
| 10 | Como estao as ruas e calcadas do seu bairro? | 3 |
| 11 | Voce sente falta de espacos de lazer ou esporte por perto? | 3 |
| 12 | O que voce acha do atendimento nos servicos publicos da sua cidade? | 3 |
| 13 | Existe algum problema de agua, esgoto ou lixo onde voce mora? | 3 |
| 14 | Voce ou alguem proximo teve dificuldade para conseguir emprego este ano? | 2 |
| 15 | Como e o acesso a internet na sua regiao? | 2 |
| 16 | Falta algum servico publico perto da sua casa? | 2 |
| 17 | O que funciona bem na sua cidade e merece ser mantido? | 2 |
| 18 | Se voce pudesse dar um recado para quem decide as politicas publicas, qual seria? | 2 |
| 19 | Voce conhece o trabalho da Professora Norma? O que sabe sobre ele? | 1 |
| 20 | Tem alguma coisa que voce gostaria que a equipe soubesse? | 1 |

### Observacoes sobre o conteudo

- A pergunta 19 e a unica que cita a Professora Norma e e formulada como
  pergunta aberta, nao como afirmacao a ser confirmada. Se o objetivo for medir
  conhecimento espontaneo, ela deve ficar; se houver risco de soar como
  divulgacao, o mais seguro e remover.
- Nenhuma pergunta menciona eleicao, voto, candidatura ou numero de urna.
- Nenhuma pergunta pede idade, renda, religiao, orientacao, filiacao partidaria
  ou documento.
- Perguntas 7, 14 e 9 tocam em situacao pessoal. Estao formuladas como convite,
  nao como exigencia, e a pessoa pode responder de forma generica.

## Proximo passo

Aprovar, ajustar ou substituir a lista acima. Com o conteudo definido, a Fase 1
e cadastro pela tela `Fluxos conversacionais`, e da para seguir ate a Fase 2 sem
enviar nada para ninguem: a Fase 2 liga so a avaliacao, com o envio automatico
ainda desligado.
