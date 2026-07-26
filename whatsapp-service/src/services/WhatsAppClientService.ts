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
import type { ConnectionResultPayload, ConversationDiagnosticsPayload, ConversationListOptions, ConversationListResult, ConversationMessagesOptions, ConversationSyncMode, IncomingMessagePayload, NormalizedConversation, NormalizedConversationMessage, QrPayload, SendPayload, SendResultPayload, StatusPayload, WhatsAppRuntime } from '../types/WhatsAppService.js';

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
  getChats(): Promise<WhatsAppChat[]>;
  getChatById(chatId: string): Promise<WhatsAppChat | null | undefined>;
  getState(): Promise<string | null>;
  getWWebVersion(): Promise<string | null>;
  info?: { wid?: { user?: string }; pushname?: string };
  pupPage?: {
    evaluate<T>(pageFunction: (...args: any[]) => T | Promise<T>, ...args: any[]): Promise<T>;
  };
  on(event: string, callback: (...args: unknown[]) => void): void;
};

type WhatsAppChat = {
  id?: { _serialized?: string; user?: string; server?: string };
  name?: string;
  isGroup?: boolean;
  archived?: boolean;
  unreadCount?: number;
  timestamp?: number;
  lastMessage?: WhatsAppMessage;
  fetchMessages(options: { limit?: number; fromMe?: boolean }): Promise<WhatsAppMessage[]>;
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

type ChatSnapshot = {
  external_chat_id: string;
  phone: string | null;
  name: string | null;
  is_group: boolean;
  is_archived: boolean;
  unread_count: number;
  last_message_at: string | null;
};

type MessageSnapshot = {
  external_message_id: string | null;
  external_chat_id: string;
  is_from_me: boolean;
  direction: 'incoming' | 'outgoing';
  type: string;
  body: string | null;
  timestamp: string | null;
  has_media: boolean;
  metadata: Record<string, unknown>;
};

type ChatListResult = {
  chats: ChatSnapshot[];
  normal_mode_ok: boolean;
  fallback_mode_ok: boolean;
  sync_mode: ConversationSyncMode;
  chats_found: number;
  chats_failed: number;
  collection_available: boolean;
  collection_count: number;
};

let packageVersionPromise: Promise<string> | null = null;

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
      if (isWhatsAppWebPostSendUndefinedIdError(errorMessage)) {
        logger.warn({ event: 'send_confirmed_without_result', request_id: payload.request_id }, 'WhatsApp Web enviou a mensagem sem retornar identificador externo.');

        return this.idempotency.remember({
          request_id: payload.request_id,
          external_message_id: null,
          status: 'sent',
          sent_at: new Date().toISOString(),
        });
      }

      logger.warn({ event: 'send_failed', request_id: payload.request_id, error_message: errorMessage }, 'Falha ao enviar mensagem individual de teste.');
      throw new ServiceError('SEND_FAILED', 'Falha ao enviar mensagem individual de teste.', 502);
    }
  }

  async listConversations(options: ConversationListOptions): Promise<ConversationListResult> {
    if (this.statusValue !== ConnectionStatus.Connected || !this.client) {
      throw new ServiceError('WHATSAPP_NOT_CONNECTED', 'A conta do WhatsApp nao esta conectada.', 409);
    }

    const limit = Math.min(Math.max(options.limit ?? 100, 1), 500);
    const listResult = await this.listChatsSafely({
      limit,
      include_archived: Boolean(options.include_archived),
    });

    return {
      conversations: listResult.chats.slice(0, limit).map((chat) => this.normalizeChat(chat)),
      normal_mode_ok: listResult.normal_mode_ok,
      fallback_mode_ok: listResult.fallback_mode_ok,
      sync_mode: listResult.sync_mode,
      chats_found: listResult.chats_found,
      chats_failed: listResult.chats_failed,
      collection_available: listResult.collection_available,
      collection_count: listResult.collection_count,
    };
  }

  async fetchConversationMessages(chatId: string, options: ConversationMessagesOptions): Promise<{ messages: NormalizedConversationMessage[]; sync_mode: ConversationSyncMode }> {
    if (this.statusValue !== ConnectionStatus.Connected || !this.client) {
      throw new ServiceError('WHATSAPP_NOT_CONNECTED', 'A conta do WhatsApp nao esta conectada.', 409);
    }

    if (!/^[\w.-]+@(c\.us|lid)$/.test(chatId)) {
      throw new ServiceError('INVALID_REQUEST', 'Identificador de conversa invalido.', 422);
    }

    const limit = Math.min(Math.max(options.limit ?? 50, 1), 500);
    const days = Math.min(Math.max(options.days ?? 30, 1), 365);
    const since = Date.now() - days * 24 * 60 * 60 * 1000;
    const chat = await this.resolveChat(chatId);
    const listResult = await this.listMessagesSafely(chatId, limit, since, chat);

    return {
      messages: listResult.messages.map((message) => this.normalizeMessage(listResult.chat, message)),
      sync_mode: listResult.sync_mode,
    };
  }

  async diagnosticsChats(): Promise<ConversationDiagnosticsPayload> {
    const ready = this.statusValue === ConnectionStatus.Connected && Boolean(this.client);
    const state = this.client ? await this.safeGetState() : null;
    const webVersion = this.client ? await this.safeGetWebVersion() : null;
    const listResult = ready && this.client
      ? await this.listChatsSafely({ limit: 1, include_archived: true, diagnosticsOnly: true })
      : this.emptyChatListResult();

    return {
      ready,
      state,
      library_version: await this.getLibraryVersion(),
      web_version: webVersion,
      get_chats_available: ready && listResult.normal_mode_ok,
      chat_collection_available: listResult.collection_available,
      chat_collection_count: listResult.collection_count,
      normal_mode_ok: listResult.normal_mode_ok,
      fallback_mode_ok: listResult.fallback_mode_ok,
      sync_mode: listResult.sync_mode,
    };
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

  private normalizeChat(chat: WhatsAppChat | ChatSnapshot): NormalizedConversation {
    return this.normalizeChatSnapshot(this.snapshotChat(chat));
  }

  private normalizeChatSnapshot(snapshot: ChatSnapshot): NormalizedConversation {
    return {
      external_chat_id: snapshot.external_chat_id,
      phone: snapshot.phone ?? snapshot.external_chat_id.replace(/\D/g, ''),
      name: snapshot.name,
      is_group: snapshot.is_group,
      is_archived: snapshot.is_archived,
      unread_count: snapshot.unread_count,
      last_message_at: snapshot.last_message_at,
    };
  }

  private snapshotChat(chat: WhatsAppChat | ChatSnapshot): ChatSnapshot {
    if ('external_chat_id' in chat) {
      return chat;
    }

    const externalId = chat.id?._serialized ?? '';
    return {
      external_chat_id: externalId,
      phone: (chat.id?.user ?? externalId).replace(/\D/g, '') || null,
      name: this.pickChatName(chat),
      is_group: this.isGroupChat(chat, externalId),
      is_archived: Boolean(chat.archived),
      unread_count: Number(chat.unreadCount ?? 0),
      last_message_at: this.timestampToIso(chat.timestamp ?? chat.lastMessage?.timestamp ?? null),
    };
  }

  private normalizeMessage(chat: WhatsAppChat | ChatSnapshot, message: WhatsAppMessage | MessageSnapshot): NormalizedConversationMessage {
    return this.normalizeMessageSnapshot(this.snapshotMessage('external_chat_id' in chat ? chat.external_chat_id : chat.id?._serialized ?? '', message));
  }

  private normalizeMessageSnapshot(snapshot: MessageSnapshot): NormalizedConversationMessage {
    return {
      external_message_id: snapshot.external_message_id ?? cryptoRandomFallback(),
      external_chat_id: snapshot.external_chat_id,
      direction: snapshot.direction,
      is_from_me: snapshot.is_from_me,
      type: snapshot.type,
      body: snapshot.body,
      sent_at: snapshot.timestamp,
      has_media: snapshot.has_media,
      metadata: snapshot.metadata,
    };
  }

  private snapshotMessage(externalChatId: string, message: WhatsAppMessage | MessageSnapshot): MessageSnapshot {
    if ('external_message_id' in message) {
      return message;
    }

    const type = message.type ?? 'unknown';
    const isFromMe = Boolean(message.fromMe);

    return {
      external_message_id: message.id?._serialized ?? message.id?.id ?? null,
      external_chat_id: externalChatId || message.from || message.to || '',
      direction: isFromMe ? 'outgoing' : 'incoming',
      is_from_me: isFromMe,
      type: ['chat', 'text'].includes(type) ? 'text' : type,
      body: message.body ?? null,
      timestamp: this.timestampToIso(message.timestamp ?? null),
      has_media: Boolean(message.hasMedia),
      metadata: {
        type,
        has_media: Boolean(message.hasMedia),
      },
    };
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

  private async listChatsSafely(options: { limit: number; include_archived: boolean; diagnosticsOnly?: boolean }): Promise<ChatListResult> {
    if (!this.client) {
      throw new ServiceError('WHATSAPP_CLIENT_NOT_READY', 'Cliente do WhatsApp nao inicializado.', 409);
    }

    const limit = Math.min(Math.max(options.limit, 1), 500);
    const includeArchived = Boolean(options.include_archived);

    try {
      const chats = await this.client.getChats();
      const chatsFound = chats.length;
      const mapped = chats
        .map((chat) => this.snapshotChat(chat))
        .filter((chat): chat is ChatSnapshot => chat !== null)
        .filter((chat) => this.isIndividualChatId(chat.external_chat_id))
        .filter((chat) => includeArchived || !chat.is_archived)
        .slice(0, limit);

      return {
        chats: mapped,
        normal_mode_ok: true,
        fallback_mode_ok: false,
        sync_mode: 'standard',
        chats_found: chatsFound,
        chats_failed: chatsFound - mapped.length,
        collection_available: true,
        collection_count: chatsFound,
      };
    } catch (error) {
      this.logChatAccessError('WHATSAPP_GET_CHATS_FAILED', error);

      const fallback = await this.listChatsFromCollection(limit, includeArchived);
      if (fallback.collection_available) {
        return {
          ...fallback,
          sync_mode: 'compatibility',
          normal_mode_ok: false,
          fallback_mode_ok: true,
        };
      }

      throw new ServiceError('WHATSAPP_FALLBACK_FAILED', 'A consulta padrao dos chats falhou e o modo de compatibilidade nao estava disponivel.', 502);
    }
  }

  private async listChatsFromCollection(limit: number, includeArchived: boolean): Promise<ChatListResult> {
    if (!this.client?.pupPage) {
      throw new ServiceError('WHATSAPP_CHAT_COLLECTION_UNAVAILABLE', 'Colecao de chats indisponivel.', 502);
    }

    try {
      const snapshots = await this.client.pupPage.evaluate((maxItems) => {
        const collection = window.require('WAWebCollections')?.Chat;
        if (!collection || typeof collection.getModelsArray !== 'function') {
          throw new Error('CHAT_COLLECTION_UNAVAILABLE');
        }

        const models = collection.getModelsArray();
        return models.map((chat: any) => {
          try {
            const serializedId = chat?.id?._serialized ?? chat?.id?.toString?.() ?? null;
            if (!serializedId) {
              return null;
            }

            const server = chat?.id?.server ?? serializedId.split('@')[1] ?? null;
            const formattedTitle = chat?.formattedTitle ?? chat?.name ?? chat?.contact?.pushname ?? null;
            const timestamp = chat?.t ?? chat?.lastMessage?.t ?? null;
            return {
              external_chat_id: serializedId,
              phone: chat?.id?.user ?? serializedId.replace(/\D/g, ''),
              name: formattedTitle,
              is_group: Boolean(chat?.isGroup || chat?.groupMetadata || server === 'g.us'),
              is_archived: Boolean(chat?.archive ?? chat?.isArchived ?? false),
              unread_count: Number.isFinite(chat?.unreadCount) ? Number(chat.unreadCount) : 0,
              last_message_at: timestamp ? new Date(Number(timestamp) * 1000).toISOString() : null,
            };
          } catch {
            return null;
          }
        }).filter(Boolean);
      }, limit) as Array<ChatSnapshot | null>;

      const chats = snapshots
        .filter((chat): chat is ChatSnapshot => chat !== null)
        .filter((chat) => this.isIndividualChatId(chat.external_chat_id))
        .filter((chat) => includeArchived || !chat.is_archived)
        .slice(0, limit);

      return {
        chats,
        normal_mode_ok: false,
        fallback_mode_ok: true,
        sync_mode: 'compatibility',
        chats_found: chats.length,
        chats_failed: 0,
        collection_available: true,
        collection_count: snapshots.filter((chat): chat is ChatSnapshot => chat !== null).length,
      };
    } catch (error) {
      this.logChatAccessError('WHATSAPP_CHAT_COLLECTION_UNAVAILABLE', error);
      return {
        chats: [],
        normal_mode_ok: false,
        fallback_mode_ok: false,
        sync_mode: 'compatibility',
        chats_found: 0,
        chats_failed: 0,
        collection_available: false,
        collection_count: 0,
      };
    }
  }

  private async resolveChat(chatId: string): Promise<WhatsAppChat | null> {
    if (!this.client) {
      throw new ServiceError('WHATSAPP_CLIENT_NOT_READY', 'Cliente do WhatsApp nao inicializado.', 409);
    }

    try {
      const chat = await this.client.getChatById(chatId);
      if (chat && this.isIndividualChat(chat)) {
        return chat;
      }
    } catch (error) {
      this.logChatAccessError('WHATSAPP_CHAT_LOOKUP_FAILED', error, chatId);
    }

    return null;
  }

  private async listMessagesSafely(chatId: string, limit: number, since: number, chat: WhatsAppChat | null): Promise<{ chat: ChatSnapshot; messages: MessageSnapshot[]; sync_mode: ConversationSyncMode }> {
    if (chat) {
      try {
        const messages = await chat.fetchMessages({ limit });
        const filtered = messages
          .filter((message) => !message.isStatus)
          .filter((message) => !message.timestamp || message.timestamp * 1000 >= since)
          .map((message) => this.snapshotMessage(chatId, message))
          .filter((message) => Boolean(message.external_message_id));

        return { chat: this.snapshotChat(chat), messages: filtered, sync_mode: 'standard' };
      } catch (error) {
        this.logChatAccessError('WHATSAPP_CHAT_MESSAGES_FAILED', error, chatId);
      }
    }

    if (!this.client?.pupPage) {
      throw new ServiceError('WHATSAPP_CHAT_MESSAGES_FAILED', 'Mensagens da conversa indisponiveis.', 502);
    }

    try {
      const result = await this.client.pupPage.evaluate((targetChatId, maxItems, sinceTs) => {
        const collection = window.require('WAWebCollections')?.Chat;
        if (!collection || typeof collection.getModelsArray !== 'function') {
          throw new Error('CHAT_COLLECTION_UNAVAILABLE');
        }

        const chat = collection.getModelsArray().find((item: any) => {
          const serializedId = item?.id?._serialized ?? item?.id?.toString?.() ?? null;
          return serializedId === targetChatId;
        });

        if (!chat) {
          return null;
        }

        const serializedId = chat?.id?._serialized ?? chat?.id?.toString?.() ?? null;
        if (!serializedId) {
          return null;
        }

        const msgModels = chat?.msgs?.getModelsArray?.() ?? [];
        const messages = msgModels
          .filter((msg: any) => !msg?.isNotification && !msg?.isStatus)
          .filter((msg: any) => !msg?.timestamp || Number(msg.timestamp) * 1000 >= sinceTs)
          .slice(-Math.max(1, Number(maxItems) || 1))
          .map((msg: any) => {
            const externalMessageId = msg?.id?._serialized ?? msg?.id?.id ?? null;
            if (!externalMessageId) {
              return null;
            }

            const isFromMe = Boolean(msg?.id?.fromMe ?? msg?.fromMe ?? msg?.isSentByMe);
            const type = msg?.type ?? 'unknown';
            return {
              external_message_id: externalMessageId,
              external_chat_id: serializedId,
              is_from_me: isFromMe,
              direction: isFromMe ? 'outgoing' : 'incoming',
              type: ['chat', 'text'].includes(type) ? 'text' : type,
              body: msg?.body ?? msg?.caption ?? null,
              timestamp: msg?.timestamp ? new Date(Number(msg.timestamp) * 1000).toISOString() : null,
              has_media: Boolean(msg?.hasMedia ?? msg?.mediaObject),
              metadata: {
                type,
                has_media: Boolean(msg?.hasMedia ?? msg?.mediaObject),
              },
            };
          })
          .filter(Boolean);

        return {
          chat: {
            external_chat_id: serializedId,
            phone: chat?.id?.user ?? serializedId.replace(/\D/g, ''),
            name: chat?.formattedTitle ?? chat?.name ?? chat?.contact?.pushname ?? null,
            is_group: Boolean(chat?.isGroup || chat?.groupMetadata || serializedId.endsWith('@g.us')),
            is_archived: Boolean(chat?.archive ?? chat?.isArchived ?? false),
            unread_count: Number.isFinite(chat?.unreadCount) ? Number(chat.unreadCount) : 0,
            last_message_at: chat?.t ?? chat?.lastMessage?.t ?? null,
          },
          messages,
        };
      }, chatId, limit, since) as { chat: ChatSnapshot; messages: MessageSnapshot[] } | null;

      if (!result || !this.isIndividualChatId(result.chat.external_chat_id)) {
        throw new ServiceError('WHATSAPP_CHAT_NOT_FOUND', 'Conversa individual nao encontrada.', 404);
      }

      return { chat: result.chat, messages: result.messages.filter((message) => Boolean(message.external_message_id)), sync_mode: 'compatibility' };
    } catch (error) {
      if (error instanceof ServiceError) {
        throw error;
      }

      this.logChatAccessError('WHATSAPP_CHAT_MESSAGES_FAILED', error, chatId);
      throw new ServiceError('WHATSAPP_CHAT_MESSAGES_FAILED', 'Mensagens da conversa indisponiveis.', 502);
    }
  }

  private isIndividualChatId(id: string): boolean {
    if (!id) {
      return false;
    }

    const server = id.split('@')[1] ?? '';
    if (['g.us', 'broadcast', 'status', 'newsletter', 'channel', 'community'].includes(server)) {
      return false;
    }

    return id.endsWith('@c.us') || id.endsWith('@lid');
  }

  private isIndividualChat(chat: WhatsAppChat): boolean {
    return this.isIndividualChatId(chat.id?._serialized ?? '');
  }

  private isGroupChat(chat: WhatsAppChat, externalId: string): boolean {
    const server = externalId.split('@')[1] ?? '';
    return Boolean(chat.isGroup)
      || server === 'g.us'
      || server === 'broadcast'
      || server === 'status'
      || server === 'newsletter'
      || server === 'channel'
      || server === 'community';
  }

  private pickChatName(chat: WhatsAppChat): string | null {
    return chat.name ?? null;
  }

  private snapshotToChat(snapshot: ChatSnapshot): WhatsAppChat {
    return {
      id: {
        _serialized: snapshot.external_chat_id,
        user: snapshot.phone ?? snapshot.external_chat_id.replace(/\D/g, ''),
        server: snapshot.external_chat_id.split('@')[1] ?? undefined,
      },
      name: snapshot.name ?? undefined,
      isGroup: snapshot.is_group,
      archived: snapshot.is_archived,
      unreadCount: snapshot.unread_count,
      timestamp: snapshot.last_message_at ? Math.floor(Date.parse(snapshot.last_message_at) / 1000) : undefined,
      fetchMessages: async () => [],
    };
  }

  private timestampToIso(value: number | string | null | undefined): string | null {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const numeric = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(numeric)) {
      return null;
    }

    return new Date(Number(numeric) * 1000).toISOString();
  }

  private logChatAccessError(code: string, error: unknown, chatId?: string): void {
    const details = sanitizeError(error);
    logger.warn({
      event: 'chat_access_failed',
      error_code: code,
      error_name: details.name,
      error_message: details.message,
      stack_line: details.stackLine,
      chat_id_hash: chatId ? hashIdentifier(chatId) : undefined,
      state: this.statusValue,
    }, 'Falha ao acessar chats do WhatsApp Web.');
  }

  private async safeGetState(): Promise<string | null> {
    try {
      return await this.client?.getState?.() ?? null;
    } catch {
      return null;
    }
  }

  private async safeGetWebVersion(): Promise<string | null> {
    try {
      return await this.client?.getWWebVersion?.() ?? null;
    } catch {
      return null;
    }
  }

  private async getLibraryVersion(): Promise<string> {
    packageVersionPromise ??= fs.readFile(path.join(process.cwd(), 'package.json'), 'utf8')
      .then((content) => {
        const parsed = JSON.parse(content) as { dependencies?: Record<string, string> };
        return parsed.dependencies?.['whatsapp-web.js'] ?? 'unknown';
      })
      .catch(() => 'unknown');

    return packageVersionPromise;
  }

  private emptyChatListResult(): ChatListResult {
    return {
      chats: [],
      normal_mode_ok: false,
      fallback_mode_ok: false,
      sync_mode: 'compatibility',
      chats_found: 0,
      chats_failed: 0,
      collection_available: false,
      collection_count: 0,
    };
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

function sanitizeError(error: unknown): { name: string; message: string; stackLine: string | null } {
  if (error instanceof Error) {
    const stackLine = error.stack
      ?.split('\n')
      .map((line) => line.trim())
      .find((line, index) => index > 0 && line.length > 0) ?? null;

    return {
      name: error.name || 'Error',
      message: error.message || 'Erro desconhecido.',
      stackLine,
    };
  }

  return {
    name: 'Error',
    message: typeof error === 'string' ? error : 'Erro desconhecido.',
    stackLine: null,
  };
}

function hashIdentifier(value: string): string {
  return crypto.createHash('sha256').update(value).digest('hex').slice(0, 12);
}

export function isWhatsAppWebPostSendUndefinedIdError(message: string): boolean {
  return /Cannot read properties of undefined \(reading 'id'\)/i.test(message);
}
