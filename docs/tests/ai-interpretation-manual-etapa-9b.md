# Roteiro de homologação manual — Etapa 9B

Homologar com a interpretação desligada e ligar em fases. A Etapa 9A precisa estar homologada antes.

## Preparação

1. Aplicar migrations e seeders.

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=SystemSettingSeeder --force
php artisan db:seed --class=InsightTopicSeeder --force
php artisan cache:clear
```

O `SystemSettingSeeder` e obrigatório mesmo em ambiente que já rodou a 9A: ele corrige a lista `conversation_automation.opt_out_expressions`, que continha `denuncia` e marcava indevidamente como não contatar quem relatava uma denuncia.

2. Adicionar a fila nova ao worker e reiniciar.

```text
ai-interpretation
```

3. Configurar o provedor no `.env` e verificar que a chave não aparece em nenhum log.

```env
AI_PROVIDER=openai
AI_OPENAI_KEY=...
AI_OPENAI_MODEL=...
```

## Fase 1 — taxonomia

1. Acessar `Temas de insights` como administrador.
2. Confirmar que os temas iniciais foram criados e que existe o tema `outros` marcado como fallback.
3. Criar um tema novo com sinônimos e confirmar a ordenação.
4. Tentar excluir o tema `outros`. Esperado: recusa.
5. Tentar desativar o tema `outros`. Esperado: permanece ativo.
6. Excluir o tema novo ainda não utilizado. Esperado: sucesso.

## Fase 2 — camada inerte e separação de chaves

Manter `ai.enabled = 0`.

7. Responder uma pesquisa pelo WhatsApp com um número de teste.
8. Confirmar que **nenhuma** execução aparece em `ai_runs`.
9. Confirmar que o fluxo 9A se comportou exatamente como antes.
10. Ligar apenas `ai.enabled = 1`, mantendo `ai.analysis_enabled = 0`. Responder de novo. Esperado: nenhuma execução de IA e evento `ai_interpretation_blocked` com motivo `analise_desabilitada`.
11. Desligar `conversation_automation.enabled` e ligar `ai.enabled` e `ai.analysis_enabled`. Responder em uma conversa que já tenha estado de fluxo. Esperado: a interpretação **roda**, provando que a 9B não depende da chave da 9A.
12. Enviar uma mensagem em uma conversa **sem** estado de fluxo. Esperado: bloqueio com motivo `sem_contexto_de_pesquisa`.

## Fase 3 — precedência determinística

Ligar `ai.enabled = 1` **e** `ai.analysis_enabled = 1`. As duas são necessárias: a chave mestra sozinha não habilita análise.

Confirmar também que `ai.response_generation_enabled` e `ai.auto_send_enabled` permanecem em `0` — são reservadas para a Etapa 9C, que não esta implementada.

10. Novo número: responder `sim`. Confirmar classificação `permission_yes` com origem `Regra deterministica` e **nenhuma** execução de IA registrada.
11. Novo número: responder `nao quero receber mensagens`. Confirmar `opt_out` determinístico, contato marcado como não contatar e **nenhuma** chamada ao provedor.
12. Novo número: enviar um audio ou imagem. Confirmar `media_or_unsupported` sem chamada externa.

## Fase 4 — classificação e extração

13. Novo número: responder a pergunta com um texto aberto real sobre saúde no interior.
14. Confirmar em `Interpretacao por IA` que o item aparece com resumo, tema, urgência e confiança.
15. Confirmar na tela da conversa o painel lateral com a marcação **Gerado por IA**.
16. Abrir o insight e conferir que a mensagem original aparece integral e inalterada.
17. Conferir em `ai_runs` a latência, os tokens, a versão de prompt e a versão de schema.
18. Conferir que o tema principal e os secundários ficaram vinculados de forma relacional.

## Fase 5 — falhas e saída invalida

19. Apontar `AI_OPENAI_URL` para um endereço inválido e responder de novo. Esperado: execução `Falhou`, item em revisão com motivo `Falha do provedor de IA`, nenhum insight criado, nenhuma mensagem enviada.
20. Repetir a falha até atingir `ai.circuit_failure_threshold`. Esperado: disjuntor aberto em `Monitoramento de IA` e execuções seguintes com `CIRCUIT_OPEN` sem tocar a rede.
21. Aguardar `ai.circuit_open_seconds`, corrigir a URL e confirmar que volta a funcionar.
22. Configurar temporariamente um modelo fraco que não respeite o schema. Esperado: `Saida invalida`, nenhum insight, item em revisão.

## Fase 6 — revisão e correção

23. Baixar `ai.min_extraction_confidence` para `0.99` e responder. Esperado: item em revisão com motivo `Confianca abaixo do limite`.
24. Novo número: escrever uma denuncia. Esperado: revisão com motivo `Relato sensivel ou denuncia`, mesmo com confiança alta.
25. Novo número: escrever pedindo emprego. Esperado: revisão com motivo `Pedido de promessa ou beneficio`.
26. Corrigir o resumo e o tema de um insight como Operador. Confirmar que o valor original aparece no histórico de correções e que a ação aparece na auditoria.
27. Marcar um insight como revisado sem alterar nada e confirmar que sai da fila.

## Fase 7 — reprocessamento e retenção

28. Reprocessar um insight pela tela como administrador. Confirmar nova execução e ausência de insight duplicado.
29. Rodar `php artisan ai:reprocess` sem filtro. Esperado: recusa.
30. Rodar `php artisan ai:reprocess --from=... --to=... --dry-run` e conferir a contagem.
31. Rodar com um período acima de `ai.reprocess_confirm_threshold` e confirmar que pede confirmação.
32. Rodar `php artisan ai:prune-runs --dry-run` e depois sem `--dry-run`. Confirmar que os insights e as mensagens originais permanecem.

## Fase 8 — permissões e privacidade

33. Entrar como Operador: deve ver e corrigir, mas não gerenciar temas, não reprocessar e não ver monitoramento.
34. Entrar como Consulta: deve apenas visualizar.
35. Entrar como usuário sem `ai_insights.view`: menu oculto e acesso negado.
36. Confirmar que a lista de insights mascara o telefone para quem não tem `ai_insights.view_contact_data`.
37. Inspecionar `storage/logs/laravel.log`: confirmar que não ha chave, telefone completo nem corpo de mensagem.

## Fase 9 — regressão

38. Enviar uma resposta manual em uma conversa. Deve funcionar normalmente.
39. Rodar uma campanha sem fluxo e confirmar que nenhum insight e criado.
40. Confirmar que **nenhuma** mensagem automática gerada por IA foi enviada em nenhum momento da homologação.
41. Confirmar que o opt-out continua interrompendo lotes pendentes.

## Verificações finais

- `ai_runs` com todas as tentativas, inclusive as que falharam.
- `conversation_insight_corrections` com valores originais preservados.
- `audit_logs` com as ações de correção, reprocessamento e taxonomia.
- Fila `ai-interpretation` sem acúmulo e sem jobs presos.
- Nenhum segredo em log, banco ou tela.
