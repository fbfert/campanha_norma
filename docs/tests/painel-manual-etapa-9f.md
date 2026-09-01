# Homologação manual — Subetapa 9F

Roteiro para conferir na mão o que os testes automáticos não olham: se a tela é
legível, se o papel sai inteiro e se o documento nominal avisa o que é.

A suíte automática cobre permissão, supressão, determinismo e a detecção de
resposta. **Não repita isso aqui.** O que segue é o que só uma pessoa vê.

Antes de começar: `php artisan migrate` e `php artisan db:seed --class=SystemSettingSeeder`.

---

## 1. Cidade e tema

1. Abra `/admin/analytics/cidade-tema` com um usuário de consulta.
2. Confira que o texto de abertura explica **por que** tanta célula está
   suprimida. Se a tela mostrar uma tabela cheia de "suprimido" sem essa frase,
   ela está errada mesmo com os números certos.
3. Confira a linha "Sem localidade declarada": ela é contada à parte e não entra
   em nenhuma linha da tabela.
4. Some as colunas visíveis de uma linha e compare com o total da linha. Eles
   **não** vão bater quando houver célula suprimida — e é isso que a marca
   "suprimido" existe para explicar. Se a célula tivesse sumido, a diferença
   pareceria registro faltando.
5. Mude `analytics.minimum_cell_size` para 1 e recarregue: a supressão some.
   Volte para 5 depois.

## 2. Posicionamento

1. Abra `/admin/analytics/posicionamento`.
2. As linhas com zero documentos aprovados aparecem destacadas. Confira que o
   destaque é de aviso, e não de erro: não há nada quebrado.
3. Suba um documento apontando para o tema mais citado, deixe-o **indexado e não
   aprovado**, e recarregue. O tema continua como buraco — indexar não aprova.
4. Aprove o documento. O tema sai da condição de buraco.
5. Desative a base do documento e recarregue. O tema volta a ser buraco.

## 3. Fila da pauta

1. Abra `/admin/pauta` como administrador.
2. O aviso de documento nominal está no topo e **traz o seu nome**.
3. O aviso da condição da detecção está visível: a marcação automática só
   funciona se a resposta sair do mesmo número pareado.
4. Filtre por pendentes. Confira que os contadores do topo continuam mostrando
   o total e as respondidas — quem filtra ainda precisa saber quantas já foram.
5. Confira que o trecho da coluna "O que escreveu" não corta palavra ao meio.
6. Confira que ninguém sumiu da fila por prioridade baixa: a de menor pontuação
   continua lá, no fim.

## 4. Dossiê

1. Abra o dossiê de alguém pela fila, **no celular**.
2. Leia em trinta segundos, na ordem: quem é, o que ela disse, o que ela quer, o
   que a campanha defende, linha vermelha.
3. Confira que a frase entre aspas é literal: compare com a mensagem na conversa,
   pelo botão "Abrir a conversa".

### 4A. O caso que mais importa: tema sem linha vermelha

1. Escolha uma pessoa cujo tema **não** tenha `red_lines` preenchido.
   Se não houver nenhuma, apague o campo de um tema no cadastro de temas.
2. Abra o dossiê dela.
3. **A seção da linha vermelha precisa aparecer mesmo assim**, dizendo que
   ninguém escreveu ainda.
4. Se a seção tiver sumido, o dossiê está errado: ausência calada é lida como
   "não há nada a evitar aqui", que é o contrário do que a ausência significa.
5. Preencha `red_lines` daquele tema e recarregue: o texto aparece em destaque
   forte.

### 4B. Confiança baixa

1. Encontre um insight com `confidence` abaixo de
   `analytics.low_confidence_threshold`.
2. O dossiê avisa, em destaque, para conferir a mensagem original antes de
   responder.

## 5. Marcar como respondida

1. Marque alguém como respondida pelo dossiê.
2. Volte à fila: a linha diz "respondida", com a data, e diz que foi **marcada à
   mão**, com o nome de quem marcou.
3. Confira em `/admin/audit-logs` que ficou o registro
   `response_agenda.marked_answered`.
4. Confira que **nenhuma mensagem saiu**: a conversa daquela pessoa não tem
   mensagem nova.

## 6. Detecção pela sincronização

Precisa do WhatsApp pareado.

1. Escolha alguém pendente na fila.
2. **Do mesmo número pareado**, mande um áudio para essa pessoa.
3. Rode a sincronização de conversas.
4. Recarregue a fila: a linha aparece como respondida e diz **detectada pela
   sincronização**, sem nome de quem marcou.
5. Repita mandando só texto, sem mídia, para outra pessoa: essa continua
   pendente.

> Lembrete: todo envio de teste vai apenas para o telefone cadastrado em
> `whatsapp.test_recipient_phone`. Não use este passo para mandar áudio a um
> eleitor de verdade.

## 7. Impressão e PDF

1. Na fila, clique "Imprimir ou salvar em PDF".
2. Na pré-visualização do navegador, confira:
   - a **capa** traz título, período, fluxo, tamanho da amostra, data, quem gerou
     e a frase de que isto **não é pesquisa eleitoral registrada**;
   - menu, barra lateral, trilha e botões **não** aparecem;
   - cada dossiê começa numa página nova;
   - nenhum cartão e nenhuma linha de tabela parte ao meio;
   - o endereço dos links **não** é impresso ao lado do texto;
   - a marca-d'água com quem gerou e a data aparece em cada página.
3. Salve como PDF e abra o arquivo. O aviso de documento nominal está na capa.
4. Faça o mesmo em `/admin/analytics/cidade-tema`: a capa aparece **só** na
   impressão, e não na tela.

## 8. Caderno pela linha de comando

```bash
php artisan relatorios:caderno --de=2026-08-01 --ate=2026-08-31 \
  --por="Seu nome" --saida=storage/app/private/caderno.html
```

1. Abra o arquivo gerado num navegador **sem conexão**. Ele precisa abrir
   formatado: o estilo é embutido, não carregado de fora.
2. Confira uma página por pessoa e o rodapé com o aviso de escuta de demanda.

## 9. O que não pode acontecer em lugar nenhum

- Nenhuma tela desta subetapa oferece botão de enviar, agendar ou gravar áudio.
- Nenhuma tela agregada mostra nome, telefone ou texto de mensagem.
- Nenhuma tela da pauta aparece no menu de quem não tem as três permissões.
