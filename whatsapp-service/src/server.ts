import { config } from './config/env.js';
import { createApp } from './app.js';
import { WhatsAppClientService } from './services/WhatsAppClientService.js';
import { logger } from './utils/logger.js';

const runtime = new WhatsAppClientService();
const app = createApp(runtime);

if (!config.serviceToken) {
  logger.error({ event: 'missing_service_token' }, 'SERVICE_TOKEN deve ser configurado.');
  process.exit(1);
}

const server = app.listen(config.port, config.host, () => {
  logger.info({ event: 'service_started', host: config.host, port: config.port }, 'Servico WhatsApp iniciado.');

  // Depois de o HTTP estar de pe, nunca antes: a reconexao leva dezenas de
  // segundos, e ate o QR ser lido a tela precisa conseguir responder.
  void runtime.autoConnect();
});

async function shutdown(signal: string) {
  logger.info({ event: 'shutdown', signal }, 'Encerrando servico WhatsApp.');
  server.close(async () => {
    await runtime.shutdown();
    process.exit(0);
  });
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
