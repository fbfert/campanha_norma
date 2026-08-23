import { describe, expect, it } from 'vitest';
import { ConnectionStatus } from '../src/enums/ConnectionStatus.js';
import { WhatsAppClientService } from '../src/services/WhatsAppClientService.js';

/**
 * Serviço com o encaminhamento interceptado.
 *
 * O que interessa aqui é o payload que sairia para o Laravel, e não a
 * requisição: a decisão de descartar ou encaminhar acontece antes dela.
 */
function buildService(): { service: WhatsAppClientService; enviados: any[] } {
  const service = new WhatsAppClientService();
  const enviados: any[] = [];

  (service as any).client = {
    info: { wid: { user: '554991888242' }, pushname: 'Conta Teste' },
  };
  (service as any).statusValue = ConnectionStatus.Connected;
  (service as any).incoming = { send: async (payload: any) => { enviados.push(payload); } };

  return { service, enviados };
}

function reacao(overrides: Record<string, unknown> = {}) {
  return {
    id: { _serialized: 'false_5549999999999@c.us_REACAO1' },
    msgId: { _serialized: 'true_5549999999999@c.us_PERGUNTA1', fromMe: true, remote: '5549999999999@c.us' },
    senderId: '5549999999999@c.us',
    reaction: '👍',
    timestamp: 1784990000,
    orphan: 0,
    ...overrides,
  };
}

describe('encaminhamento de reação', () => {
  it('encaminha a reação com o emoji no corpo e a mensagem reagida no alvo', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao());

    expect(enviados).toHaveLength(1);
    expect(enviados[0].message_type).toBe('reaction');
    expect(enviados[0].text).toBe('👍');
    expect(enviados[0].sender_phone).toBe('5549999999999');
    expect(enviados[0].quoted_external_message_id).toBe('true_5549999999999@c.us_PERGUNTA1');
    expect(enviados[0].external_message_id).toBe('false_5549999999999@c.us_REACAO1');
    expect(enviados[0].is_from_me).toBe(false);
    expect(enviados[0].has_media).toBe(false);
    expect(enviados[0].metadata.reaction.target_from_me).toBe(true);
  });

  it('descarta remoção de reação, que chega pelo mesmo evento com o emoji vazio', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao({ reaction: '' }));

    expect(enviados).toHaveLength(0);
  });

  it('descarta reação sem mensagem reagida, porque não há alvo a conferir', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao({ msgId: undefined }));

    expect(enviados).toHaveLength(0);
  });

  it('descarta reação órfã, que é reação antiga trazida na ressincronização', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao({ orphan: 1, orphanReason: 'no parent' }));

    expect(enviados).toHaveLength(0);
  });

  it('descarta reação de grupo', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao({
      senderId: '5549999999999@c.us',
      msgId: { _serialized: 'x', fromMe: true, remote: '120363@g.us' },
    }));

    expect(enviados).toHaveLength(0);
  });

  it('descarta reação nossa, feita pela equipe no celular conectado', async () => {
    const { service, enviados } = buildService();

    await (service as any).forwardReaction(reacao({ senderId: '554991888242@c.us' }));

    expect(enviados).toHaveLength(0);
  });
});
