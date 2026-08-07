# Deploy: a fila precisa ser reiniciada

**Toda alteração em job, service ou configuração que um job use só passa a valer
depois de reiniciar a fila.**

```bash
sudo systemctl restart gerenciador-mensagens-queue
```

## Por que

`queue:work` é processo longo. Ele carrega as classes uma vez, ao subir, e as
mantém em memória enquanto vive. Publicar código novo não muda nada nele: o
worker segue executando a versão que carregou.

O efeito é traiçoeiro porque tudo parece funcionar. O deploy passa, a tela mostra
o código novo, os testes passam, e só o comportamento em produção continua o
antigo — sem erro, sem aviso, sem nada que aponte para a causa.

Aconteceu duas vezes:

- ao trocar a chave do provedor de IA, o worker continuou usando a anterior;
- ao corrigir o bloqueio do aviso da rede de segurança, a primeira execução
  depois da correção ainda falhou exatamente como antes.

Nos dois casos a correção estava certa e o worker é que não a tinha visto.

## Quando reiniciar

Sempre que mudar qualquer um destes:

- um job em `app/Jobs`;
- um service que algum job chame — o que na prática é quase todo
  `app/Services`;
- uma configuração lida no boot (`config/*.php`, `.env`).

Configuração guardada em `system_settings` é lida a cada chamada e **não** exige
reinício. É a exceção, não a regra.

Na dúvida, reinicie: a fila retoma de onde parou, os jobs pendentes continuam na
tabela, e o custo é de segundos.

## Como confirmar que pegou

```bash
systemctl show gerenciador-mensagens-queue -p ActiveEnterTimestamp
```

O horário precisa ser posterior ao do deploy. Se for anterior, o worker ainda é
o antigo.

## O serviço do WhatsApp é outro processo

`gerenciador-whatsapp` roda o Node e mantém a sessão do navegador. Mudança no
`whatsapp-service/` exige `npm run build` e reinício **dele**, não da fila:

```bash
cd whatsapp-service && npx tsc
sudo systemctl restart gerenciador-whatsapp
```

A sessão volta sozinha depois do reinício — veja `whatsapp-systemd.md`.
