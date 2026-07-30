# Roteiro de homologação manual — Etapa 9A

Homologar com a automação inicialmente desligada e ligar em duas fases: primeiro so a avaliação, depois o envio.

## Preparação

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
2. Criar fluxo com status `Rascunho`, texto de apresentação, agradecimento e recusa.
3. Confirmar que `Maximo de aprofundamentos` esta em zero.
4. Cadastrar cerca de 20 perguntas, variando peso.
5. Desativar uma pergunta e confirmar que ela não pode ser sorteada.
6. Excluir uma pergunta e confirmar exclusão apenas lógica.
7. Mudar o status do fluxo para `Ativo`.

## Fase 2 — avaliação sem envio

Manter `conversation_automation.auto_send_enabled = 0` e ligar `conversation_automation.enabled = 1`.

8. Criar campanha vinculada ao fluxo e enviar para um número de teste próprio.
9. Confirmar em `Pesquisa conversacional` que a conversa aparece em `Aguardando permissao`.
10. Responder `Sim, pode perguntar` pelo WhatsApp.
11. Confirmar que **nenhuma** mensagem automática foi enviada e que o motivo do bloqueio ficou registrado.

## Fase 3 — envio automático

Ligar `conversation_automation.auto_send_enabled = 1`.

12. Repetir com outro número de teste. Responder `Sim`.
13. Confirmar que exatamente **uma** pergunta chegou.
14. Confirmar na tela da conversa que a mensagem esta marcada como `Automatica`.
15. Confirmar o aviso de transparência no texto.
16. Responder a pergunta e confirmar agradecimento e estagio `Concluido`.

## Fase 4 — caminhos alternativos

17. Novo número: responder `nao obrigado`. Esperado: `Permissao negada`, contato **não** marcado como não contatar.
18. Novo número: responder `nao quero receber mensagens`. Esperado: `Opt-out`, contato marcado como não contatar, destinatários pendentes com `CONTACT_REPLIED`, nenhuma mensagem enviada.
19. Novo número: responder algo ambíguo e longo. Esperado: `Aguardando humano`, nenhuma pergunta enviada.
20. Novo número: desativar todas as perguntas antes de responder `sim`. Esperado: `Aguardando humano` com evento `automation_no_question_available`.

## Fase 5 — controles e limites

21. Pausar a automação de uma conversa e responder. Esperado: nada acontece.
22. Retomar e confirmar que volta a funcionar.
23. Assumir manualmente e confirmar que a automação para.
24. Encerrar manualmente e confirmar estagio terminal.
25. Pausar o fluxo inteiro e confirmar que nenhuma conversa daquele fluxo avança.
26. Desligar `conversation_automation.enabled` e confirmar que nada e criado.
27. Responder novamente em uma conversa já concluída. Esperado: não reinicia.

## Fase 6 — permissões

28. Entrar como Operador: deve ver e controlar, mas não gerenciar fluxos nem perguntas.
29. Entrar como Consulta: deve apenas visualizar.
30. Entrar como usuário sem a permissão: menu oculto e acesso negado.

## Fase 7 — regressão das etapas anteriores

31. Enviar uma resposta manual em uma conversa sem fluxo. Deve funcionar normalmente.
32. Rodar uma campanha **sem** fluxo associado e confirmar que nenhum estado de automação e criado.
33. Confirmar que mensagens recebidas continuam sendo registradas normalmente e que a interrupção de lotes por resposta continua funcionando.

## Verificações finais

- `conversation_flow_transitions` com histórico completo e responsável correto.
- `audit_logs` com as ações de automação.
- Nenhum segredo ou corpo completo indevido em log.
- Filas sem acúmulo e sem jobs falhos.
