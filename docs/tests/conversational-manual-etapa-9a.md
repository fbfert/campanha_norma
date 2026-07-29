# Roteiro de homologacao manual — Etapa 9A

Homologar com a automacao inicialmente desligada e ligar em duas fases: primeiro so a avaliacao, depois o envio.

## Preparacao

1. Aplicar migrations e seeders.

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=SystemSettingSeeder --force
php artisan cache:clear
```

2. Adicionar as filas novas ao worker e reiniciar.

```text
conversation-automation
conversation-automation-send
```

3. Confirmar WhatsApp conectado.

## Fase 1 — cadastro

1. Acessar `Fluxos conversacionais` como administrador.
2. Criar fluxo com status `Rascunho`, texto de apresentacao, agradecimento e recusa.
3. Confirmar que `Maximo de aprofundamentos` esta em zero.
4. Cadastrar cerca de 20 perguntas, variando peso.
5. Desativar uma pergunta e confirmar que ela nao pode ser sorteada.
6. Excluir uma pergunta e confirmar exclusao apenas logica.
7. Mudar o status do fluxo para `Ativo`.

## Fase 2 — avaliacao sem envio

Manter `conversation_automation.auto_send_enabled = 0` e ligar `conversation_automation.enabled = 1`.

8. Criar campanha vinculada ao fluxo e enviar para um numero de teste proprio.
9. Confirmar em `Pesquisa conversacional` que a conversa aparece em `Aguardando permissao`.
10. Responder `Sim, pode perguntar` pelo WhatsApp.
11. Confirmar que **nenhuma** mensagem automatica foi enviada e que o motivo do bloqueio ficou registrado.

## Fase 3 — envio automatico

Ligar `conversation_automation.auto_send_enabled = 1`.

12. Repetir com outro numero de teste. Responder `Sim`.
13. Confirmar que exatamente **uma** pergunta chegou.
14. Confirmar na tela da conversa que a mensagem esta marcada como `Automatica`.
15. Confirmar o aviso de transparencia no texto.
16. Responder a pergunta e confirmar agradecimento e estagio `Concluido`.

## Fase 4 — caminhos alternativos

17. Novo numero: responder `nao obrigado`. Esperado: `Permissao negada`, contato **nao** marcado como nao contatar.
18. Novo numero: responder `nao quero receber mensagens`. Esperado: `Opt-out`, contato marcado como nao contatar, destinatarios pendentes com `CONTACT_REPLIED`, nenhuma mensagem enviada.
19. Novo numero: responder algo ambiguo e longo. Esperado: `Aguardando humano`, nenhuma pergunta enviada.
20. Novo numero: desativar todas as perguntas antes de responder `sim`. Esperado: `Aguardando humano` com evento `automation_no_question_available`.

## Fase 5 — controles e limites

21. Pausar a automacao de uma conversa e responder. Esperado: nada acontece.
22. Retomar e confirmar que volta a funcionar.
23. Assumir manualmente e confirmar que a automacao para.
24. Encerrar manualmente e confirmar estagio terminal.
25. Pausar o fluxo inteiro e confirmar que nenhuma conversa daquele fluxo avanca.
26. Desligar `conversation_automation.enabled` e confirmar que nada e criado.
27. Responder novamente em uma conversa ja concluida. Esperado: nao reinicia.

## Fase 6 — permissoes

28. Entrar como Operador: deve ver e controlar, mas nao gerenciar fluxos nem perguntas.
29. Entrar como Consulta: deve apenas visualizar.
30. Entrar como usuario sem a permissao: menu oculto e acesso negado.

## Fase 7 — regressao das etapas anteriores

31. Enviar uma resposta manual em uma conversa sem fluxo. Deve funcionar normalmente.
32. Rodar uma campanha **sem** fluxo associado e confirmar que nenhum estado de automacao e criado.
33. Confirmar que mensagens recebidas continuam sendo registradas normalmente e que a interrupcao de lotes por resposta continua funcionando.

## Verificacoes finais

- `conversation_flow_transitions` com historico completo e responsavel correto.
- `audit_logs` com as acoes de automacao.
- Nenhum segredo ou corpo completo indevido em log.
- Filas sem acumulo e sem jobs falhos.
