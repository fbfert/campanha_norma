## Why

A subetapa 9E resolveu o dado. Sete telas, oito serviços de métrica, supressão de célula pequena, exportação com pseudônimo por sal e documentação de fórmulas com numerador, denominador e exclusões. O que foi coletado virou leitura agregada.

O que não existe é a camada de **documento**. Hoje o relatório é uma tela filtrada por parâmetro na URL e um arquivo CSV. Tela não se entrega a uma candidata, e CSV não é relatório: é insumo. Falta o artefato datado, com capa, período declarado e destinatário — o que se imprime, se lê no papel e se guarda.

Falta também o caminho de volta. Aproximadamente duzentas pessoas responderam à pesquisa, e esse volume cabe em atendimento individual: lido o resumo do que a pessoa escreveu, uma resposta gravada à mão leva cerca de dois minutos. São sete horas de trabalho distribuídas em trinta dias. O sistema sabe o que cada pessoa levantou e não oferece nenhuma tela que ajude alguém a responder a ela.

E falta a pergunta que o painel agregado não responde: **sobre o que a campanha ainda não tem posição escrita.** A 9D guarda os documentos oficiais aprovados; a 9B guarda os temas que a população citou. Ninguém cruzou os dois, e o cruzamento é justamente a pauta.

A 9F fecha as três lacunas sem enviar nada. Ela é **somente leitura**: nenhuma tela desta subetapa envia mensagem, agenda envio, grava áudio ou liga automação.

## What Changes

- Adicionar cruzamento de localidade declarada e região por tema principal, com supressão de célula pequena aplicada no serviço, células suprimidas mantidas na tabela e insights sem localidade declarada contados à parte.
- Adicionar pauta de posicionamento: temas com menções no período que não possuem nenhum documento aprovado, em base ativa associada ao fluxo, apontando para aquele tema.
- Adicionar caderno de resposta: um dossiê nominal por pessoa, com a frase literal da mensagem de origem, os campos que a interpretação já extraiu, a orientação escrita do tema e a linha vermelha do tema.
- Adicionar fila de resposta ordenada por pontuação de prioridade configurável, com estado pendente ou respondida e filtros por tema, cidade e estado.
- Adicionar duas colunas de texto a `insight_topics`: orientação de resposta e linha vermelha, editáveis no cadastro de temas que já existe.
- Adicionar coluna opcional em `knowledge_documents` ligando um documento aprovado ao tema que ele responde, usada apenas pela pauta de posicionamento e nunca pela recuperação.
- Adicionar marcação de resposta já enviada, por detecção sobre o que a sincronização já grava e por marcação manual como reserva, distinguindo as duas origens na tela.
- Adicionar uma permissão, para a pauta nominal, exigida em conjunto com as permissões de identificação e de conteúdo já existentes.
- Adicionar quatro configurações no grupo de pauta: três pesos de ordenação e a janela de dias considerada na detecção.
- Adicionar layout de impressão e regras de mídia impressa, com capa obrigatória, marca-d'água de origem no caderno e saída em PDF pelo próprio navegador.
- Adicionar documentação da montagem do dossiê campo a campo, da fórmula de prioridade e da regra de detecção com sua condição.

## Impact

- Affected specs: `response-agenda` (nova), `analytics-governance`, `admin-foundation`, `history-compliance`, `project-foundation`
- Affected code: migrations de colunas em temas, documentos e insights; três serviços novos em análise; controllers e rotas do painel e do módulo nominal; views, layout de impressão e regras de impressão; enum de permissões, gate e seeders; entradas de trilha de navegação; testes e documentação.
- Não afetado: o serviço Node.js `whatsapp-service/` permanece inalterado; o contrato `App\Contracts\WhatsAppProvider` não é tocado; a camada de recuperação da 9D não é tocada nem referenciada; as sete telas da 9E continuam com os mesmos números; nenhuma chamada nova de provedor de inteligência artificial é feita.
- Alteração de comportamento existente: nenhuma. A 9F acrescenta telas, colunas anuláveis e configurações com padrão seguro. Toda coluna nova é anulável, e o dossiê de um tema sem orientação escrita continua saindo, apenas sem aquela seção.
- Compatibilidade: a 9F é somente leitura sobre dados já gravados. Com a interpretação desligada e sem nenhum insight, as telas abrem e declaram a ausência de dados, nunca erro.
- Segurança e proteção de dados: o painel agregado e o caderno nominal são módulos separados, com permissões separadas, porque as regras de exposição são opostas — supressão de grupo pequeno de um lado, exposição individual do outro. O caderno leva aviso permanente de documento nominal, marca-d'água com quem gerou e registro de geração na auditoria.
