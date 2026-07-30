# Roteiro de homologação manual — Etapa 9C

Homologar com a geração desligada e avançar por fases. O autoenvio e a **última** fase e so deve ser ligado depois que todas as anteriores estiverem estáveis. As Etapas 9A e 9B precisam estar homologadas.

## Preparação

1. Aplicar migration e seeders.

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=SystemSettingSeeder --force
php artisan cache:clear
```

2. Adicionar as filas novas ao worker e reiniciar.

```text
ai-response-generation
ai-response-send
```

3. Confirmar que `ai.response.mode` esta em `disabled` e que `ai.response.auto_send_classifications` esta vazia.

## Fase 1 — camada inerte

4. Responder uma pesquisa por um número de teste.
5. Confirmar que **nenhuma** sugestão aparece em `/admin/reply-suggestions`.
6. Confirmar que a 9A e a 9B se comportaram exatamente como antes.
7. Enviar uma resposta manual em qualquer conversa. Deve funcionar normalmente.

## Fase 2 — rascunho

Ligar `ai.response.mode = draft_only`.

8. Responder de novo. Confirmar que a sugestão aparece como pendente.
9. Abrir a sugestão e confirmar que o botão de aprovar esta **desabilitado**, com o motivo `modo_nao_permite_envio`.
10. Confirmar que nada foi enviado ao contato.
11. Ler o texto gerado com atenção: ele reconhece o ponto, faz uma única pergunta e não promete nada?

## Fase 3 — aprovação humana

Ligar `ai.response.mode = approval_required`.

12. Responder por um número de teste. Abrir a sugestão pendente.
13. Conferir que a tela mostra a mensagem da pessoa, a pergunta original, o resumo, o tema, a confiança e o motivo de revisão quando houver.
14. Aprovar sem editar. Confirmar o recebimento no WhatsApp de teste.
15. Confirmar na timeline da conversa o selo indicando IA e o nome de quem aprovou.
16. Responder de novo, editar o texto antes de aprovar e enviar.
17. Conferir na tela da sugestão que o texto **gerado** e o texto **enviado** aparecem separados.
18. Rejeitar uma sugestão com motivo e confirmar que ela sai da fila.
19. Regenerar uma sugestão sem justificativa. Esperado: recusa.
20. Regenerar com justificativa e confirmar que a anterior continua legível no histórico.

## Fase 4 — sugestão obsoleta

21. Gerar uma sugestão e, antes de aprovar, responder de novo pelo WhatsApp.
22. Tentar aprovar a sugestão antiga. Esperado: recusa, sugestão marcada como obsoleta, nada enviado.
23. Enviar três mensagens seguidas em poucos segundos. Esperado: **uma** sugestão apenas, considerando o texto completo.

## Fase 5 — limites e encerramento

24. Confirmar `ai.response.max_followups = 2`.
25. Aprovar duas perguntas de aprofundamento em uma mesma conversa.
26. Responder uma terceira vez. Esperado: agradecimento automático e fluxo concluído, sem nova sugestão.
27. Conferir que o contador de aprofundamentos subiu apenas nos envios confirmados, não nas gerações.

## Fase 6 — handoff

28. Novo número: perguntar algo factual sobre a Professora Norma. Esperado: handoff, automação pausada, nada enviado.
29. Novo número: escrever uma denuncia. Esperado: handoff com motivo de denuncia.
30. Novo número: pedir para falar com uma pessoa. Esperado: handoff com pedido explícito.
31. Novo número: escrever uma ameaca. Esperado: handoff e prioridade elevada na conversa.
32. Em todos os casos, confirmar que o motivo aparece na tela e que nenhum texto improvisado foi enviado.

## Fase 7 — parada e elegibilidade

33. Gerar uma sugestão e, antes de aprovar, marcar o contato como não contatar. Tentar aprovar. Esperado: recusa.
34. Gerar uma sugestão e desativar o contato. Tentar aprovar. Esperado: recusa.
35. Novo número: pedir para parar de receber mensagens. Confirmar opt-out e que qualquer sugestão pendente foi invalidada.

## Fase 8 — permissões

36. Entrar como Operador: deve ver, rejeitar e dar feedback, mas **não** deve conseguir aprovar.
37. Entrar como Consulta: deve apenas visualizar.
38. Confirmar que a tela não oferece nenhuma forma de aprovar mais de uma sugestão de uma vez.
39. Confirmar que o telefone aparece mascarado para quem não tem a permissão de dados de contato.

## Fase 9 — autoenvio limitado

Somente depois de todas as fases anteriores estáveis.

40. Preencher `ai.response.auto_send_classifications` com `question_answer` apenas.
41. Manter `ai.response.auto_send_min_confidence` alto, em `0.90` ou mais.
42. Ligar `ai.response.mode = auto_send_limited`.
43. Responder por um número de teste com uma resposta clara e objetiva. Confirmar envio automático e o registro da decisão.
44. Atribuir uma conversa a um operador e responder. Esperado: autoenvio recusado com motivo `conversa_atribuida_a_humano`.
45. Baixar a confiança do modelo ou usar uma resposta ambígua. Esperado: recusa por confiança insuficiente.
46. Conferir em `conversation_events` o registro `ai_auto_send_decision` com o motivo de cada recusa.
47. Desligar `ai.response.mode` de volta para `approval_required` e confirmar que o autoenvio para imediatamente.

## Fase 10 — regressão

48. Enviar uma resposta manual e confirmar comportamento, validações e mensagens de erro identicos aos de antes.
49. Rodar uma campanha sem fluxo e confirmar que nenhuma sugestão e criada.
50. Confirmar que a interrupção de lotes por resposta continua funcionando.

## Verificações finais

- `conversation_reply_suggestions` com no máximo uma sugestão viva por mensagem recebida.
- Texto gerado preservado em todas as sugestões editadas.
- `audit_logs` com aprovação, rejeição, regeneração, assunção e feedback.
- `conversation_events` com o motivo de cada decisão de autoenvio.
- Filas sem acúmulo e sem jobs presos.
- Nenhum segredo ou telefone completo em log.
