import { ConnectionStatus } from '../enums/ConnectionStatus.js';

export type StatusPayload = {
  status: ConnectionStatus;
  phone_number?: string | null;
  display_name?: string | null;
  connected_at?: string | null;
  last_activity_at?: string | null;
  browser_ready: boolean;
  session_available: boolean;
  error_code?: string | null;
  error_message?: string | null;
};

export type QrPayload = {
  status: ConnectionStatus;
  qr_code?: string | null;
  generated_at?: string | null;
  expires_at?: string | null;
};

export type ConnectionResultPayload = {
  status: ConnectionStatus;
  message?: string;
  error_code?: string | null;
  error_message?: string | null;
};

export type SendPayload = {
  request_id: string;
  phone: string;
  message: string;
};

export type IncomingMessagePayload = {
  event_id: string;
  provider: 'web';
  connection_id: string;
  external_message_id: string;
  sender_phone: string;
  sender_name?: string | null;
  recipient_phone?: string | null;
  message_type: string;
  text?: string | null;
  sent_at?: string | null;
  received_at: string;
  is_from_me: boolean;
  is_group: boolean;
  has_media: boolean;
  quoted_external_message_id?: string | null;
  metadata: Record<string, unknown>;
};

export type SendResultPayload = {
  request_id: string;
  external_message_id?: string | null;
  status: 'sent' | 'failed';
  sent_at?: string | null;
  error_code?: string | null;
  error_message?: string | null;
};

export type ConversationListOptions = {
  limit?: number;
  include_archived?: boolean;
};

export type ConversationSyncMode = 'standard' | 'compatibility';

export type ConversationMessagesOptions = {
  limit?: number;
  days?: number;
};

export type NormalizedConversation = {
  external_chat_id: string;
  phone: string;
  name?: string | null;
  is_group: boolean;
  is_archived: boolean;
  unread_count: number;
  last_message_at?: string | null;
};

export type NormalizedConversationMessage = {
  external_message_id: string;
  external_chat_id: string;
  direction: 'incoming' | 'outgoing';
  is_from_me: boolean;
  type: string;
  body?: string | null;
  sent_at?: string | null;
  has_media: boolean;
  metadata: Record<string, unknown>;
};

export type ConversationListResult = {
  conversations: NormalizedConversation[];
  sync_mode: ConversationSyncMode;
  normal_mode_ok: boolean;
  fallback_mode_ok: boolean;
  chats_found: number;
  chats_failed: number;
  collection_available: boolean;
  collection_count: number;
};

export type MessageMediaPayload = {
  external_message_id: string;
  mimetype: string | null;
  filename: string | null;
  bytes: number;
  // Conteudo em base64. Nao e persistido em lugar nenhum do servico: quem
  // chama transcreve e descarta.
  data: string;
};

export type ConversationDiagnosticsPayload = {
  ready: boolean;
  state: string | null;
  library_version: string;
  web_version: string | null;
  get_chats_available: boolean;
  chat_collection_available: boolean;
  chat_collection_count: number;
  normal_mode_ok: boolean;
  fallback_mode_ok: boolean;
  sync_mode: ConversationSyncMode;
};

export interface WhatsAppRuntime {
  health(): Record<string, unknown>;
  status(): StatusPayload;
  connect(): Promise<ConnectionResultPayload>;
  qrcode(): Promise<QrPayload>;
  reconnect(): Promise<ConnectionResultPayload>;
  disconnect(): Promise<ConnectionResultPayload>;
  clearSession(): Promise<ConnectionResultPayload>;
  sendTestMessage(payload: SendPayload): Promise<SendResultPayload>;
  listConversations(options: ConversationListOptions): Promise<ConversationListResult>;
  fetchConversationMessages(chatId: string, options: ConversationMessagesOptions): Promise<{ messages: NormalizedConversationMessage[] }>;
  fetchMessageMedia(chatId: string, messageId: string, maxBytes: number): Promise<MessageMediaPayload>;
  diagnosticsChats(): Promise<ConversationDiagnosticsPayload>;
  shutdown(): Promise<void>;
}
