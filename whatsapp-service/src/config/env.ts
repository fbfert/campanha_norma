import dotenv from 'dotenv';
import path from 'node:path';

dotenv.config();

const root = process.cwd();

export const config = {
  nodeEnv: process.env.NODE_ENV ?? 'development',
  host: process.env.HOST ?? '127.0.0.1',
  port: Number(process.env.PORT ?? 3100),
  serviceToken: process.env.SERVICE_TOKEN ?? '',
  serviceName: process.env.SERVICE_NAME ?? 'whatsapp-service',
  sessionPath: process.env.SESSION_PATH ?? path.join(root, 'storage', 'session'),
  logPath: process.env.LOG_PATH ?? path.join(root, 'storage', 'logs'),
  browserHeadless: (process.env.BROWSER_HEADLESS ?? 'true') === 'true',
  browserExecutablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined,
  browserNoSandbox: (process.env.BROWSER_NO_SANDBOX ?? 'false') === 'true',
  webVersionCacheUrl: process.env.WEB_VERSION_CACHE_URL || 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/2.3000.1040225260-alpha.html',
  requestTimeoutMs: Number(process.env.REQUEST_TIMEOUT_MS ?? 15000),
  qrExpirationSeconds: Number(process.env.QR_EXPIRATION_SECONDS ?? 60),
  maxReconnectAttempts: Number(process.env.MAX_RECONNECT_ATTEMPTS ?? 5),
  reconnectIntervalSeconds: Number(process.env.RECONNECT_INTERVAL_SECONDS ?? 15),
  allowTestMessage: (process.env.ALLOW_TEST_MESSAGE ?? 'true') === 'true',
  laravelIncomingWebhookUrl: process.env.LARAVEL_INCOMING_WEBHOOK_URL ?? '',
  laravelIncomingWebhookSecret: process.env.LARAVEL_INCOMING_WEBHOOK_SECRET ?? '',
  laravelIncomingWebhookTimeoutMs: Number(process.env.LARAVEL_INCOMING_WEBHOOK_TIMEOUT_MS ?? 10000),
  incomingWebhookMaxAttempts: Number(process.env.INCOMING_WEBHOOK_MAX_ATTEMPTS ?? 5),
  incomingWebhookRetrySeconds: Number(process.env.INCOMING_WEBHOOK_RETRY_SECONDS ?? 15),
  incomingMessageEnabled: (process.env.INCOMING_MESSAGE_ENABLED ?? 'true') === 'true',
  incomingMessageLogBody: (process.env.INCOMING_MESSAGE_LOG_BODY ?? 'false') === 'true',
};
