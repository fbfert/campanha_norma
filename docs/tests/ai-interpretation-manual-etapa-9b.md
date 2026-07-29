# Roteiro de homologacao manual — Etapa 9B

Homologar com a interpretacao desligada e ligar em fases. A Etapa 9A precisa estar homologada antes.

## Preparacao

1. Aplicar migrations e seeders.

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=SystemSettingSeeder --force
php artisan db:seed --class=InsightTopicSeeder --force
php artisan cache:clear
```

O `SystemSettingSeeder` e obrigatorio mesmo em ambiente que ja rodou a 9A: ele corrige a lista `conversation_automation.opt_out_expressions`, que continha `denuncia` e marcava indevidamente como nao contatar quem relatava uma denuncia.

2. Adicionar a fila nova ao worker e reiniciar.

```text
ai-interpretation
```

3. Configurar o provedor no `.env` e verificar que a chave nao aparece em nenhum log.

```env
AI_PROVIDER=openai
AI_OPENAI_KEY=...
AI_OPENAI_MODEL=...
```

## Fase 1 — taxonomia

1. Acessar `Temas de insights` como administrador.
2. Confirmar que os temas iniciais foram criados e que existe o tema `outros` marcado como fallback.
3. Criar um tema novo com sinonimos e confirmar a ordenacao.
4. Tentar excluir o tema `outros`. Esperado: recusa.
5. Tentar desativar o tema `outros`. Esperado: permanece ativo.
6. Excluir o tema novo ainda nao utilizado. Esperado: sucesso.

## Fase 2 — camada inerte e separacao de chaves

Manter `ai.enabled = 0`.

7. Responder uma pesquisa pelo WhatsApp com um numero de teste.
8. Confirmar que **nenhuma** execucao aparece em `ai_runs`.
9. Confirmar que o fluxo 9A se comportou exatamente como antes.
10. Ligar apenas `ai.enabled = 1`, mantendo `ai.analysis_enabled = 0`. Responder de novo. Esperado: nenhuma execucao de IA e evento `ai_interpretation_blocked` com motivo `analise_desabilitada`.
11. Desligar `conversation_automation.enabled` e ligar `ai.enabled` e `ai.analysis_enabled`. Responder em uma conversa que ja tenha estado de fluxo. Esperado: a interpretacao **roda**, provando que a 9B nao depende da chave da 9A.
12. Enviar uma mensagem em uma conversa **sem** estado de fluxo. Esperado: bloqueio com motivo `sem_contexto_de_pesquisa`.

## Fase 3 — precedencia deterministica

Ligar `ai.enabled = 1` **e** `ai.analysis_enabled = 1`. As duas sao necessarias: a chave mestra sozinha nao habilita analise.

Confirmar tambem que `ai.response_generation_enabled` e `ai.auto_send_enabled` permanecem em `0` — sao reservadas para a Etapa 9C, que nao esta implementada.

10. Novo numero: responder `sim`. Confirmar classificacao `permission_yes` com origem `Regra deterministica` e **nenhuma** execucao de IA registrada.
11. Novo numero: responder `nao quero receber mensagens`. Confirmar `opt_out` deterministico, contato marcado como nao contatar e **nenhuma** chamada ao provedor.
12. Novo numero: enviar um audio ou imagem. Confirmar `media_or_unsupported` sem chamada externa.

## Fase 4 — classificacao e extracao

13. Novo numero: responder a pergunta com um texto aberto real sobre saude no interior.
14. Confirmar em `Interpretacao por IA` que o item aparece com resumo, tema, urgencia e confianca.
15. Confirmar na tela da conversa o painel lateral com a marcacao **Gerado por IA**.
16. Abrir o insight e conferir que a mensagem original aparece integral e inalterada.
17. Conferir em `ai_runs` a latencia, os tokens, a versao de prompt e a versao de schema.
18. Conferir que o tema principal e os secundarios ficaram vinculados de forma relacional.

## Fase 5 — falhas e saida invalida

19. Apontar `AI_OPENAI_URL` para um endereco invalido e responder de novo. Esperado: execucao `Falhou`, item em revisao com motivo `Falha do provedor de IA`, nenhum insight criado, nenhuma mensagem enviada.
20. Repetir a falha ate atingir `ai.circuit_failure_threshold`. Esperado: disjuntor aberto em `Monitoramento de IA` e execucoes seguintes com `CIRCUIT_OPEN` sem tocar a rede.
21. Aguardar `ai.circuit_open_seconds`, corrigir a URL e confirmar que volta a funcionar.
22. Configurar temporariamente um modelo fraco que nao respeite o schema. Esperado: `Saida invalida`, nenhum insight, item em revisao.

## Fase 6 — revisao e correcao

23. Baixar `ai.min_extraction_confidence` para `0.99` e responder. Esperado: item em revisao com motivo `Confianca abaixo do limite`.
24. Novo numero: escrever uma denuncia. Esperado: revisao com motivo `Relato sensivel ou denuncia`, mesmo com confianca alta.
25. Novo numero: escrever pedindo emprego. Esperado: revisao com motivo `Pedido de promessa ou beneficio`.
26. Corrigir o resumo e o tema de um insight como Operador. Confirmar que o valor original aparece no historico de correcoes e que a acao aparece na auditoria.
27. Marcar um insight como revisado sem alterar nada e confirmar que sai da fila.

## Fase 7 — reprocessamento e retencao

28. Reprocessar um insight pela tela como administrador. Confirmar nova execucao e ausencia de insight duplicado.
29. Rodar `php artisan ai:reprocess` sem filtro. Esperado: recusa.
30. Rodar `php artisan ai:reprocess --from=... --to=... --dry-run` e conferir a contagem.
31. Rodar com um periodo acima de `ai.reprocess_confirm_threshold` e confirmar que pede confirmacao.
32. Rodar `php artisan ai:prune-runs --dry-run` e depois sem `--dry-run`. Confirmar que os insights e as mensagens originais permanecem.

## Fase 8 — permissoes e privacidade

33. Entrar como Operador: deve ver e corrigir, mas nao gerenciar temas, nao reprocessar e nao ver monitoramento.
34. Entrar como Consulta: deve apenas visualizar.
35. Entrar como usuario sem `ai_insights.view`: menu oculto e acesso negado.
36. Confirmar que a lista de insights mascara o telefone para quem nao tem `ai_insights.view_contact_data`.
37. Inspecionar `storage/logs/laravel.log`: confirmar que nao ha chave, telefone completo nem corpo de mensagem.

## Fase 9 — regressao

38. Enviar uma resposta manual em uma conversa. Deve funcionar normalmente.
39. Rodar uma campanha sem fluxo e confirmar que nenhum insight e criado.
40. Confirmar que **nenhuma** mensagem automatica gerada por IA foi enviada em nenhum momento da homologacao.
41. Confirmar que o opt-out continua interrompendo lotes pendentes.

## Verificacoes finais

- `ai_runs` com todas as tentativas, inclusive as que falharam.
- `conversation_insight_corrections` com valores originais preservados.
- `audit_logs` com as acoes de correcao, reprocessamento e taxonomia.
- Fila `ai-interpretation` sem acumulo e sem jobs presos.
- Nenhum segredo em log, banco ou tela.
