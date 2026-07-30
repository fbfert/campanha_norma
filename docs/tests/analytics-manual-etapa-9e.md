# Homologacao manual — Subetapa 9E

Confere o que os testes automatizados nao alcancam: leitura das telas, sentido
dos numeros para quem vai decidir com eles, e o conteudo real dos arquivos
exportados.

**Pre-requisitos:** migrations e seeders da 9E aplicados. Usuarios nos tres
perfis. Idealmente, alguma conversa real ja coletada — com base vazia da para
homologar as Fases 0, 1 e 6, e o resto fica sem materia.

Registre em cada fase: data, quem executou, resultado e evidencia.

---

## Fase 0 — Estado vazio

| # | Acao | Esperado |
| --- | --- | --- |
| 0.1 | Abrir as seis telas agregadas sem nenhum dado | Todas abrem. Cada secao vazia diz que nao ha registro, e nao mostra grafico vazio como se fosse resultado |
| 0.2 | Conferir as taxas no painel | Aparece traco, nunca zero por cento |
| 0.3 | `php artisan analytics:rebuild-metrics --date=hoje` | Executa sem erro e cria a linha de total |
| 0.4 | Repetir o mesmo comando | Nenhuma linha nova. A contagem da tabela nao muda |

---

## Fase 1 — Permissoes

| # | Acao | Esperado |
| --- | --- | --- |
| 1.1 | Entrar como **consulta** | Ve as seis telas agregadas; nao ve Governanca no menu |
| 1.2 | Consulta abre Demandas | Ve urgencia e fila de revisao; **nao** ve problemas, acoes, resultados nem exemplos |
| 1.3 | Consulta acessa `/admin/analytics/governanca` pela URL | Recusado |
| 1.4 | Entrar como **operador** | Ve texto das demandas e os exemplos |
| 1.5 | Operador abre Qualidade da IA | Colunas de custo ausentes, com aviso explicando por que |
| 1.6 | Entrar como **administrador** | Ve tudo, incluindo custo e governanca |

---

## Fase 2 — Numeros do painel

Com dados reais coletados.

| # | Acao | Esperado |
| --- | --- | --- |
| 2.1 | Conferir "contatos abordados" contra a tela de Pesquisa conversacional | Mesmo numero |
| 2.2 | Conferir a taxa de permissao | O denominador exclui quem ainda nao respondeu |
| 2.3 | Somar concedidas, negadas e opt-outs | Bate com o denominador exibido |
| 2.4 | Trocar o periodo para um intervalo sem dados | Traco nas taxas, sem erro |
| 2.5 | Filtrar por um fluxo especifico | Numeros mudam e o filtro aparece na URL |
| 2.6 | Copiar a URL e abrir em outra aba | Mesmos filtros aplicados |
| 2.7 | Conferir tempo medio ate a primeira resposta | O numero de amostras aparece ao lado e exclui quem nunca respondeu |

---

## Fase 3 — Supressao

| # | Acao | Esperado |
| --- | --- | --- |
| 3.1 | Encontrar uma cidade com menos de 5 respostas | Contagem aparece como suprimida, e a linha continua na lista |
| 3.2 | Baixar `analytics.minimum_cell_size` para 2 e recarregar | A mesma celula passa a mostrar o numero |
| 3.3 | Devolver para 5 | Volta a suprimir |
| 3.4 | Conferir uma celula com zero | Mostra zero, nunca suprimido |
| 3.5 | Procurar filtro por atributo sensivel na tela de geografia | Nao existe |

---

## Fase 4 — Exportacao agregada

| # | Acao | Esperado |
| --- | --- | --- |
| 4.1 | Exportar temas em CSV como operador | Arquivo gerado, expiracao definida |
| 4.2 | Abrir o arquivo | Contem tema, quantidade e confianca. **Nao** contem nome, telefone nem identificador |
| 4.3 | Conferir auditoria | Evento `analytics.export_requested` com usuario e filtros |
| 4.4 | Exportar em XLSX | Mesmo conteudo, abre no Excel sem aviso |
| 4.5 | Esperar a expiracao ou forcar a data | Download recusado |

---

## Fase 5 — Exportacao detalhada

| # | Acao | Esperado |
| --- | --- | --- |
| 5.1 | Operador tenta exportar detalhado | Recusado |
| 5.2 | Administrador tenta sem preencher finalidade | Recusado com mensagem clara |
| 5.3 | Administrador exporta com finalidade | Arquivo gerado; finalidade gravada junto |
| 5.4 | Abrir o arquivo | Contem o texto das respostas; **nao** contem nome nem telefone; primeira coluna e pseudonimo |
| 5.5 | Exportar de novo o mesmo periodo | Os pseudonimos do segundo arquivo sao diferentes dos do primeiro |
| 5.6 | Tentar cruzar os dois arquivos pelo pseudonimo | Impossivel: nenhum valor coincide |

---

## Fase 6 — Injecao de formula

| # | Acao | Esperado |
| --- | --- | --- |
| 6.1 | Fazer uma conversa de teste responder com um texto comecando por `=1+1` | A resposta e gravada normalmente |
| 6.2 | Exportar detalhado e abrir no Excel ou LibreOffice | A celula aparece como texto. **Nenhuma formula e avaliada** |
| 6.3 | Repetir com `@SUM(A1)` e `-1` | Mesmo comportamento |
| 6.4 | Exportar o historico de mensagens da Etapa 6 com a mesma mensagem | Tambem neutralizado |

---

## Fase 7 — Materializacao e correcao

| # | Acao | Esperado |
| --- | --- | --- |
| 7.1 | `analytics:rebuild-metrics --days=7` | Executa e relata as linhas escritas |
| 7.2 | Repetir | Mesma contagem de linhas na tabela |
| 7.3 | Corrigir uma classificacao pela tela de insights | Correcao gravada |
| 7.4 | Reconstruir o dia da correcao | Valores refletem a correcao |

---

## Fase 8 — Retencao e direitos

| # | Acao | Esperado |
| --- | --- | --- |
| 8.1 | `analytics:anonymize` sem argumento | Recusa e explica que falta escopo |
| 8.2 | `analytics:anonymize --contact=N --dry-run` | Relata o que seria afetado; nada muda |
| 8.3 | `analytics:anonymize --contact=N` | Texto esvaziado; **a linha continua existindo** |
| 8.4 | Conferir a conversa daquele contato | Mensagens sem corpo, historico preservado |
| 8.5 | Conferir os relatorios do periodo | Contagens preservadas; texto desaparecido |
| 8.6 | Conferir auditoria | Evento `analytics.content_anonymized` com escopo e quantidade |

---

## Fase 9 — Governanca

| # | Acao | Esperado |
| --- | --- | --- |
| 9.1 | Abrir Governanca com tudo desligado | Mostra os cinco interruptores em desligado |
| 9.2 | Ligar `ai.enabled` sem provedor configurado | Divergencia listada explicitamente |
| 9.3 | Ligar `knowledge.enabled` sem documento aprovado | Divergencia listada |
| 9.4 | Ligar automacao sem fluxo ativo | Divergencia listada |
| 9.5 | Desfazer e recarregar | Divergencias somem |
| 9.6 | Conferir pendencias e falhas | Numeros batem com as telas de origem |

---

## Fase 10 — Regressao

| # | Acao | Esperado |
| --- | --- | --- |
| 10.1 | Abrir os relatorios da Etapa 6 | Funcionam como antes |
| 10.2 | Exportar historico de mensagens | Funciona; agora com celulas neutralizadas |
| 10.3 | Abrir a caixa de entrada e responder manualmente | Sem alteracao |
| 10.4 | Conferir campanhas, lotes e processamento | Sem alteracao |
| 10.5 | `php artisan test` | Suite completa passando |

---

## Encerramento

- [ ] Todas as fases executadas e registradas.
- [ ] Nenhum arquivo exportado contem nome ou telefone.
- [ ] Nenhuma celula pequena exibida.
- [ ] Perfil de consulta nao viu texto de cidadao em nenhuma tela.
