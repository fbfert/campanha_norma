# Limpeza

Sistema › Limpeza remove a participação de uma pessoa nas funções que o sistema
já executou com ela: inscrição em campanha, conversa, presença em lote de envio,
etiqueta, histórico, vínculo com a planilha importada.

O cadastro do contato **não** é apagado aqui. Para isso existe a tela de
Contatos, que já pergunta as coisas certas antes de fazer isso. A Limpeza trata
do que a pessoa participou, não de quem ela é.

---

## O que a tela faz

```text
Sistema › Limpeza
  → procura o contato por nome ou telefone
  → CleanupService::inventario() lista um item por participação
       "Campanha X"  ·  "Conversa #41"  ·  "Lote Y"  ·  "Etiquetas"
  → o operador marca o que sai (ou usa "limpar tudo")
  → confirma digitando o telefone e escrevendo o motivo
  → CleanupService::limpar() marca as linhas como excluídas
       e grava uma CleanupOperation com um CleanupItem por participação
  → Sistema › Limpeza › Lixeira restaura enquanto o prazo não vence
  → cleanup:purge-expired apaga em definitivo o que passou do prazo
```

## As três decisões que explicam o resto

### A remoção é suave, mas o efeito é imediato

O que sai vai para a lixeira com `deleted_at` e volta inteiro enquanto o prazo
não venceu. Só que, para todo o resto do sistema, sumiu no instante em que saiu:
painel da pesquisa, relatórios, contagem de lote e lista de sorteio param de
enxergar na mesma hora, porque quem os filtra é o escopo global do Eloquent — e
não uma cláusula que alguém precise lembrar de escrever em cada consulta.

Onde isso não vale sozinho é na consulta crua. `DB::table(...)` não passa pelo
escopo, e por isso `GovernanceReportService` e `MergeEchoedOutgoingMessagesCommand`
ganharam `whereNull('deleted_at')` explícito. Consulta crua nova sobre tabela
com lixeira precisa do mesmo cuidado.

### `cleanup_trash_key`, e por que não bastava `deleted_at`

Marcar a linha como excluída não a tira do índice único. Uma inscrição limpa
continuaria ocupando `(campanha, contato)`, e a pessoa nunca mais conseguiria se
inscrever naquela campanha — o `INSERT` bateria contra uma linha que a consulta
não enxerga e o banco enxerga.

A saída óbvia, acrescentar `deleted_at` ao índice, **não funciona**, e falha de
um jeito pior do que não fazer nada: em MySQL e em SQLite, NULL conta como valor
distinto dentro de índice único. Como toda linha viva tem `deleted_at` nulo,
duas linhas vivas iguais passariam a ser aceitas — o índice continuaria
existindo e pararia de garantir o que existe para garantir. Isso foi tentado
durante a implementação e a suíte acusou na hora.

`cleanup_trash_key` resolve sem NULL nenhum: vale `0` na linha viva e passa a
valer o próprio id quando ela vai para a lixeira.

Quem mantém a coluna é `App\Support\MantemChaveDeLixeira`, e ele cobre os dois
caminhos de exclusão porque cobrir um só faria a garantia valer conforme o jeito
de chamar:

- `$modelo->delete()` — evento `deleted` dispara um `UPDATE` próprio, já que
  `runSoftDelete` grava apenas `deleted_at` e `updated_at` e descarta qualquer
  outro atributo sujo;
- `Modelo::where(...)->delete()` — não carrega modelo nenhum e não dispara
  evento; esse caminho é coberto por `App\Support\ConstrutorComChaveDeLixeira`.

### Limpar conversa exige limpar a inscrição que nasceu dela

Inscrição em campanha é projeção da mensagem que a originou, e a mensagem tem
cascata no banco. Quem limpasse só a conversa perderia a inscrição junto — no
expurgo, semanas depois, em silêncio. A Limpeza recusa a combinação e diz quais
inscrições precisam ser marcadas junto. É trava em código, não aviso em tela.

---

## Prazo e expurgo

O prazo sai de `retention.cleanup_trash_days`, ajustável em Configurações, com
30 dias de padrão. `cleanup:purge-expired` roda diariamente às 03:30 e apaga em
definitivo o que venceu. Depois daqui não há volta — é o que dá sentido ao
prazo.

A restauração é recusada assim que o prazo vence, sem esperar o expurgo passar:
entre o vencimento e a próxima rodada há uma janela em que a linha ainda está
lá, e aceitar restaurar nela faria o prazo valer para o agendador e não para
quem opera.

## Permissões

| Permissão | O que abre |
| --- | --- |
| `cleanup.view` | ver a tela, o inventário de um contato e a lixeira |
| `cleanup.execute` | executar a limpeza |
| `cleanup.restore` | restaurar da lixeira |

São três e não uma porque ver o que existe, mandar embora e trazer de volta são
decisões de peso diferente: quem apura um pedido de remoção precisa da primeira
sem precisar das outras duas. Nenhuma delas está no papel de operador.

## O que a Limpeza não faz

- **não apaga o cadastro do contato** — isso é da tela de Contatos;
- **não impede a pessoa de participar de novo** — se ela responder a uma
  palavra-chave ou mandar mensagem outra vez, entra normalmente. A Limpeza trata
  do passado;
- **não alcança número sem contato cadastrado** — o que nunca virou contato não
  tem participação para remover aqui;
- **não apaga a linha da planilha importada** — sai apenas o vínculo dela com
  esta pessoa, senão a importação passaria a mentir sobre quantas linhas tinha;
- **não desfaz o envio** — limpar a presença num lote apaga o registro do envio,
  não a mensagem que já chegou ao aparelho da pessoa.

## Inscrição já sorteada

A Limpeza permite, com confirmação a mais. Remover quem foi sorteado reescreve
um resultado já apurado e possivelmente divulgado, e permitir em silêncio faria
isso custar exatamente o mesmo que remover um inscrito qualquer. A tela mostra
qual sorteio foi, e a confirmação extra é obrigatória.

Vale lembrar do efeito colateral: campanha com lista congelada passa a acusar
divergência na conferência de hash depois de uma limpeza. Isso é correto — a
lista mudou.

## Onde está

| Peça | Arquivo |
| --- | --- |
| Regra | `app/Services/Cleanup/CleanupService.php` |
| Telas | `app/Http/Controllers/Admin/Cleanup/CleanupController.php` |
| Alvos | `app/Enums/CleanupTarget.php` |
| Lixeira | `app/Models/CleanupOperation.php`, `app/Models/CleanupItem.php` |
| Chave do índice | `app/Support/MantemChaveDeLixeira.php`, `app/Support/ConstrutorComChaveDeLixeira.php` |
| Expurgo | `app/Console/Commands/CleanupPurgeExpiredCommand.php` |
| Esquema | `database/migrations/2026_08_19_000100_create_cleanup_tables.php` |
| Testes | `tests/Feature/LimpezaDeParticipacoesTest.php` |

## Nota para quem for mexer no esquema

No MySQL, `kcc_participation_unq` e `cfs_conversation_uniq` eram os únicos
índices que sustentavam suas chaves estrangeiras. Derrubar um índice nessa
condição devolve o erro 1553 e mata a migração no meio, com metade das tabelas
alteradas. O SQLite dos testes não tem essa restrição e aprovaria a ordem errada
sem reclamar.

Por isso a migração cria o índice novo **antes** de derrubar o antigo: com a
mesma coluna à esquerda, o novo passa a servir a chave estrangeira e o antigo
sai sem drama. É também por isso que o índice novo tem nome próprio em vez de
reaproveitar o antigo — dois índices não dividem um nome.
