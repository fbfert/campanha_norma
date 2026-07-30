# Homologação manual — Subetapa 9E

Confere o que os testes automatizados não alcançam: leitura das telas, sentido
dos números para quem vai decidir com eles, e o conteúdo real dos arquivos
exportados.

**Pre-requisitos:** migrations e seeders da 9E aplicados. Usuários nos três
perfis. Idealmente, alguma conversa real já coletada — com base vazia da para
homologar as Fases 0, 1 e 6, e o resto fica sem matéria.

Registre em cada fase: data, quem executou, resultado e evidência.

---

## Fase 0 — Estado vazio

| # | Ação | Esperado |
| --- | --- | --- |
| 0.1 | Abrir as seis telas agregadas sem nenhum dado | Todas abrem. Cada seção vazia diz que não ha registro, e não mostra gráfico vazio como se fosse resultado |
| 0.2 | Conferir as taxas no painel | Aparece traço, nunca zero por cento |
| 0.3 | `php artisan analytics:rebuild-metrics --date=hoje` | Executa sem erro e cria a linha de total |
| 0.4 | Repetir o mesmo comando | Nenhuma linha nova. A contagem da tabela não muda |

---

## Fase 1 — Permissões

| # | Ação | Esperado |
| --- | --- | --- |
| 1.1 | Entrar como **consulta** | Ve as seis telas agregadas; não ve Governança no menu |
| 1.2 | Consulta abre Demandas | Ve urgência e fila de revisão; **não** ve problemas, ações, resultados nem exemplos |
| 1.3 | Consulta acessa `/admin/analytics/governanca` pela URL | Recusado |
| 1.4 | Entrar como **operador** | Ve texto das demandas e os exemplos |
| 1.5 | Operador abre Qualidade da IA | Colunas de custo ausentes, com aviso explicando por que |
| 1.6 | Entrar como **administrador** | Ve tudo, incluindo custo e governança |

---

## Fase 2 — Números do painel

Com dados reais coletados.

| # | Ação | Esperado |
| --- | --- | --- |
| 2.1 | Conferir "contatos abordados" contra a tela de Pesquisa conversacional | Mesmo número |
| 2.2 | Conferir a taxa de permissão | O denominador exclui quem ainda não respondeu |
| 2.3 | Somar concedidas, negadas e opt-outs | Bate com o denominador exibido |
| 2.4 | Trocar o período para um intervalo sem dados | Traço nas taxas, sem erro |
| 2.5 | Filtrar por um fluxo específico | Números mudam e o filtro aparece na URL |
| 2.6 | Copiar a URL e abrir em outra aba | Mesmos filtros aplicados |
| 2.7 | Conferir tempo médio até a primeira resposta | O número de amostras aparece ao lado e exclui quem nunca respondeu |

---

## Fase 3 — Supressão

| # | Ação | Esperado |
| --- | --- | --- |
| 3.1 | Encontrar uma cidade com menos de 5 respostas | Contagem aparece como suprimida, e a linha continua na lista |
| 3.2 | Baixar `analytics.minimum_cell_size` para 2 e recarregar | A mesma célula passa a mostrar o número |
| 3.3 | Devolver para 5 | Volta a suprimir |
| 3.4 | Conferir uma célula com zero | Mostra zero, nunca suprimido |
| 3.5 | Procurar filtro por atributo sensível na tela de geografia | Não existe |

---

## Fase 4 — Exportação agregada

| # | Ação | Esperado |
| --- | --- | --- |
| 4.1 | Exportar temas em CSV como operador | Arquivo gerado, expiração definida |
| 4.2 | Abrir o arquivo | Contem tema, quantidade e confiança. **Não** contem nome, telefone nem identificador |
| 4.3 | Conferir auditoria | Evento `analytics.export_requested` com usuário e filtros |
| 4.4 | Exportar em XLSX | Mesmo conteúdo, abre no Excel sem aviso |
| 4.5 | Esperar a expiração ou forçar a data | Download recusado |

---

## Fase 5 — Exportação detalhada

| # | Ação | Esperado |
| --- | --- | --- |
| 5.1 | Operador tenta exportar detalhado | Recusado |
| 5.2 | Administrador tenta sem preencher finalidade | Recusado com mensagem clara |
| 5.3 | Administrador exporta com finalidade | Arquivo gerado; finalidade gravada junto |
| 5.4 | Abrir o arquivo | Contem o texto das respostas; **não** contem nome nem telefone; primeira coluna e pseudônimo |
| 5.5 | Exportar de novo o mesmo período | Os pseudonimos do segundo arquivo são diferentes dos do primeiro |
| 5.6 | Tentar cruzar os dois arquivos pelo pseudônimo | Impossível: nenhum valor coincide |

---

## Fase 6 — Injeção de fórmula

| # | Ação | Esperado |
| --- | --- | --- |
| 6.1 | Fazer uma conversa de teste responder com um texto começando por `=1+1` | A resposta e gravada normalmente |
| 6.2 | Exportar detalhado e abrir no Excel ou LibreOffice | A célula aparece como texto. **Nenhuma fórmula e avaliada** |
| 6.3 | Repetir com `@SUM(A1)` e `-1` | Mesmo comportamento |
| 6.4 | Exportar o histórico de mensagens da Etapa 6 com a mesma mensagem | Também neutralizado |

---

## Fase 7 — Materialização e correção

| # | Ação | Esperado |
| --- | --- | --- |
| 7.1 | `analytics:rebuild-metrics --days=7` | Executa e relata as linhas escritas |
| 7.2 | Repetir | Mesma contagem de linhas na tabela |
| 7.3 | Corrigir uma classificação pela tela de insights | Correção gravada |
| 7.4 | Reconstruir o dia da correção | Valores refletem a correção |

---

## Fase 8 — Retenção e direitos

| # | Ação | Esperado |
| --- | --- | --- |
| 8.1 | `analytics:anonymize` sem argumento | Recusa e explica que falta escopo |
| 8.2 | `analytics:anonymize --contact=N --dry-run` | Relata o que seria afetado; nada muda |
| 8.3 | `analytics:anonymize --contact=N` | Texto esvaziado; **a linha continua existindo** |
| 8.4 | Conferir a conversa daquele contato | Mensagens sem corpo, histórico preservado |
| 8.5 | Conferir os relatórios do período | Contagens preservadas; texto desaparecido |
| 8.6 | Conferir auditoria | Evento `analytics.content_anonymized` com escopo e quantidade |

---

## Fase 9 — Governança

| # | Ação | Esperado |
| --- | --- | --- |
| 9.1 | Abrir Governança com tudo desligado | Mostra os cinco interruptores em desligado |
| 9.2 | Ligar `ai.enabled` sem provedor configurado | Divergência listada explicitamente |
| 9.3 | Ligar `knowledge.enabled` sem documento aprovado | Divergência listada |
| 9.4 | Ligar automação sem fluxo ativo | Divergência listada |
| 9.5 | Desfazer e recarregar | Divergências somem |
| 9.6 | Conferir pendências e falhas | Números batem com as telas de origem |

---

## Fase 10 — Regressão

| # | Ação | Esperado |
| --- | --- | --- |
| 10.1 | Abrir os relatórios da Etapa 6 | Funcionam como antes |
| 10.2 | Exportar histórico de mensagens | Funciona; agora com células neutralizadas |
| 10.3 | Abrir a caixa de entrada e responder manualmente | Sem alteração |
| 10.4 | Conferir campanhas, lotes e processamento | Sem alteração |
| 10.5 | `php artisan test` | Suite completa passando |

---

## Encerramento

- [ ] Todas as fases executadas e registradas.
- [ ] Nenhum arquivo exportado contem nome ou telefone.
- [ ] Nenhuma célula pequena exibida.
- [ ] Perfil de consulta não viu texto de cidadão em nenhuma tela.
