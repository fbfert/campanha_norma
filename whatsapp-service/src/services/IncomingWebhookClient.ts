import crypto from 'node:crypto';
import { config } from '../config/env.js';
import { logger } from '../utils/logger.js';
import type { IncomingMessagePayload } from '../types/WhatsAppService.js';

export function signIncomingPayload(rawBody: string, timestamp: string, nonce: string, secret: string): string {
  return `sha256=${crypto.createHmac('sha256', secret).update(`${timestamp}.${nonce}.${rawBody}`).digest('hex')}`;
}

export class IncomingWebhookClient {
  async send(payload: IncomingMessagePayload): Promise<void> {
    if (!config.incomingMessageEnabled || !config.laravelIncomingWebhookUrl || !config.laravelIncomingWebhookSecret) {
      return;
    }

    const rawBody = JSON.stringify(payload);

    for (let attempt = 1; attempt <= config.incomingWebhookMaxAttempts; attempt += 1) {
      const timestamp = Math.floor(Date.now() / 1000).toString();
      const nonce = crypto.randomUUID();
      const signature = signIncomingPayload(rawBody, timestamp, nonce, config.laravelIncomingWebhookSecret);
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), config.laravelIncomingWebhookTimeoutMs);

      try {
        const response = await fetch(config.laravelIncomingWebhookUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Webhook-Timestamp': timestamp,
            'X-Webhook-Nonce': nonce,
            'X-Webhook-Signature': signature,
          },
          body: rawBody,
          signal: controller.signal,
        });

        if (response.ok) {
          logger.info({ event: 'incoming_webhook_sent', event_id: payload.event_id, external_message_id: payload.external_message_id }, 'Mensagem recebida encaminhada ao Laravel.');
          return;
        }

        if (response.status >= 400 && response.status < 500) {
          logger.warn({ event: 'incoming_webhook_rejected', status: response.status, event_id: payload.event_id }, 'Webhook de entrada rejeitado pelo Laravel.');
          return;
        }
      } catch (error) {
        logger.warn({ event: 'incoming_webhook_failed', attempt, event_id: payload.event_id, err: error }, 'Falha temporaria ao encaminhar mensagem recebida.');
      } finally {
        clearTimeout(timeout);
      }

      await new Promise((resolve) => setTimeout(resolve, config.incomingWebhookRetrySeconds * 1000));
    }
  }
}
