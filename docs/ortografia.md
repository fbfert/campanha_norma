# Acentuação do português no sistema

Todo texto em português deste repositório usa acentuação correta, e isso é
verificado por teste. Este documento explica a regra, o que ela deliberadamente
não alcança e como mexer no dicionário.

---

## 1. Por que virou regra

O sistema nasceu misturando as duas grafias: telas antigas escreviam `Situação`
e `Ações`, telas novas escreviam `Situacao` e `Acoes`. Não havia problema de
codificação — os arquivos sempre foram UTF-8 e o banco sempre foi `utf8mb4`. Era
só falta de combinação.

Mistura de grafia numa mesma tela lê como descuido, e num sistema que fala com
cidadão isso pesa. Como convenção que ninguém confere apodrece, a regra é
verificada por `tests/Feature/OrtografiaTest.php`.

---

## 2. A regra

**Se um humano lê, acentue. Se o código compara, não toque.**

| Leva acento | Não leva |
| --- | --- |
| rótulo, título, mensagem de erro | chave de `system_settings` |
| texto de tela e de e-mail | slug, `enum` case, valor gravado no banco |
| prompt enviado ao modelo | nome de rota e URL |
| comentário de código e docstring | coluna, classe, método, variável |
| documentação em `docs/` | cabeçalho e valor de CSV do importador |

O caso mais fácil de errar é o mesmo texto nos dois papéis. Em
`case Importacao = 'importacao'`, nada muda: o nome do case é identificador e o
valor vai para o banco. Já o rótulo que sai disso na tela é `Importação`.

---

## 3. Como rodar

```bash
php artisan test --filter=OrtografiaTest
```

A falha lista arquivo, linha, a palavra e a forma correta.

O teste ignora o que é código antes de conferir: blocos de código e trechos entre
crases no Markdown, `namespace`/`use`, variáveis, membros (`->x`), constantes de
classe (`::X`), declarações (`case X`, `function X`, `class X`), atributos HTML
que não são texto e strings que parecem chave, slug, rota ou caminho.

---

## 4. O dicionário

`resources/ortografia/acentuacao-pt-br.json`, com três partes:

| Parte | Para que serve |
| --- | --- |
| `correcoes` | forma errada → forma correta. É a lista que o teste cobra. |
| `permitidas` | palavras que **parecem** erradas e estão certas sem acento: `categoria`, `auditoria`, `arquivo`, `diretoria`, `objetivo`, `governanca` (que aparece em URL). |
| `sufixos_suspeitos` | pega palavra nova que ainda não está no dicionário: `-cao`, `-coes`, `-encia`, `-encias`, `-ancia`, `-ancias`, `-avel`, `-aveis`, `-ivel`, `-iveis`. Em português, quase tudo com esses finais precisa de acento. |

Dois testes protegem o dicionário de si mesmo:

- **consistência** — tirar o acento da forma correta tem de devolver exatamente a
  forma errada. Foi o que pegou `urgencia -> urência` na primeira versão, gerada
  cortando cinco letras em vez de seis;
- **não contradição** — nenhuma palavra pode estar em `correcoes` e em
  `permitidas` ao mesmo tempo.

---

## 5. Quando o teste reclama

1. **Precisa de acento** — escreva com acento. É o caso normal.
2. **Está certa sem acento** — acrescente em `permitidas`, em ordem alfabética.
3. **É deliberado** — marque a linha com `// ortografia:ignorar` e explique.

O caso 3 é raro e tem uma família clara: a **saída esperada de uma função que
remove acento**. `TextNormalizer::normalize('Educação 2026')` devolve
`'educacao 2026'`, e essa string sem acento é o resultado certo. Acentuá-la
quebraria o teste.

```php
$this->assertSame('educacao 2026', app(TextNormalizer::class)->normalize('Educação 2026')); // ortografia:ignorar - saida normalizada nao tem acento
```

O mesmo vale para o CSV de importação em `ContactModuleTest`: `nao_contatar` é
o nome da coluna que o importador espera, não uma palavra escrita errado.

---

## 6. O que a regra não alcança

Três limitações conhecidas, todas por escolha:

**`e` no lugar de `é`.** Espalhado pelo repositório (`a base e ativa` em vez de
`a base é ativa`). Não é falta de acento numa palavra: são duas palavras
diferentes, e distinguir a conjunção do verbo exige ler a frase. Corrigir em
massa corromperia texto. Fica para revisão humana, e o dicionário não tenta.

**Palavras de duas letras.** `ve`/`vê`, `le`/`lê`, `da`/`dá`, `ha`/`há` têm o
mesmo problema e ficam de fora pelo mesmo motivo.

**Palavras ambíguas.** Algumas grafias sem acento são palavras legítimas e por
isso não entram em `correcoes`:

| Palavra | Por quê |
| --- | --- |
| `esta` | pronome (`esta base`) ou verbo (`está ativa`) |
| `valida` / `invalida` | adjetivo (`válida`) ou verbo (`ele valida`) |
| `media` | substantivo (`média`) ou inglês |
| `continua` | adjetivo (`contínua`) ou verbo (`ele continua`) |
| `secretaria` | `secretaria` (órgão) ou `secretária` (pessoa) |
| `nos` | preposição (`nos casos`) ou pronome (`nós`) |
| `marco` | `marco` (referência) ou `março` (mês) |

Se uma dessas aparecer errada numa tela, corrija à mão.

---

## 7. Texto que está no banco

Rótulo gravado em tabela não muda sozinho quando o código muda. Em 30/07/2026 o
texto gravado foi atualizado por UPDATE dirigido, não por seeder, e o que foi
alterado está registrado em `storage/app/private/ortografia-rollback.sql`, que
desfaz exatamente essas linhas.

### Não use os seeders para isso

**`db:seed` não é seguro aqui.** Os três seeders reescrevem mais do que texto:

| Seeder | O que ele também faz |
| --- | --- |
| `SystemSettingSeeder` | `updateOrCreate(['key' => …], $setting)` com `value` incluído: **devolve toda configuração ao padrão**. Ritmo de disparo, janelas, limiares e `knowledge.enabled` voltam ao valor de fábrica. |
| `RolePermissionSeeder` | `$role->permissions()->sync(…)`: **reverte as permissões de cada papel** para o conjunto padrão, descartando ajuste feito na tela de usuários. |
| `InsightTopicSeeder` | força `is_active = true` e sobrescreve `synonyms`, `color` e `display_order`: **reativa tema desligado** e descarta sinônimo editado no admin. |

Na correção de 2026 isso não causou dano porque a base ainda estava idêntica ao
padrão — 27 valores divergiam, todos por acento, e nenhum papel ou tema tinha
ajuste. Foi sorte, não garantia. Se precisar repetir a operação, faça o UPDATE
dirigido: atualize `value` apenas quando as duas formas forem iguais depois de
remover os acentos, o que prova que é a mesma configuração e não uma alteração
de quem opera.

Depois de qualquer alteração em `system_settings`:

```bash
php84 artisan cache:clear
```

`SystemSettingService` cacheia para sempre; sem isso a tela continua mostrando o
valor antigo.

### Por que acentuar esses valores não muda comportamento

Boa parte do que está gravado é lista de expressões que o sistema compara com o
que a pessoa escreve — `ai.expressions.risk`, `ai.response.forbidden.*`,
`knowledge.injection_patterns`, `knowledge.factual_markers` e os sinônimos de
tema. Em todos os consumidores (`SensitiveContentDetector`, `ReplyTextValidator`,
`PromptInjectionSanitizer`, `GroundingValidator`, `InsightTopicMapper`) a
expressão configurada passa pelo mesmo `normalize()` que a mensagem recebida, e
essa normalização remove acento antes de comparar. `denúncia` e `denuncia` casam
igual. Conferido em produção nos dois sentidos depois da atualização.

Os sinônimos de tema (`InsightTopic::synonyms`) passam por
`PermissionResponseClassifier::normalize()` nos dois lados da comparação, que
remove acento antes de casar. Acentuá-los não muda o que a IA classifica.

---

## 8. Documentos relacionados

- `CLAUDE.md` — a regra em versão curta, carregada por agentes a cada sessão.
- `tests/Support/Ortografia.php` — extrator de texto humano e detector.
- `tests/Feature/OrtografiaTest.php` — o teste que cobra.
