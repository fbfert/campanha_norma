# systemd - WhatsApp Service

O Apache continua servindo apenas o Laravel. O servico Node.js deve escutar somente em `127.0.0.1`.

Exemplo disponivel em:

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
