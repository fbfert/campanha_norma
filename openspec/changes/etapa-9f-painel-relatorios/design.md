# Design — Subetapa 9F

## Contexto

Volume real no momento do desenho: aproximadamente duzentas pessoas responderam à pesquisa conversacional. Esse número decide quase tudo o que vem abaixo.

Duzentos cabe em atendimento individual. Não há escassez a administrar, e portanto o relatório não precisa priorizar por escassez — precisa **ordenar por relevância** e mostrar o que já foi respondido, para ninguém responder duas vezes nem esquecer alguém. Um sistema desenhado para vinte mil respostas resolveria um problema que esta campanha não tem.

Duzentos também é pouco para cruzamento. Com `analytics.minimum_cell_size` em 5, cruzar localidade por tema derruba o número de registros por célula e boa parte da tabela aparece suprimida. Isso está correto e é declarado na tela: é a regra da 9E funcionando, não falta de dado.

## Decisão 1 — Dois módulos separados, não um

**Escolhido:** o painel agregado permanece em `analytics`; o caderno nominal nasce em um módulo próprio, com controller próprio, rota própria e permissão própria.

**Alternativa descartada:** uma aba a mais dentro do painel analítico, reusando as permissões que já existem.

A razão não é organização de pastas. As duas coisas obedecem a regras **opostas**:

| | Painel agregado | Caderno de resposta |
| --- | --- | --- |
| Natureza | anônimo | nominal |
| Supressão de célula pequena | sim | não se aplica — um registro é o ponto |
| Exportação em massa | sim, agregada | não |
| Permissão | agregado | nominal, mais identificação, mais conteúdo |

Supressão de grupo pequeno de um lado e exposição individual do outro, no mesmo código, é exatamente onde o vazamento nasce: basta alguém confundir qual caminho está editando. E se as duas morarem no mesmo módulo, a garantia inteira da 9E vira decoração, porque quem alcança o caderno passa a ter nome para o que o painel mostra sem dono.

A separação também é o que torna a permissão honesta. A pauta exige **três** permissões simultâneas — a permissão nominal, a de identificação e a de conteúdo — porque o dossiê expõe nome, cidade e o texto que a pessoa escreveu. Três exposições distintas, três permissões.

O cruzamento de localidade por tema e a pauta de posicionamento são agregados e continuam sob a permissão de agregado. Nenhuma permissão nova para eles: permissão que não separa nada só dificulta a administração.

## Decisão 2 — O roteiro é montagem determinística, nunca geração

**Escolhido:** o dossiê é composição de campos já gravados. Nenhuma chamada nova de provedor de inteligência artificial.

A subetapa 9B já extrai, por mensagem, com saída validada por esquema: resumo, tema principal, problema identificado, ação sugerida, resultado desejado, grupo afetado, localidade declarada, urgência, sentimento, palavras-chave e confiança. Isso não é matéria-prima para um roteiro — **isso já é o roteiro**, estruturado.

| Bloco do dossiê | Origem | Custo |
| --- | --- | --- |
| A frase da pessoa, literal | corpo da mensagem de origem | nenhum |
| O que ela levantou e o que quer | campos do insight da 9B | nenhum |
| O que a candidata já defende | documento aprovado da 9D, pelo tema | nenhum |
| O que não prometer | coluna do tema, escrita por uma pessoa | trabalho humano |

Quatro consequências, e todas importam:

1. **Sem custo por pessoa e sem latência.** Abrir um dossiê é uma consulta, não uma chamada paga.
2. **Sem frase inventada.** Um modelo que parafraseia introduz uma afirmação que ninguém escreveu, dentro de um documento que será lido como se fosse o que a pessoa disse.
3. **Citação literal é mais forte.** A candidata lê o que o eleitor escreveu de verdade. Nenhuma paráfrase melhora isso.
4. **É verificável.** Gerar o mesmo dossiê duas vezes produz o mesmo texto, e existe teste afirmando que abrir a pauta não cria nenhum registro de execução de modelo. Esse teste é o que impede a 9F de passar a gerar texto sem ninguém perceber.

Se o roteiro parecer seco depois de usado de verdade, existe um caminho: uma camada opcional de polimento pela 9C, que já tem validador determinístico barrando promessa, pedido de voto, urgência artificial e intimidade simulada. **Não faz parte desta subetapa.**

## Decisão 3 — Nenhuma inteligência artificial escreve o que a candidata vai dizer

Consequência direta da decisão anterior, e vale registrar separado porque o motivo é outro.

Promessa dita pela própria candidata, na voz dela, é pior que promessa de sistema em texto: não existe "foi o sistema". Não há retratação possível de um áudio já enviado.

Por isso o dossiê tem uma seção fixa de **linha vermelha** por tema, escrita por uma pessoa, exibida em destaque forte na tela e no papel. Ela é o único bloco do dossiê que o sistema não sabe preencher sozinho, e é o bloco que protege quem fala.

Quando o tema não tem linha vermelha escrita, o dossiê sai assim mesmo e **diz que não tem**. Seção ausente em silêncio seria lida como "não há nada a evitar aqui", que é o oposto do que a ausência significa.

## Decisão 4 — Linha vermelha e orientação moram no tema

**Escolhido:** duas colunas de texto anuláveis em `insight_topics`.

**Alternativa descartada:** tabela própria de orientação, com cadastro próprio.

A informação pertence ao tema: é sobre saúde, sobre transporte, sobre creche que se define o que dizer e o que não prometer. Morando no tema, ela aparece no cadastro de temas que já existe, não exige tela nova, e desaparece naturalmente quando o tema é desativado. Tabela nova custaria migration, model, controller, formulário e permissão para guardar dois campos de texto.

## Decisão 5 — O documento aprovado aponta para o tema, e a recuperação não sabe disso

**Escolhido:** uma coluna anulável em `knowledge_documents` referenciando o tema, usada exclusivamente pelo serviço de pauta de posicionamento.

A 9D tem uma trava estrutural: um teste lê o código-fonte da camada de recuperação, com os comentários removidos, e falha se as palavras `conversation_insights`, `conversation_messages`, `contacts` ou `conversations` aparecerem ali. A razão é que a opinião da população nunca pode virar fonte de resposta individual — o que o eleitor disse não pode ser recuperado como se fosse posição oficial da campanha.

O cruzamento entre tema e documento aprovado é feito **fora** dessa camada, em serviço novo, que consulta as tabelas de conhecimento e as de insight diretamente. A camada de recuperação não é lida, não é importada e não é referenciada. A trava continua valendo, e o teste continua passando sem alteração.

A pauta considera buraco o tema que não tem documento **aprovado** em base **ativa**. As duas condições são deliberadas: indexar não aprova — a separação entre pronto e aprovado já existe na 9D e significa que alguém decidiu que aquilo pode ser dito —, e documento aprovado em base desligada não responde a ninguém.

## Decisão 6 — Ordenar por relevância, não por escassez

A pontuação de prioridade combina três sinais, com pesos em configuração e não no código: urgência declarada no insight, tamanho da resposta que a pessoa escreveu e o tema ser emergente.

O tamanho da resposta entra porque quem escreveu muito investiu mais na conversa, e uma resposta longa ignorada custa mais que uma resposta curta ignorada. O tema emergente entra porque é onde a campanha ainda não formou posição, e responder cedo é mais barato que corrigir depois.

Os pesos são configuração porque nenhum deles foi calibrado com dado real. Cravá-los no código transformaria três chutes em regra permanente.

Pontuação **ordena**, não classifica: toda pessoa da fila é para responder, e a fila só decide quem vem antes. Nada é descartado por prioridade baixa.

## Decisão 7 — Resposta já enviada, detectada pelo que a sincronização já grava

**Escolhido:** um insight é considerado respondido quando existe, na mesma conversa, mensagem de saída com mídia, posterior à criação do insight e dentro da janela configurada. A marcação manual também conta, e tem precedência.

O serviço de sincronização de conversas já grava mensagens de saída vindas do WhatsApp Web, com o indicador de mídia, o tipo e o instante de envio. Se a candidata gravar o áudio na mesma conta pareada ao sistema, o áudio aparece na próxima sincronização e a fila se marca sozinha. Nenhum botão, nenhuma disciplina exigida dela — e disciplina é o que não sobrevive à terceira semana de campanha.

**Condição declarada:** se ela usar outro número, isso não funciona. Por isso a marcação manual existe de qualquer forma, como reserva, e por isso o aviso da condição vai **na tela da fila**, não apenas na documentação. Uma condição que só o manual conhece é uma condição que ninguém conhece.

A fila mostra qual das duas origens marcou cada linha, com a data. Origem diferente é confiança diferente: a marcação manual afirma que alguém respondeu; a detecção afirma que saiu um áudio naquela conversa, o que é evidência forte e não prova.

**Proibição:** a detecção não pode usar a coluna de origem da mensagem. Essa coluna tem valor padrão `manual` e o serviço de sincronização não a preenche ao criar a mensagem, de modo que uma mensagem sincronizada do WhatsApp Web fica gravada como `manual`. Filtrar por origem `sync` pareceria mais preciso e não casaria com nada, em silêncio. A regra usa direção, presença de mídia e instante de envio, que são os campos efetivamente escritos.

Nada disso é trabalho de fila ou de agendamento. É consulta sobre o que já está gravado.

## Decisão 8 — PDF pelo navegador, sem dependência nova

**Escolhido:** layout de impressão em Blade, regras de mídia impressa na folha de estilo do projeto usando os tokens que já existem, e um botão que chama a impressão do navegador.

**Alternativa descartada:** biblioteca de geração de PDF no servidor, ou biblioteca de gráficos no cliente.

A 9E recusou biblioteca de gráficos de propósito, e a regra de interface do projeto proíbe carregar qualquer coisa de rede externa: o sistema roda em servidor próprio e precisa abrir com internet ruim. Manter a coerência custa pouco aqui, porque o que se imprime é tabela e cartão de texto.

Duas regras acompanham a saída impressa:

- **Capa obrigatória**, com título, período, fluxo, tamanho da amostra, data de geração, quem gerou e o aviso de que o material é escuta de demanda e **não é pesquisa eleitoral registrada**. Esse aviso vai na capa, não em rodapé: rodapé de página impressa não é lido, e um documento com números sobre opinião da população que circula sem essa frase é lido como pesquisa.
- **Toda taxa impressa mostra o número de registros ao lado.** Percentual sem denominador visível esconde o tamanho da amostra, e num documento impresso, que circula sem os filtros da tela, isso vira manchete interna. A 9E já exige denominador visível; no papel a exigência é maior, porque não há como voltar e conferir.

O caderno leva ainda **marca-d'água discreta** em cada página, com quem gerou e a data, e a geração fica registrada na auditoria. Documento nominal que vaza deve ter origem — pelo mesmo motivo que a exportação detalhada da 9E carrega sal próprio.

## Decisão 9 — Somente leitura, e verificado como tal

Nenhuma tela da 9F envia mensagem, agenda envio, grava áudio ou liga automação. O contrato de provedor de WhatsApp não é alterado, o serviço Node não é tocado, e nenhuma fila nova é criada.

O único verbo de escrita do módulo nominal grava a marcação de respondida e o registro de auditoria. Ele não envia nada, não abre o WhatsApp e não agenda.

Isso é afirmado por teste: uma varredura sobre as rotas do módulo falha se qualquer uma delas alcançar o provedor de WhatsApp. Restrição declarada em prosa é convenção, e convenção não impede nada.
