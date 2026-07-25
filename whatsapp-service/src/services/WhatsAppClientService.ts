import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import qrcode from 'qrcode';
import whatsappWeb from 'whatsapp-web.js';
import { config } from '../config/env.js';
import { ConnectionStatus } from '../enums/ConnectionStatus.js';
import { ServiceError } from '../errors/ServiceError.js';
import { logger } from '../utils/logger.js';
import { IdempotencyStore } from './IdempotencyStore.js';
import { IncomingWebhookClient } from './IncomingWebhookClient.js';
import type { ConnectionResultPayload, IncomingMessagePayload, QrPayload, SendPayload, SendResultPayload, StatusPayload, WhatsAppRuntime } from '../types/WhatsAppService.js';

const { Client, LocalAuth } = whatsappWeb as unknown as {
  Client: new (options: Record<string, unknown>) => WhatsAppClient;
  LocalAuth: new (options: Record<string, unknown>) => unknown;
};

type WhatsAppClient = {
  initialize(): Promise<void>;
  destroy(): Promise<void>;
  logout(): Promise<void>;
  sendMessage(chatId: string, message: string): Promise<{ id?: { _serialized?: string } }>;
  getNumberId(phone: string): Promise<{ _serialized?: string } | null>;
  getState(): Promise<string | null>;
  info?: { wid?: { user?: string }; pushname?: string };
  on(event: string, callback: (...args: unknown[]) => void): void;
};

type WhatsAppMessage = {
  id?: { _serialized?: string; id?: string };
  from?: string;
  to?: string;
  author?: string;
  body?: string;
  type?: string;
  timestamp?: number;
  fromMe?: boolean;
  hasMedia?: boolean;
  isStatus?: boolean;
};

export class WhatsAppClientService implements WhatsAppRuntime {
  private client: WhatsAppClient | null = null;
  private statusValue = ConnectionStatus.NotInitialized;
  private qrCode: string | null = null;
  private qrGeneratedAt: Date | null = null;
  private qrExpiresAt: Date | null = null;
  private connectedAt: Date | null = null;
  private lastActivityAt: Date | null = null;
  private lastErrorCode: string | null = null;
  private lastErrorMessage: string | null = null;
  private reconnecting = false;

  constructor(private readonly idempotency = new IdempotencyStore(), private readonly incoming = new IncomingWebhookClient()) {}

  health() {
    return {
      service: config.serviceName,
      status: 'healthy',
      uptime_seconds: Math.floor(process.uptime()),
      timestamp: new Date().toISOString(),
    };
  }

  status(): StatusPayload {
    return {
      status: this.statusValue,
      phone_number: this.client?.info?.wid?.user ?? null,
      display_name: this.client?.info?.pushname ?? null,
      connected_at: this.connectedAt?.toISOString() ?? null,
      last_activity_at: this.lastActivityAt?.toISOString() ?? null,
      browser_ready: this.client !== null && ![ConnectionStatus.BrowserError, ConnectionStatus.ServiceError].includes(this.statusValue),
      session_available: this.client !== null || this.statusValue === ConnectionStatus.Connected,
      error_code: this.lastErrorCode,
      error_message: this.lastErrorMessage,
    };
  }

  async connect(): Promise<ConnectionResultPayload> {
    if ([ConnectionStatus.Starting, ConnectionStatus.GeneratingQr, ConnectionStatus.Authenticating].includes(this.statusValue)) {
      throw new ServiceError('CLIENT_ALREADY_STARTING', 'O cliente ja esta em inicializacao.', 409);
    }

    if (this.statusValue === ConnectionStatus.Connected) {
      return { status: this.statusValue, message: 'Cliente ja conectado.' };
    }

    await this.ensureDirectories();
    this.statusValue = ConnectionStatus.Starting;
    this.lastErrorCode = null;
    this.lastErrorMessage = null;

    try {
      this.client = this.makeClient();
      this.attachEvents(this.client);
      void this.client.initialize();

      return { status: this.statusValue, message: 'Inicializacao solicitada.' };
    } catch (error) {
      this.recordError(ConnectionStatus.BrowserError, 'BROWSER_START_FAILED', 'Falha ao iniciar o navegador.');
      logger.error({ event: 'browser_start_failed', err: error }, 'Falha ao iniciar o navegador.');
      throw new ServiceError('BROWSER_START_FAILED', 'Falha ao iniciar o navegador.', 500);
    }
  }

  async qrcode(): Promise<QrPayload> {
    if (!this.qrCode || !this.qrGeneratedAt || !this.qrExpiresAt) {
      if (!this.client) {
        await this.connect();
      }

      throw new ServiceError('QR_NOT_AVAILABLE', 'QR Code ainda nao disponivel.', 404);
    }

    if (this.qrExpiresAt.getTime() < Date.now()) {
      this.clearQr();
      this.statusValue = ConnectionStatus.SessionExpired;
      throw new ServiceError('QR_EXPIRED', 'QR Code expirado.', 410);
    }

    return {
      status: ConnectionStatus.WaitingForQrScan,
      qr_code: this.qrCode,
      generated_at: this.qrGeneratedAt.toISOString(),
      expires_at: this.qrExpiresAt.toISOString(),
    };
  }

  async reconnect(): Promise<ConnectionResultPayload> {
    if (this.reconnecting) {
      throw new ServiceError('CLIENT_ALREADY_STARTING', 'Reconexao ja esta em andamento.', 409);
    }

    this.reconnecting = true;
    this.statusValue = ConnectionStatus.Reconnecting;

    try {
      for (let attempt = 1; attempt <= config.maxReconnectAttempts; attempt += 1) {
        logger.info({ event: 'reconnect_attempt', attempt });
        await this.disconnect();
        await this.connect();
        await this.wait(config.reconnectIntervalSeconds * 1000);

        if ((this.statusValue as ConnectionStatus) === ConnectionStatus.Connected) {
          return { status: this.statusValue, message: 'Reconectado.' };
        }
      }

      this.recordError(ConnectionStatus.ServiceError, 'SESSION_EXPIRED', 'Nao foi possivel reconectar dentro do limite.');
      throw new ServiceError('SESSION_EXPIRED', 'Nao foi possivel reconectar dentro do limite.', 409);
    } finally {
      this.reconnecting = false;
    }
  }

  async disconnect(): Promise<ConnectionResultPayload> {
    this.statusValue = ConnectionStatus.Disconnecting;
    this.clearQr();

    if (this.client) {
      await this.client.destroy();
      this.client = null;
    }

    this.statusValue = ConnectionStatus.Disconnected;
    this.lastActivityAt = new Date();

    return { status: this.statusValue, message: 'Cliente desconectado.' };
  }

  async clearSession(): Promise<ConnectionResultPayload> {
    await this.disconnect();

    try {
      await fs.rm(config.sessionPath, { recursive: true, force: true });
      await this.ensureDirectories();
      this.statusValue = ConnectionStatus.NotInitialized;

      return { status: this.statusValue, message: 'Sessao removida.' };
    } catch (error) {
      this.recordError(ConnectionStatus.ServiceError, 'SESSION_DELETE_FAILED', 'Falha ao excluir a sessao.');
      logger.error({ event: 'session_delete_failed', err: error }, 'Falha ao excluir a sessao.');
      throw new ServiceError('SESSION_DELETE_FAILED', 'Falha ao excluir a sessao.', 500);
    }
  }

  async sendTestMessage(payload: SendPayload): Promise<SendResultPayload> {
    const previous = this.idempotency.get(payload.request_id);
    if (previous) {
      return previous;
    }

    if (!config.allowTestMessage) {
      throw new ServiceError('INVALID_REQUEST', 'Envio de teste desativado.', 403);
    }

    if (this.statusValue !== ConnectionStatus.Connected || !this.client) {
      throw new ServiceError('WHATSAPP_NOT_CONNECTED', 'A conta do WhatsApp nao esta conectada.', 409);
    }

    const phone = payload.phone.replace(/\D/g, '');
    if (phone.length < 10 || phone.length > 15) {
      throw new ServiceError('INVALID_PHONE', 'Telefone invalido.', 422);
    }

    if (!payload.message.trim()) {
      throw new ServiceError('EMPTY_MESSAGE', 'A mensagem nao pode ficar vazia.', 422);
    }

    if (payload.message.length > 4096) {
      throw new ServiceError('MESSAGE_TOO_LONG', 'Mensagem muito longa.', 422);
    }

    try {
      const numberId = await this.client.getNumberId(phone);
      if (!numberId?._serialized) {
        throw new ServiceError('INVALID_PHONE', 'O telefone informado nao foi reconhecido pelo WhatsApp Web.', 422);
      }

      const result = await this.client.sendMessage(numberId._serialized, payload.message);
      this.lastActivityAt = new Date();

      return this.idempotency.remember({
        request_id: payload.request_id,
        external_message_id: result?.id?._serialized ?? null,
        status: 'sent',
        sent_at: new Date().toISOString(),
      });
    } catch (error) {
      if (error instanceof ServiceError) {
        throw error;
      }

      const errorMessage = error instanceof Error ? error.message : String(error);
      logger.warn({ event: 'send_failed', request_id: payload.request_id, error_message: errorMessage }, 'Falha ao enviar mensagem individual de teste.');
      throw new ServiceError('SEND_FAILED', 'Falha ao enviar mensagem individual de teste.', 502);
    }
  }

  async shutdown(): Promise<void> {
    if (this.client) {
      await this.client.destroy();
      this.client = null;
    }
  }

  private makeClient(): WhatsAppClient {
    const args = config.browserNoSandbox ? ['--no-sandbox', '--disable-setuid-sandbox'] : [];

    return new Client({
      authStrategy: new LocalAuth({ dataPath: config.sessionPath }),
      puppeteer: {
        headless: config.browserHeadless,
        executablePath: config.browserExecutablePath,
        args,
      },
    });
  }

  private attachEvents(client: WhatsAppClient): void {
    client.on('qr', async (qr: unknown) => {
      this.statusValue = ConnectionStatus.GeneratingQr;
      this.qrGeneratedAt = new Date();
      this.qrExpiresAt = new Date(Date.now() + config.qrExpirationSeconds * 1000);
      this.qrCode = await qrcode.toDataURL(String(qr));
      this.statusValue = ConnectionStatus.WaitingForQrScan;
      logger.info({ event: 'qr_generated', status: this.statusValue });
    });

    client.on('authenticated', () => {
      this.statusValue = ConnectionStatus.Authenticating;
      this.clearQr();
      logger.info({ event: 'authenticated' });
    });

    client.on('ready', () => {
      this.statusValue = ConnectionStatus.Connected;
      this.connectedAt = new Date();
      this.lastActivityAt = new Date();
      this.clearQr();
      logger.info({ event: 'connected' });
    });

    client.on('disconnected', (reason: unknown) => {
      this.statusValue = ConnectionStatus.Disconnected;
      this.lastActivityAt = new Date();
      this.lastErrorMessage = typeof reason === 'string' ? reason : null;
      logger.warn({ event: 'disconnected', reason });
    });

    client.on('auth_failure', (message: unknown) => {
      this.recordError(ConnectionStatus.AuthenticationFailed, 'AUTHENTICATION_FAILED', String(message ?? 'Falha de autenticacao.'));
    });

    client.on('message', (message: unknown) => {
      void this.forwardIncoming(message as WhatsAppMessage);
    });

    client.on('message_create', (message: unknown) => {
      const msg = message as WhatsAppMessage;
      if (msg.fromMe) {
        void this.forwardIncoming(msg);
      }
    });
  }

  private async forwardIncoming(message: WhatsAppMessage): Promise<void> {
    if (!config.incomingMessageEnabled || message.isStatus) {
      return;
    }

    const from = message.from ?? '';
    const to = message.to ?? '';
    const isGroup = from.endsWith('@g.us') || to.endsWith('@g.us');

    if (isGroup) {
      logger.info({ event: 'incoming_group_ignored', external_message_id: message.id?._serialized });
      return;
    }

    const externalId = message.id?._serialized ?? message.id?.id ?? cryptoRandomFallback();
    const sender = (message.fromMe ? to : from).replace(/\D/g, '');
    const recipient = (message.fromMe ? from : to).replace(/\D/g, '');
    const timestamp = message.timestamp ? new Date(message.timestamp * 1000).toISOString() : new Date().toISOString();
    const type = message.type ?? 'unknown';

    const payload: IncomingMessagePayload = {
      event_id: cryptoRandomFallback(),
      provider: 'web',
      connection_id: 'principal',
      external_message_id: externalId,
      sender_phone: sender,
      sender_name: null,
      recipient_phone: recipient || this.client?.info?.wid?.user || null,
      message_type: ['chat', 'text'].includes(type) ? 'text' : type,
      text: config.incomingMessageLogBody ? message.body ?? null : message.body ?? null,
      sent_at: timestamp,
      received_at: new Date().toISOString(),
      is_from_me: Boolean(message.fromMe),
      is_group: false,
      has_media: Boolean(message.hasMedia),
      quoted_external_message_id: null,
      metadata: {
        type,
        has_media: Boolean(message.hasMedia),
      },
    };

    await this.incoming.send(payload);
  }

  private clearQr(): void {
    this.qrCode = null;
    this.qrGeneratedAt = null;
    this.qrExpiresAt = null;
  }

  private recordError(status: ConnectionStatus, code: string, message: string): void {
    this.statusValue = status;
    this.lastErrorCode = code;
    this.lastErrorMessage = message;
    this.lastActivityAt = new Date();
  }

  private async ensureDirectories(): Promise<void> {
    await fs.mkdir(config.sessionPath, { recursive: true });
    await fs.mkdir(config.logPath, { recursive: true });
  }

  private wait(ms: number): Promise<void> {
    return new Promise((resolve) => {
      setTimeout(resolve, ms);
    });
  }
}

function cryptoRandomFallback(): string {
  return crypto.randomUUID();
}
