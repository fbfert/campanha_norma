# Instruções do projeto

Sistema de atendimento e pesquisa por WhatsApp (Laravel + PHP 8.4). A interface,
a documentação e os comentários são em **português do Brasil**.

## Acentuação: regra obrigatória

**Todo texto em português escrito neste repositório usa a acentuação correta.**
Isso vale para rótulo de tela, mensagem de erro, texto de e-mail, prompt enviado
ao modelo, comentário de código, mensagem de commit e documentação.

Escreva `Situação`, `Configurações`, `usuário`, `não`, `serviço`, `período`,
`índice`, `análise`. Nunca `Situacao`, `Configuracoes`, `usuario`, `nao`.

Os arquivos são UTF-8 e o banco é `utf8mb4`: não existe motivo técnico para
escrever sem acento.

### O que **não** leva acento

Identificador não é prosa, e trocar um quebra o sistema. Continuam sem acento:

- chaves de configuração e de `system_settings` — `knowledge.retrieval_strategy`;
- slugs, `enum` cases e valores gravados no banco — `case Importacao = 'importacao'`;
- nomes de rota e URLs — `admin.analytics.governanca`, `/analytics/governanca`;
- colunas, nomes de arquivo, classes, métodos e variáveis;
- cabeçalhos e valores de CSV lidos pelo importador — `nao_contatar`.

Na dúvida: **se um humano lê, acentue; se o código compara, não toque.**

### Como isso é verificado

`tests/Feature/OrtografiaTest.php` varre `app`, `config`, `database`, `docs`,
`lang`, `resources`, `routes` e `tests`, ignora o que é código e falha apontando
arquivo, linha e a forma correta.

```bash
php artisan test --filter=OrtografiaTest
```

O dicionário é `resources/ortografia/acentuacao-pt-br.json`:

- `correcoes` — forma errada → forma correta;
- `permitidas` — palavras corretas **sem** acento (`categoria`, `arquivo`, `diretoria`);
- `sufixos_suspeitos` — pega palavra nova: quase tudo que termina em `-cao`,
  `-coes`, `-encia`, `-avel`, `-ivel` precisa de acento.

Quando o teste apontar uma palavra:

1. precisa de acento → escreva com acento;
2. está certa sem acento → acrescente em `permitidas`;
3. é deliberada (ex.: saída esperada de uma função que **remove** acento) →
   marque a linha com `// ortografia:ignorar` e diga o porquê.

Detalhes e limitações conhecidas em `docs/ortografia.md`.

## Interface: cor, ícone e caminho de migalhas

Três regras valem em toda tela, e as três são cobradas por
`tests/Feature/PadraoDeInterfaceTest.php`:

- **cor sai de um token** declarado em `:root`, em `resources/css/app.css` —
  nunca escrita a mão numa view nem fora do `:root`;
- **ícone existe no sprite** antes de ser usado (`<use>` para id inexistente não
  desenha nada e não avisa);
- **tela nova precisa de entrada em `app/Support/Breadcrumbs.php`**, senão a
  trilha aparece sem link nenhum.

Nada de `<style>` dentro de view e nada carregado de CDN: o sistema roda em
servidor próprio e precisa abrir com a internet ruim.

O documento completo, com o motivo de cada regra, está em
`docs/padroes-de-interface.md`.

## Testes

```bash
php artisan test
```

A suíte usa SQLite em memória.

### Nenhum teste fala com o mundo

Provedor de IA, WhatsApp, antivírus e extrator de PDF são sempre falsos ou
desligados. Isto **não é convenção**: `tests/TestCase.php` chama
`Http::preventStrayRequests()`, e qualquer requisição que nenhum `Http::fake()`
cubra falha na hora, dizendo qual URL era.

A trava existe porque a convenção não bastou. Um teste da rede de segurança
executava o comando de envio sem falsear o provedor, o serviço do WhatsApp
estava de pé, e a suíte mandou **132 mensagens de verdade** ao longo de dois
dias. Foram todas para o próprio número conectado, então ninguém de fora recebeu
nada — mas isso foi sorte do endereço, não desenho.

Se um teste seu falhar com "stray request", ele estava prestes a sair para a
rede. Acrescente o `Http::fake()` que falta; não remova a trava.

### Teste que envia vai para um telefone só

**Todo envio de teste — automático ou feito à mão, por qualquer pessoa ou
assistente — vai apenas para o telefone cadastrado em
`whatsapp.test_recipient_phone`.**

Hoje é o **5549991613378 (Felipe Boeck Fert)**.

Isso vale para a tela de mensagem de teste, para experimento em produção e para
qualquer verificação de ponta a ponta. `WhatsAppTestMessageService` recusa
qualquer outro destino, e o teste que garante isso é
`TesteSoVaiParaOTelefoneDeTesteTest`.

O motivo é simples: teste que sai para um eleitor não é teste, é uma mensagem de
campanha mandada por engano — e não há como recolher.
