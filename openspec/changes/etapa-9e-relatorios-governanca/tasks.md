# Tarefas — Subetapa 9E

## 1. Especificacao

- [x] 1.1 Ler README, documentacao de relatorios e monitoramento da Etapa 6, filas, implantacao e testes.
- [x] 1.2 Ler as specs aprovadas e as mudancas 9A a 9D.
- [x] 1.3 Inspecionar migrations, models, enums e servicos de relatorio existentes sem supor convencao.
- [x] 1.4 Criar proposta, design e deltas de spec da subetapa 9E.
- [x] 1.5 Validar com `openspec validate --specs` e `openspec validate --all --json`.

## 2. Configuracao e permissoes

- [x] 2.1 Acrescentar as chaves de `analytics` em `system_settings`, todas com valor padrao seguro.
- [x] 2.2 Criar as nove permissoes de analise no enum de permissoes.
- [x] 2.3 Registrar os gates correspondentes.
- [x] 2.4 Atribuir aos papeis: consulta recebe apenas agregado; operador recebe agregado e conteudo; administrador recebe tudo.

## 3. Banco de dados

- [x] 3.1 Criar migration da tabela de metricas diarias de conversa com chave natural por dia e fluxo.
- [x] 3.2 Criar migration acrescentando finalidade, escopo, anonimizacao e sal de pseudonimo a exportacao existente.
- [x] 3.3 Criar indices coerentes para os recortes por periodo, fluxo, tema e cidade.
- [x] 3.4 Garantir `down()` seguro em ambas as migrations.

## 4. Servicos de metrica

- [x] 4.1 Criar servico de metricas de participacao com as taxas documentadas.
- [x] 4.2 Criar servico de metricas de tema, com emergentes e nao classificados.
- [x] 4.3 Criar servico de metricas de geografia usando apenas dado cadastrado ou declarado.
- [x] 4.4 Criar servico de metricas de demanda com exemplos anonimizados.
- [x] 4.5 Criar servico de metricas de qualidade da IA por provedor, modelo e versao de prompt.
- [x] 4.6 Criar servico de metricas de qualidade das perguntas.
- [x] 4.7 Criar servico de relatorio de governanca com divergencias de configuracao.

## 5. Protecoes transversais

- [x] 5.1 Criar supressor de grupo pequeno aplicado no servico, nunca na view.
- [x] 5.2 Criar sanitizador de celula contra injecao de formula.
- [x] 5.3 Aplicar o sanitizador tambem a exportacao existente da Etapa 6.
- [x] 5.4 Criar anonimizador de exportacao com remocao de nome, mascara de telefone e pseudonimo por sal de exportacao.

## 6. Exportacao

- [x] 6.1 Criar exportacao agregada sem identificacao.
- [x] 6.2 Criar exportacao detalhada exigindo permissao elevada e finalidade escrita.
- [x] 6.3 Reutilizar o processamento assincrono, a expiracao e o disco privado ja existentes.
- [x] 6.4 Registrar usuario, filtros, finalidade, data e expiracao.

## 7. Materializacao

- [x] 7.1 Criar comando de reconstrucao idempotente das metricas diarias.
- [x] 7.2 Garantir que reconstruir o mesmo dia duas vezes nao duplica linha.
- [x] 7.3 Reprocessar agregados apos correcao humana ou exclusao.

## 8. Retencao e direitos

- [x] 8.1 Criar comando de anonimizacao de conteudo por contato ou periodo.
- [x] 8.2 Preservar auditoria minima e integridade referencial.
- [x] 8.3 Reprocessar os dias afetados apos a anonimizacao.
- [x] 8.4 Registrar execucao com escopo e quantidade afetada.

## 9. Interface

- [x] 9.1 Criar tela de painel executivo com filtros de periodo e fluxo preservados na URL.
- [x] 9.2 Criar telas de temas, geografia e demandas.
- [x] 9.3 Criar telas de qualidade da IA e qualidade das perguntas.
- [x] 9.4 Criar tela de governanca.
- [x] 9.5 Usar apenas Blade, Alpine e Tailwind existentes, sem biblioteca de graficos nova.
- [x] 9.6 Tratar estado vazio, carregamento e erro com texto claro.
- [x] 9.7 Acrescentar as entradas de menu condicionadas a permissao.

## 10. Testes

- [x] 10.1 Testar cada formula com numerador, denominador e exclusoes.
- [x] 10.2 Testar supressao de grupo abaixo do minimo.
- [x] 10.3 Testar anonimizacao e irreversibilidade do pseudonimo.
- [x] 10.4 Testar neutralizacao de injecao de formula em CSV e XLSX.
- [x] 10.5 Testar as nove permissoes e a separacao entre agregado, conteudo e identificacao.
- [x] 10.6 Testar exportacao assincrona e expiracao.
- [x] 10.7 Testar reconstrucao idempotente das metricas.
- [x] 10.8 Testar anonimizacao com reprocessamento dos agregados.
- [x] 10.9 Testar regressao dos relatorios da Etapa 6.
- [x] 10.10 Testar estado vazio com todas as subetapas desligadas.

## 11. Documentacao

- [x] 11.1 Escrever documentacao de formulas com numerador, denominador e exclusoes.
- [x] 11.2 Escrever manual de operacao da 9E.
- [x] 11.3 Escrever roteiro de homologacao manual.
- [x] 11.4 Atualizar README na secao da Etapa 9.
- [x] 11.5 Produzir matriz de rastreabilidade 9A a 9E entre requisito, teste e tela.
- [x] 11.6 Produzir checklist de implantacao em producao.

## 12. Encerramento

- [x] 12.1 Executar `php artisan test`.
- [x] 12.2 Executar `npm run build`.
- [x] 12.3 Executar Pint.
- [x] 12.4 Executar validacoes OpenSpec.
- [x] 12.5 Apresentar arquivos alterados, migrations, comandos, riscos e pendencias.
