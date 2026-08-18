# Padrões de interface

Três coisas precisam continuar valendo em toda tela nova: **o visual segue um só
padrão**, **o português está acentuado** e **o caminho de migalhas leva a algum
lugar**.

Nenhuma delas depende de alguém lembrar. Cada uma tem um teste que falha
apontando arquivo e linha:

```bash
php artisan test --filter=PadraoDeInterfaceTest   # visual e migalhas
php artisan test --filter=OrtografiaTest          # acentuação
```

As regras abaixo existem porque cada uma delas já foi quebrada aqui — e nos três
casos a falha era silenciosa. Nada travava, nada aparecia no log; a tela apenas
ficava um pouco pior, e assim continuava.

---

## 1. Visual

### A cor nasce em `:root`

Toda cor do sistema é um token declarado em `resources/css/app.css`, dentro de
`:root`. Fora dali não existe hexadecimal nenhum — nem na folha de estilo, nem
dentro de uma tela.

```blade
{{-- não --}}
<span style="background:#4f46e5;color:#fff;">Gerado por IA</span>

{{-- sim --}}
<span style="background:var(--ai-mark);color:var(--text-inverse);">Gerado por IA</span>
```

**Por quê.** Antes eram vinte e poucos hexadecimais espalhados pelo arquivo, cada
um escolhido no momento em que a tela foi escrita. Mudar o azul da marca exigia
procurar todas as ocorrências e torcer para não esquecer nenhuma — e a troca de
paleta encontrou cores frias sobrando em cinco telas.

**Única exceção:** campos em que a cor é o próprio dado — o seletor de cor de
etiqueta e de tema. Ali o hexadecimal é valor inicial ou exemplo, não estilo.
O teste reconhece isso por `name="color"` e `placeholder="#"`.

### As telas usam classes semânticas

`card`, `btn`, `muted`, `actions`, `table-wrap`. O Tailwind está instalado, mas
as telas **não** usam classes utilitárias. Trocar sessenta telas por utilitárias
seria uma reescrita grande, arriscada e sem ganho visível para quem usa.

A única exceção é a paginação, que vem do Laravel escrita em utilitárias e vive
em `resources/views/vendor/pagination`.

### Nada de `<style>` dentro de uma tela

Estilo mora na folha. Um bloco `<style>` numa view escapa dos tokens e do build,
e some do alcance de qualquer ajuste global.

### Nada carregado de fora

Sem CDN, sem fonte externa, sem biblioteca remota. O sistema roda em servidor
próprio e precisa abrir com a internet ruim. Os ícones são um sprite SVG
embutido (`components/layouts/partials/icons.blade.php`), desenhado uma vez por
página e referenciado por `<use>`.

Ícone novo entra no sprite antes de ser usado — `<use>` apontando para um id que
não existe não desenha nada e não gera erro em lugar nenhum.

### A tela cabe no celular

Abaixo de 860px a barra de navegação vira uma gaveta, aberta por um botão
"Menu" no topo e fechada ao tocar fora. A gaveta é feita com **caixa de
seleção**, não com JavaScript, pelo mesmo motivo que não há CDN aqui: o sistema
precisa abrir com a internet ruim, e menu que depende de script é menu que às
vezes não abre.

Três regras que vieram de defeitos reais:

- **Filho de `.content` recebe `min-width: 0`.** O padrão de `min-width` num
  item de grid é `auto`, então ele não encolhe abaixo do próprio conteúdo — um
  card com tabela larga dentro empurrava a página inteira e fazia o documento
  rolar de lado. Em qualquer largura, não só nas pequenas: eram 1342px de
  rolagem numa janela de 1280.
- **Conteúdo largo rola dentro do próprio quadro.** Tabela vai em
  `.table-wrap`, que tem `overflow-x: auto`; no celular a tabela ganha
  `min-width` para rolar de lado em vez de espremer as colunas até uma letra
  por linha.
- **Campo de texto não mora em célula de tabela.** Ele estica a coluna e, com
  ela, a tabela inteira. Vai atrás de um `<details>`, como em
  `.row-actions`.

Ao criar tela nova, confira nas duas larguras. O que se mede é simples: o
documento não pode rolar na horizontal.

---

## 2. Acentuação

A regra completa está no `CLAUDE.md`, na raiz. Em resumo:

- **se um humano lê, acentue** — rótulo, mensagem, comentário, documentação,
  mensagem de commit;
- **se o código compara, não toque** — chave de configuração, slug, `enum`,
  nome de rota, coluna, cabeçalho de CSV lido pelo importador.

Quando o teste apontar uma palavra que está certa sem acento, acrescente-a em
`permitidas` no dicionário (`resources/ortografia/acentuacao-pt-br.json`).
Quando for caso deliberado — um slug ou um cabeçalho de CSV dentro de um teste —
marque a linha com `// ortografia:ignorar` e diga o porquê.

---

## 3. Caminho de migalhas

Cada tela declara a trilha como texto:

```blade
<x-layouts.app title="Importar temas" breadcrumbs="Inicio / Pesquisa conversacional / Temas / Importar">
```

O destino de cada segmento vive em `app/Support/Breadcrumbs.php`. **Tela nova
precisa de entrada nova lá.**

```php
'Inicio / Pesquisa conversacional / Temas / Importar' => ['dashboard', null, 'admin.insight-topics.index', null],
```

O mapa é posicional: a terceira posição é o terceiro segmento. `null` significa
segmento sem link.

### Três regras que o teste cobra

1. **Toda trilha usada por uma tela existe no mapa.** Sem essa verificação,
   treze telas ficaram com a trilha muda — entre elas os sete relatórios da
   Etapa 9E. A trilha continuava aparecendo, apenas sem link, que é o tipo de
   falha que ninguém abre um chamado para relatar.
2. **O último segmento nunca tem link.** Ele é a página atual, e link para onde
   já se está é ruído.
3. **Toda rota citada existe.** Um `route()` para nome inexistente derruba a
   página inteira, e as migalhas aparecem em todas elas.

### Duas defesas embutidas

**A busca ignora acento e caixa.** Uma revisão de ortografia trocou `Inicio` por
`Início` nas telas sem trocar nas chaves do mapa, e duas telas da base de
conhecimento perderam o link em silêncio. Comparar sem acento faz as duas formas
encontrarem a mesma entrada — é onde a regra 2 e a regra 3 deste documento se
cruzam.

**Trilha sem entrada não fica muda.** O primeiro segmento continua levando ao
início. É o link que mais importa, e faz uma tela nova nascer utilizável antes
de alguém escrever a entrada dela. O teste ainda cobra a entrada; o usuário é
que não paga por isso.

---

## Ao criar uma tela

1. Escolher a trilha e **acrescentar a entrada** em `App\Support\Breadcrumbs`.
2. Usar as classes existentes; se precisar de cor, usar um token — e se o token
   não existir, criá-lo em `:root` com um comentário dizendo para que serve.
3. Ícone: conferir se já está no sprite.
4. Escrever em português acentuado.
5. Rodar os dois testes acima.
