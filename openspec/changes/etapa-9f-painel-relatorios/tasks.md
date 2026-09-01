# Tarefas — Subetapa 9F

## 1. Especificação

- [x] 1.1 Ler README, `CLAUDE.md`, a documentação de análise e governança e a de fórmulas.
- [x] 1.2 Ler a mudança 9E inteira e as specs aprovadas que ela alterou.
- [x] 1.3 Conferir no código, sem supor convenção, os campos do insight, as chaves de configuração, as permissões, as situações de documento e base, o formato das chaves de trilha e o que a sincronização grava.
- [x] 1.4 Criar proposta, design e deltas de spec da subetapa 9F.
- [x] 1.5 Validar com `openspec validate --specs` e `openspec validate --all --json`.

## 2. Prova antecipada, sem tela

- [ ] 2.1 Criar comando que gera o caderno de resposta em HTML autocontido, com período e fluxo por opção.
- [ ] 2.2 Montar uma página por pessoa, com quebra de página na impressão.
- [ ] 2.3 Incluir a frase literal da mensagem de origem, sem paráfrase e sem corte no meio de palavra.
- [ ] 2.4 Incluir aviso de baixa confiança abaixo do limiar configurado, mandando conferir a mensagem original.
- [ ] 2.5 Ordenar por urgência e, em seguida, por tamanho da resposta.
- [ ] 2.6 Escrever cabeçalho com período, fluxo, data, quem gerou e aviso de documento nominal, e rodapé com o aviso de escuta de demanda.
- [ ] 2.7 Testar quebra por pessoa, frase literal, aviso de baixa confiança e ordenação.
- [ ] 2.8 Apresentar o resultado antes de construir qualquer tela.

## 3. Banco de dados

- [ ] 3.1 Acrescentar a `insight_topics` as colunas anuláveis de orientação de resposta e de linha vermelha.
- [ ] 3.2 Expor as duas colunas no cadastro de temas existente, com rótulo e texto de ajuda.
- [ ] 3.3 Acrescentar a `knowledge_documents` a coluna anulável de tema, com chave estrangeira anulando na exclusão.
- [ ] 3.4 Expor o campo de tema no formulário de documento como opcional.
- [ ] 3.5 Acrescentar a `conversation_insights` as colunas anuláveis de instante e autor da marcação manual, com índice no instante.
- [ ] 3.6 Garantir `down()` seguro e testar retrocesso e reaplicação de cada migration.
- [ ] 3.7 Confirmar que o teste de isolamento estrutural da recuperação da 9D continua passando.

## 4. Permissão e configuração

- [ ] 4.1 Acrescentar a permissão da pauta nominal ao enum de permissões, com o rótulo em português.
- [ ] 4.2 Registrar o gate correspondente.
- [ ] 4.3 Atribuir a permissão apenas ao papel administrador.
- [ ] 4.4 Exigir no controller da pauta a permissão nominal em conjunto com identificação e conteúdo.
- [ ] 4.5 Manter o cruzamento de localidade por tema e a pauta de posicionamento sob a permissão de agregado, sem permissão nova.
- [ ] 4.6 Acrescentar as quatro configurações do grupo de pauta, com padrão seguro e descrição.

## 5. Serviços

- [ ] 5.1 Criar o serviço de cruzamento de localidade e região por tema, com a assinatura usada pelos serviços de análise existentes.
- [ ] 5.2 Aplicar supressão de célula pequena no serviço, mantendo a célula suprimida na tabela e nunca suprimindo zero.
- [ ] 5.3 Contar à parte os insights sem localidade declarada, sem distribuir nem somar a outro grupo.
- [ ] 5.4 Criar o serviço de pauta de posicionamento, considerando buraco o tema sem documento aprovado em base ativa associada ao fluxo.
- [ ] 5.5 Manter o serviço de posicionamento fora da camada de recuperação, sem usar, importar ou referenciar nenhuma classe dela.
- [ ] 5.6 Criar o serviço de pauta de resposta com a fila ordenada por pontuação de prioridade lida da configuração.
- [ ] 5.7 Montar o dossiê por composição determinística, sem nenhuma chamada de modelo.
- [ ] 5.8 Deixar comentado no código por que não há supressão no dossiê.
- [ ] 5.9 Testar a ordenação por prioridade e o dossiê montado sem tema atribuído.

## 6. Telas agregadas

- [ ] 6.1 Criar a tela de cruzamento de localidade e região por tema, no padrão das telas de análise existentes.
- [ ] 6.2 Explicar na tela que cruzar dois eixos derruba o número por célula e que a supressão é a regra funcionando.
- [ ] 6.3 Criar a tela de pauta de posicionamento, destacando os temas sem nenhum documento aprovado.
- [ ] 6.4 Preservar período e fluxo no endereço, como as telas vizinhas.
- [ ] 6.5 Acrescentar as entradas de trilha de navegação com as chaves sem acento, como as vizinhas.
- [ ] 6.6 Acrescentar as entradas de menu condicionadas à permissão de agregado.

## 7. Módulo nominal

- [ ] 7.1 Criar o controller da pauta em módulo próprio, separado do de análise.
- [ ] 7.2 Criar a tela da fila com filtros, contadores e ligação para o dossiê.
- [ ] 7.3 Criar a tela do dossiê na ordem de leitura definida no design, com a linha vermelha em destaque forte.
- [ ] 7.4 Dizer explicitamente quando o tema não tem linha vermelha escrita.
- [ ] 7.5 Implementar a marcação manual gravando instante, autor e registro de auditoria.
- [ ] 7.6 Deixar comentado no controller que a marcação não envia, não abre o WhatsApp e não agenda.
- [ ] 7.7 Exibir em toda tela do módulo o aviso permanente de documento nominal, com o nome de quem está vendo.
- [ ] 7.8 Acrescentar as entradas de trilha e o menu próprio, visível apenas a quem tem a permissão.

## 8. Impressão

- [ ] 8.1 Criar o layout de impressão sem menu, sem barra lateral, sem trilha e sem botões.
- [ ] 8.2 Acrescentar as regras de mídia impressa na folha de estilo do projeto, usando os tokens existentes.
- [ ] 8.3 Impedir quebra de cartão e de linha de tabela no meio.
- [ ] 8.4 Acrescentar o botão de impressão no painel e no caderno.
- [ ] 8.5 Criar a rota do caderno completo, com um dossiê por página e a mesma permissão da pauta.
- [ ] 8.6 Aplicar marca-d'água de origem em cada página do caderno e registrar a geração na auditoria.
- [ ] 8.7 Escrever a capa obrigatória do caderno e do painel impresso, com o aviso de escuta de demanda.
- [ ] 8.8 Exibir o número de registros ao lado de toda taxa impressa.

## 9. Resposta já enviada

- [ ] 9.1 Implementar a detecção por mensagem de saída com mídia posterior ao insight, dentro da janela configurada.
- [ ] 9.2 Dar precedência à marcação manual.
- [ ] 9.3 Distinguir na fila a origem da marcação, com a data.
- [ ] 9.4 Exibir na fila a condição do número pareado, em texto curto.
- [ ] 9.5 Não usar a coluna de origem da mensagem, cujo valor padrão não distingue o que veio da sincronização.
- [ ] 9.6 Testar detecção pela sincronização, marcação manual, saída sem mídia, saída anterior ao insight e saída fora da janela.

## 10. Testes

- [ ] 10.1 Testar que sem a permissão nominal não se abre a fila, o dossiê nem o caderno.
- [ ] 10.2 Testar que a permissão nominal sem a de identificação também não abre.
- [ ] 10.3 Testar que o papel de consulta abre as telas agregadas e não abre a pauta.
- [ ] 10.4 Testar supressão de célula abaixo do mínimo no cruzamento, com a célula mantida na tabela.
- [ ] 10.5 Testar que zero nunca é suprimido.
- [ ] 10.6 Testar explicitamente que o dossiê individual não sofre supressão.
- [ ] 10.7 Testar que gerar o mesmo dossiê duas vezes produz o mesmo texto.
- [ ] 10.8 Testar que abrir a pauta, o dossiê ou o caderno não cria nenhum registro de execução de modelo.
- [ ] 10.9 Testar que tema com menções e nenhum documento aprovado aparece como buraco.
- [ ] 10.10 Testar que documento indexado e não aprovado continua sendo buraco.
- [ ] 10.11 Testar que documento aprovado em base inativa continua sendo buraco.
- [ ] 10.12 Testar que as sete telas da 9E continuam abrindo com os mesmos números.
- [ ] 10.13 Testar que o isolamento estrutural da recuperação da 9D continua valendo.
- [ ] 10.14 Varrer as rotas do módulo e falhar se qualquer uma alcançar o provedor de WhatsApp.

## 11. Documentação

- [ ] 11.1 Escrever a documentação do painel e da pauta, com telas, permissões e configurações.
- [ ] 11.2 Documentar a montagem do dossiê campo a campo e por que não há modelo nessa montagem.
- [ ] 11.3 Documentar a regra de detecção de resposta com a condição do número pareado.
- [ ] 11.4 Acrescentar à documentação de fórmulas a pontuação de prioridade e a regra do cruzamento.
- [ ] 11.5 Atualizar o README com o escopo implementado e o não implementado da subetapa.
- [ ] 11.6 Escrever o roteiro de homologação manual, incluindo o tema sem linha vermelha escrita.
- [ ] 11.7 Registrar que preencher orientação e linha vermelha por tema é trabalho humano que nenhum passo automatiza.

## 12. Encerramento

- [ ] 12.1 Executar a suíte inteira.
- [ ] 12.2 Executar o verificador de acentuação e o de padrão de interface.
- [ ] 12.3 Executar Pint.
- [ ] 12.4 Executar as validações OpenSpec.
- [ ] 12.5 Apresentar arquivos alterados, migrations, rotas, riscos e pendências.
