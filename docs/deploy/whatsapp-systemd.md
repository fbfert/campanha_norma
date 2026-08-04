# systemd - WhatsApp Service

O Apache continua servindo apenas o Laravel. O serviço Node.js deve escutar somente em `127.0.0.1`.

Exemplo disponível em:

```text
whatsapp-service/deploy/gerenciador-whatsapp.service
```

Comandos:

```bash
sudo useradd --system --home /var/lib/gerenciador-whatsapp --shell /usr/sbin/nologin gerenciador-whatsapp
sudo mkdir -p /var/lib/gerenciador-whatsapp/session
sudo chown -R gerenciador-whatsapp:gerenciador-whatsapp /var/lib/gerenciador-whatsapp
sudo chmod -R 700 /var/lib/gerenciador-whatsapp
sudo cp whatsapp-service/deploy/gerenciador-whatsapp.service /etc/systemd/system/gerenciador-whatsapp.service
sudo systemctl daemon-reload
sudo systemctl enable gerenciador-whatsapp
sudo systemctl start gerenciador-whatsapp
sudo systemctl status gerenciador-whatsapp
```

## Memória

A unidade traz `MemoryHigh` e `MemoryMax`. Eles não liberam memória: tornam a
falha previsível.

Numa máquina apertada, faltando memória o kernel escolhe a vítima globalmente.
Aqui ele escolheu o Chromium três vezes num dia. O serviço reconectava sozinho e
a sessão voltava a dizer `connected` — mas a página do navegador ficava morta, e
toda chamada que precisa avaliar código nela expirava. Seis sincronizações
falharam ao longo de uma hora e vinte, com o status verde, até alguém reiniciar
à mão. Status verde com a página morta é pior que status vermelho, porque
ninguém vai olhar.

Com o limite, o estouro fica contido no cgroup do serviço, o `Restart=on-failure`
reinicia a unidade e a reconexão automática retoma a sessão.

Os valores ficam **acima do pico observado em operação normal** — cerca de 1 GB
numa sincronização de cem conversas. Apertá-los demais estrangula a operação
legítima em vez de proteger a máquina. Para conferir o pico real antes de
ajustar:

```bash
systemctl show gerenciador-whatsapp -p MemoryPeak -p MemoryCurrent
```

Aumentar o swap foi avaliado e descartado. Com o swap já cheio, mais swap troca
uma falha barulhenta — que a recuperação automática resolve — por uma
silenciosa: processo vivo e lento demais para funcionar.
