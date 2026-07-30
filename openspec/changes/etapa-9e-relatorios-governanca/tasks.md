# Tarefas — Subetapa 9E

## 1. Especificação

- [x] 1.1 Ler README, documentação de relatórios e monitoramento da Etapa 6, filas, implantação e testes.
- [x] 1.2 Ler as specs aprovadas e as mudanças 9A a 9D.
- [x] 1.3 Inspecionar migrations, models, enums e serviços de relatório existentes sem supor convenção.
- [x] 1.4 Criar proposta, design e deltas de spec da subetapa 9E.
- [x] 1.5 Validar com `openspec validate --specs` e `openspec validate --all --json`.

## 2. Configuração e permissões

- [x] 2.1 Acrescentar as chaves de `analytics` em `system_settings`, todas com valor padrão seguro.
- [x] 2.2 Criar as nove permissões de análise no enum de permissões.
- [x] 2.3 Registrar os gates correspondentes.
- [x] 2.4 Atribuir aos papeis: consulta recebe apenas agregado; operador recebe agregado e conteúdo; administrador recebe tudo.

## 3. Banco de dados

- [x] 3.1 Criar migration da tabela de métricas diárias de conversa com chave natural por dia e fluxo.
- [x] 3.2 Criar migration acrescentando finalidade, escopo, anonimização e sal de pseudônimo a exportação existente.
- [x] 3.3 Criar índices coerentes para os recortes por período, fluxo, tema e cidade.
- [x] 3.4 Garantir `down()` seguro em ambas as migrations.

## 4. Serviços de métrica

- [x] 4.1 Criar serviço de métricas de participação com as taxas documentadas.
- [x] 4.2 Criar serviço de métricas de tema, com emergentes e não classificados.
- [x] 4.3 Criar serviço de métricas de geografia usando apenas dado cadastrado ou declarado.
- [x] 4.4 Criar serviço de métricas de demanda com exemplos anonimizados.
- [x] 4.5 Criar serviço de métricas de qualidade da IA por provedor, modelo e versão de prompt.
- [x] 4.6 Criar serviço de métricas de qualidade das perguntas.
- [x] 4.7 Criar serviço de relatório de governança com divergências de configuração.

## 5. Proteções transversais

- [x] 5.1 Criar supressor de grupo pequeno aplicado no serviço, nunca na view.
- [x] 5.2 Criar sanitizador de célula contra injeção de fórmula.
- [x] 5.3 Aplicar o sanitizador também a exportação existente da Etapa 6.
- [x] 5.4 Criar anonimizador de exportação com remoção de nome, mascara de telefone e pseudônimo por sal de exportação.

## 6. Exportação

- [x] 6.1 Criar exportação agregada sem identificação.
- [x] 6.2 Criar exportação detalhada exigindo permissão elevada e finalidade escrita.
- [x] 6.3 Reutilizar o processamento assíncrono, a expiração e o disco privado já existentes.
- [x] 6.4 Registrar usuário, filtros, finalidade, data e expiração.

## 7. Materialização

- [x] 7.1 Criar comando de reconstrução idempotente das métricas diárias.
- [x] 7.2 Garantir que reconstruir o mesmo dia duas vezes não duplica linha.
- [x] 7.3 Reprocessar agregados após correção humana ou exclusão.

## 8. Retenção e direitos

- [x] 8.1 Criar comando de anonimização de conteúdo por contato ou período.
- [x] 8.2 Preservar auditoria mínima e integridade referencial.
- [x] 8.3 Reprocessar os dias afetados após a anonimização.
- [x] 8.4 Registrar execução com escopo e quantidade afetada.

## 9. Interface

- [x] 9.1 Criar tela de painel executivo com filtros de período e fluxo preservados na URL.
- [x] 9.2 Criar telas de temas, geografia e demandas.
- [x] 9.3 Criar telas de qualidade da IA e qualidade das perguntas.
- [x] 9.4 Criar tela de governança.
- [x] 9.5 Usar apenas Blade, Alpine e Tailwind existentes, sem biblioteca de gráficos nova.
- [x] 9.6 Tratar estado vazio, carregamento e erro com texto claro.
- [x] 9.7 Acrescentar as entradas de menu condicionadas a permissão.

## 10. Testes

- [x] 10.1 Testar cada fórmula com numerador, denominador e exclusões.
- [x] 10.2 Testar supressão de grupo abaixo do mínimo.
- [x] 10.3 Testar anonimização e irreversibilidade do pseudônimo.
- [x] 10.4 Testar neutralização de injeção de fórmula em CSV e XLSX.
- [x] 10.5 Testar as nove permissões e a separação entre agregado, conteúdo e identificação.
- [x] 10.6 Testar exportação assincrona e expiração.
- [x] 10.7 Testar reconstrução idempotente das métricas.
- [x] 10.8 Testar anonimização com reprocessamento dos agregados.
- [x] 10.9 Testar regressão dos relatórios da Etapa 6.
- [x] 10.10 Testar estado vazio com todas as subetapas desligadas.

## 11. Documentação

- [x] 11.1 Escrever documentação de fórmulas com numerador, denominador e exclusões.
- [x] 11.2 Escrever manual de operação da 9E.
- [x] 11.3 Escrever roteiro de homologação manual.
- [x] 11.4 Atualizar README na seção da Etapa 9.
- [x] 11.5 Produzir matriz de rastreabilidade 9A a 9E entre requisito, teste e tela.
- [x] 11.6 Produzir checklist de implantação em produção.

## 12. Encerramento

- [x] 12.1 Executar `php artisan test`.
- [x] 12.2 Executar `npm run build`.
- [x] 12.3 Executar Pint.
- [x] 12.4 Executar validações OpenSpec.
- [x] 12.5 Apresentar arquivos alterados, migrations, comandos, riscos e pendências.
