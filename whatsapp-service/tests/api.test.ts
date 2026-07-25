import request from 'supertest';
import { beforeEach, describe, expect, it } from 'vitest';
import { createApp } from '../src/app.js';
import { ConnectionStatus } from '../src/enums/ConnectionStatus.js';
import { ServiceError } from '../src/errors/ServiceError.js';
import type { ConnectionResultPayload, QrPayload, SendPayload, SendResultPayload, StatusPayload, WhatsAppRuntime } from '../src/types/WhatsAppService.js';

process.env.SERVICE_TOKEN = 'token-teste';

class FakeRuntime implements WhatsAppRuntime {
  public state = ConnectionStatus.NotInitialized;
  public qr: QrPayload | null = null;
  public connectError: ServiceError | null = null;
  public sendError: ServiceError | null = null;
  public sent = new Map<string, SendResultPayload>();

  health() {
    return { service: 'whatsapp-service', status: 'healthy', uptime_seconds: 1, timestamp: new Date().toISOString() };
  }

  status(): StatusPayload {
    return { status: this.state, browser_ready: this.state !== ConnectionStatus.NotInitialized, session_available: this.state === ConnectionStatus.Connected };
  }

  async connect(): Promise<ConnectionResultPayload> {
    if (this.connectError) {
      throw this.connectError;
    }
    if (this.state === ConnectionStatus.Starting) {
      throw new ServiceError('CLIENT_ALREADY_STARTING', 'O cliente ja esta em inicializacao.', 409);
    }
    this.state = ConnectionStatus.Starting;
    return { status: this.state };
  }

  async qrcode(): Promise<QrPayload> {
    if (!this.qr) {
      throw new ServiceError('QR_NOT_AVAILABLE', 'QR Code ainda nao disponivel.', 404);
    }
    if (this.qr.expires_at && new Date(this.qr.expires_at).getTime() < Date.now()) {
      throw new ServiceError('QR_EXPIRED', 'QR Code expirado.', 410);
    }
    return this.qr;
  }

  async reconnect(): Promise<ConnectionResultPayload> {
    this.state = ConnectionStatus.Reconnecting;
    return { status: this.state };
  }

  async disconnect(): Promise<ConnectionResultPayload> {
    this.state = ConnectionStatus.Disconnected;
    return { status: this.state };
  }

  async clearSession(): Promise<ConnectionResultPayload> {
    this.state = ConnectionStatus.NotInitialized;
    return { status: this.state };
  }

  async sendTestMessage(payload: SendPayload): Promise<SendResultPayload> {
    const previous = this.sent.get(payload.request_id);
    if (previous) {
      return previous;
    }
    if (this.sendError) {
      throw this.sendError;
    }
    if (this.state !== ConnectionStatus.Connected) {
      throw new ServiceError('WHATSAPP_NOT_CONNECTED', 'A conta do WhatsApp nao esta conectada.', 409);
    }
    const result = { request_id: payload.request_id, external_message_id: 'msg-1', status: 'sent' as const, sent_at: new Date().toISOString() };
    this.sent.set(payload.request_id, result);
    return result;
  }

  async shutdown(): Promise<void> {}
}

describe('api privada WhatsApp', () => {
  let runtime: FakeRuntime;
  let app: ReturnType<typeof createApp>;

  beforeEach(() => {
    runtime = new FakeRuntime();
    app = createApp(runtime);
  });

  const authed = () => request(app).get('/api/health').set('Authorization', 'Bearer token-teste');

  it('responde health check autenticado', async () => {
    await authed().expect(200).expect((response) => {
      expect(response.body.success).toBe(true);
      expect(response.body.data.status).toBe('healthy');
    });
  });

  it('rejeita token ausente e invalido', async () => {
    await request(app).get('/api/health').expect(401);
    await request(app).get('/api/health').set('Authorization', 'Bearer errado').expect(401);
  });

  it('retorna status sem cliente inicializado', async () => {
    await request(app).get('/api/status').set('Authorization', 'Bearer token-teste').expect(200).expect((response) => {
      expect(response.body.data.status).toBe(ConnectionStatus.NotInitialized);
    });
  });

  it('impede conexao duplicada em andamento', async () => {
    runtime.state = ConnectionStatus.Starting;
    await request(app).post('/api/connect').set('Authorization', 'Bearer token-teste').expect(409);
  });

  it('retorna QR indisponivel e QR expirado', async () => {
    await request(app).get('/api/qrcode').set('Authorization', 'Bearer token-teste').expect(404);
    runtime.qr = { status: ConnectionStatus.WaitingForQrScan, qr_code: 'data:image/png;base64,abc', expires_at: new Date(Date.now() - 1000).toISOString() };
    await request(app).get('/api/qrcode').set('Authorization', 'Bearer token-teste').expect(410);
  });

  it('retorna QR disponivel', async () => {
    runtime.qr = { status: ConnectionStatus.WaitingForQrScan, qr_code: 'data:image/png;base64,abc', expires_at: new Date(Date.now() + 60000).toISOString() };
    await request(app).get('/api/qrcode').set('Authorization', 'Bearer token-teste').expect(200).expect((response) => {
      expect(response.body.data.qr_code).toContain('data:image/png');
    });
  });

  it('desconecta e exclui sessao', async () => {
    await request(app).post('/api/disconnect').set('Authorization', 'Bearer token-teste').expect(200);
    await request(app).delete('/api/session').set('Authorization', 'Bearer token-teste').expect(200);
  });

  it('valida telefone e mensagem', async () => {
    await request(app).post('/api/test-message').set('Authorization', 'Bearer token-teste').send({
      request_id: '2f2bce01-e176-4f06-8a2d-e23e147c01a1',
      phone: 'abc',
      message: 'Teste',
    }).expect(422);

    await request(app).post('/api/test-message').set('Authorization', 'Bearer token-teste').send({
      request_id: '2f2bce01-e176-4f06-8a2d-e23e147c01a2',
      phone: '5549999999999',
      message: '',
    }).expect(422);
  });

  it('bloqueia envio sem conexao', async () => {
    await request(app).post('/api/test-message').set('Authorization', 'Bearer token-teste').send({
      request_id: '2f2bce01-e176-4f06-8a2d-e23e147c01a3',
      phone: '5549999999999',
      message: 'Teste',
    }).expect(409);
  });

  it('envia mensagem individual e retorna resultado idempotente', async () => {
    runtime.state = ConnectionStatus.Connected;
    const payload = { request_id: '2f2bce01-e176-4f06-8a2d-e23e147c01a4', phone: '5549999999999', message: 'Teste' };
    const first = await request(app).post('/api/test-message').set('Authorization', 'Bearer token-teste').send(payload).expect(200);
    const second = await request(app).post('/api/test-message').set('Authorization', 'Bearer token-teste').send(payload).expect(200);
    expect(second.body.data.external_message_id).toBe(first.body.data.external_message_id);
    expect(runtime.sent.size).toBe(1);
  });

  it('trata erro do navegador', async () => {
    runtime.connectError = new ServiceError('BROWSER_START_FAILED', 'Falha ao iniciar o navegador.', 500);
    await request(app).post('/api/connect').set('Authorization', 'Bearer token-teste').expect(500);
  });
});
