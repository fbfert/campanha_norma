# Tarefas — Subetapa 9F

## 1. Especificação

- [x] 1.1 Ler README, `CLAUDE.md`, a documentação de análise e governança e a de fórmulas.
- [x] 1.2 Ler a mudança 9E inteira e as specs aprovadas que ela alterou.
- [x] 1.3 Conferir no código, sem supor convenção, os campos do insight, as chaves de configuração, as permissões, as situações de documento e base, o formato das chaves de trilha e o que a sincronização grava.
- [x] 1.4 Criar proposta, design e deltas de spec da subetapa 9F.
- [x] 1.5 Validar com `openspec validate --specs` e `openspec validate --all --json`.

## 2. Prova antecipada, sem tela

- [x] 2.1 Criar comando que gera o caderno de resposta em HTML autocontido, com período e fluxo por opção.
- [x] 2.2 Montar uma página por pessoa, com quebra de página na impressão.
- [x] 2.3 Incluir a frase literal da mensagem de origem, sem paráfrase e sem corte no meio de palavra.
- [x] 2.4 Incluir aviso de baixa confiança abaixo do limiar configurado, mandando conferir a mensagem original.
- [x] 2.5 Ordenar por urgência e, em seguida, por tamanho da resposta.
- [x] 2.6 Escrever cabeçalho com período, fluxo, data, quem gerou e aviso de documento nominal, e rodapé com o aviso de escuta de demanda.
- [x] 2.7 Testar quebra por pessoa, frase literal, aviso de baixa confiança e ordenação.
- [x] 2.8 Apresentar o resultado antes de construir qualquer tela.

## 3. Banco de dados

- [x] 3.1 Acrescentar a `insight_topics` as colunas anuláveis de orientação de resposta e de linha vermelha.
- [x] 3.2 Expor as duas colunas no cadastro de temas existente, com rótulo e texto de ajuda.
- [x] 3.3 Acrescentar a `knowledge_documents` a coluna anulável de tema, com chave estrangeira anulando na exclusão.
- [x] 3.4 Expor o campo de tema no formulário de documento como opcional.
- [x] 3.5 Acrescentar a `conversation_insights` as colunas anuláveis de instante e autor da marcação manual, com índice no instante.
- [x] 3.6 Garantir `down()` seguro e testar retrocesso e reaplicação de cada migration.
- [x] 3.7 Confirmar que o teste de isolamento estrutural da recuperação da 9D continua passando.

## 4. Permissão e configuração

- [x] 4.1 Acrescentar a permissão da pauta nominal ao enum de permissões, com o rótulo em português.
- [x] 4.2 Registrar o gate correspondente.
- [x] 4.3 Atribuir a permissão apenas ao papel administrador.
- [x] 4.4 Exigir no controller da pauta a permissão nominal em conjunto com identificação e conteúdo.
- [x] 4.5 Manter o cruzamento de localidade por tema e a pauta de posicionamento sob a permissão de agregado, sem permissão nova.
- [x] 4.6 Acrescentar as quatro configurações do grupo de pauta, com padrão seguro e descrição.

## 5. Serviços

- [x] 5.1 Criar o serviço de cruzamento de localidade e região por tema, com a assinatura usada pelos serviços de análise existentes.
- [x] 5.2 Aplicar supressão de célula pequena no serviço, mantendo a célula suprimida na tabela e nunca suprimindo zero.
- [x] 5.3 Contar à parte os insights sem localidade declarada, sem distribuir nem somar a outro grupo.
- [x] 5.4 Criar o serviço de pauta de posicionamento, considerando buraco o tema sem documento aprovado em base ativa associada ao fluxo.
- [x] 5.5 Manter o serviço de posicionamento fora da camada de recuperação, sem usar, importar ou referenciar nenhuma classe dela.
- [x] 5.6 Criar o serviço de pauta de resposta com a fila ordenada por pontuação de prioridade lida da configuração.
- [x] 5.7 Montar o dossiê por composição determinística, sem nenhuma chamada de modelo.
- [x] 5.8 Deixar comentado no código por que não há supressão no dossiê.
- [x] 5.9 Testar a ordenação por prioridade e o dossiê montado sem tema atribuído.

## 6. Telas agregadas

- [x] 6.1 Criar a tela de cruzamento de localidade e região por tema, no padrão das telas de análise existentes.
- [x] 6.2 Explicar na tela que cruzar dois eixos derruba o número por célula e que a supressão é a regra funcionando.
- [x] 6.3 Criar a tela de pauta de posicionamento, destacando os temas sem nenhum documento aprovado.
- [x] 6.4 Preservar período e fluxo no endereço, como as telas vizinhas.
- [x] 6.5 Acrescentar as entradas de trilha de navegação com as chaves sem acento, como as vizinhas.
- [x] 6.6 Acrescentar as entradas de menu condicionadas à permissão de agregado.

## 7. Módulo nominal

- [x] 7.1 Criar o controller da pauta em módulo próprio, separado do de análise.
- [x] 7.2 Criar a tela da fila com filtros, contadores e ligação para o dossiê.
- [x] 7.3 Criar a tela do dossiê na ordem de leitura definida no design, com a linha vermelha em destaque forte.
- [x] 7.4 Dizer explicitamente quando o tema não tem linha vermelha escrita.
- [x] 7.5 Implementar a marcação manual gravando instante, autor e registro de auditoria.
- [x] 7.6 Deixar comentado no controller que a marcação não envia, não abre o WhatsApp e não agenda.
- [x] 7.7 Exibir em toda tela do módulo o aviso permanente de documento nominal, com o nome de quem está vendo.
- [x] 7.8 Acrescentar as entradas de trilha e o menu próprio, visível apenas a quem tem a permissão.

## 8. Impressão

- [x] 8.1 Criar o layout de impressão sem menu, sem barra lateral, sem trilha e sem botões.
- [x] 8.2 Acrescentar as regras de mídia impressa na folha de estilo do projeto, usando os tokens existentes.
- [x] 8.3 Impedir quebra de cartão e de linha de tabela no meio.
- [x] 8.4 Acrescentar o botão de impressão no painel e no caderno.
- [x] 8.5 Criar a rota do caderno completo, com um dossiê por página e a mesma permissão da pauta.
- [x] 8.6 Aplicar marca-d'água de origem em cada página do caderno e registrar a geração na auditoria.
- [x] 8.7 Escrever a capa obrigatória do caderno e do painel impresso, com o aviso de escuta de demanda.
- [x] 8.8 Exibir o número de registros ao lado de toda taxa impressa.

## 9. Resposta já enviada

- [x] 9.1 Implementar a detecção por mensagem de saída com mídia posterior ao insight, dentro da janela configurada.
- [x] 9.2 Dar precedência à marcação manual.
- [x] 9.3 Distinguir na fila a origem da marcação, com a data.
- [x] 9.4 Exibir na fila a condição do número pareado, em texto curto.
- [x] 9.5 Não usar a coluna de origem da mensagem, cujo valor padrão não distingue o que veio da sincronização.
- [x] 9.6 Testar detecção pela sincronização, marcação manual, saída sem mídia, saída anterior ao insight e saída fora da janela.

## 10. Testes

- [x] 10.1 Testar que sem a permissão nominal não se abre a fila, o dossiê nem o caderno.
- [x] 10.2 Testar que a permissão nominal sem a de identificação também não abre.
- [x] 10.3 Testar que o papel de consulta abre as telas agregadas e não abre a pauta.
- [x] 10.4 Testar supressão de célula abaixo do mínimo no cruzamento, com a célula mantida na tabela.
- [x] 10.5 Testar que zero nunca é suprimido.
- [x] 10.6 Testar explicitamente que o dossiê individual não sofre supressão.
- [x] 10.7 Testar que gerar o mesmo dossiê duas vezes produz o mesmo texto.
- [x] 10.8 Testar que abrir a pauta, o dossiê ou o caderno não cria nenhum registro de execução de modelo.
- [x] 10.9 Testar que tema com menções e nenhum documento aprovado aparece como buraco.
- [x] 10.10 Testar que documento indexado e não aprovado continua sendo buraco.
- [x] 10.11 Testar que documento aprovado em base inativa continua sendo buraco.
- [x] 10.12 Testar que as sete telas da 9E continuam abrindo com os mesmos números.
- [x] 10.13 Testar que o isolamento estrutural da recuperação da 9D continua valendo.
- [x] 10.14 Varrer as rotas do módulo e falhar se qualquer uma alcançar o provedor de WhatsApp.

## 11. Documentação

- [x] 11.1 Escrever a documentação do painel e da pauta, com telas, permissões e configurações.
- [x] 11.2 Documentar a montagem do dossiê campo a campo e por que não há modelo nessa montagem.
- [x] 11.3 Documentar a regra de detecção de resposta com a condição do número pareado.
- [x] 11.4 Acrescentar à documentação de fórmulas a pontuação de prioridade e a regra do cruzamento.
- [x] 11.5 Atualizar o README com o escopo implementado e o não implementado da subetapa.
- [x] 11.6 Escrever o roteiro de homologação manual, incluindo o tema sem linha vermelha escrita.
- [x] 11.7 Registrar que preencher orientação e linha vermelha por tema é trabalho humano que nenhum passo automatiza.

## 12. Encerramento

- [x] 12.1 Executar a suíte inteira.
- [x] 12.2 Executar o verificador de acentuação e o de padrão de interface.
- [x] 12.3 Executar Pint.
- [x] 12.4 Executar as validações OpenSpec.
- [x] 12.5 Apresentar arquivos alterados, migrations, rotas, riscos e pendências.
