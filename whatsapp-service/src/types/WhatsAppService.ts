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

export interface WhatsAppRuntime {
  health(): Record<string, unknown>;
  status(): StatusPayload;
  connect(): Promise<ConnectionResultPayload>;
  qrcode(): Promise<QrPayload>;
  reconnect(): Promise<ConnectionResultPayload>;
  disconnect(): Promise<ConnectionResultPayload>;
  clearSession(): Promise<ConnectionResultPayload>;
  sendTestMessage(payload: SendPayload): Promise<SendResultPayload>;
  shutdown(): Promise<void>;
}
