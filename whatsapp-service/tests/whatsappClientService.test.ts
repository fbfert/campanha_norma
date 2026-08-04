import { describe, expect, it } from 'vitest';
import { ConnectionStatus } from '../src/enums/ConnectionStatus.js';
import { WhatsAppClientService } from '../src/services/WhatsAppClientService.js';

function buildService(options: {
  chats?: any[];
  fallbackChats?: any[];
  messages?: any[];
  fallbackMessages?: any[];
  getChatsThrows?: boolean;
  getChatByIdThrows?: boolean;
} = {}) {
  const service = new WhatsAppClientService();
  const client = {
    info: { wid: { user: '554991888242' }, pushname: 'Conta Teste' },
    getChats: async () => {
      if (options.getChatsThrows) {
        throw new Error('r');
      }

      return options.chats ?? [];
    },
    getChatById: async (chatId: string) => {
      if (options.getChatByIdThrows) {
        throw new Error('chat lookup failed');
      }

      const chat = (options.chats ?? []).find((item) => item.id?._serialized === chatId);
      if (!chat) {
        return null;
      }

      return {
        ...chat,
        fetchMessages: async () => options.messages ?? [],
      };
    },
    getState: async () => 'CONNECTED',
    getWWebVersion: async () => '2.3000.0',
    pupPage: {
      evaluate: async (_fn: (...args: unknown[]) => unknown, ...args: unknown[]) => {
        if (args.length === 1) {
          return (options.fallbackChats ?? []).map((chat) => chat);
        }

        if (args.length === 3) {
          return {
            chat: (options.fallbackChats ?? [])[0] ?? null,
            messages: options.fallbackMessages ?? [],
          };
        }

        return null;
      },
    },
  };

  (service as any).client = client;
  (service as any).statusValue = ConnectionStatus.Connected;

  return service;
}

describe('WhatsAppClientService', () => {
  it('usa o modo padrao quando getChats funciona', async () => {
    const service = buildService({
      chats: [
        {
          id: { _serialized: '5549999999999@c.us', user: '5549999999999', server: 'c.us' },
          name: 'Contato Teste',
          isGroup: false,
          archived: false,
          unreadCount: 2,
          timestamp: 1784990000,
          fetchMessages: async () => [],
        },
        {
          id: { _serialized: '120363@g.us', user: '120363', server: 'g.us' },
          name: 'Grupo',
          isGroup: true,
          archived: false,
          unreadCount: 0,
          timestamp: 1784990000,
          fetchMessages: async () => [],
        },
      ],
    });

    const result = await service.listConversations({ limit: 10, include_archived: false });

    expect(result.sync_mode).toBe('standard');
    expect(result.normal_mode_ok).toBe(true);
    expect(result.fallback_mode_ok).toBe(false);
    expect(result.conversations).toHaveLength(1);
    expect(result.conversations[0].external_chat_id).toBe('5549999999999@c.us');
  });

  it('cai para compatibilidade quando getChats falha', async () => {
    const service = buildService({
      getChatsThrows: true,
      fallbackChats: [
        {
          external_chat_id: '5549999999999@c.us',
          phone: '5549999999999',
          name: 'Contato Teste',
          is_group: false,
          is_archived: false,
          unread_count: 3,
          last_message_at: new Date().toISOString(),
        },
        {
          external_chat_id: '120363@g.us',
          phone: '120363',
          name: 'Grupo Ignorado',
          is_group: true,
          is_archived: false,
          unread_count: 0,
          last_message_at: new Date().toISOString(),
        },
      ],
    });

    const result = await service.listConversations({ limit: 10, include_archived: false });

    expect(result.sync_mode).toBe('compatibility');
    expect(result.normal_mode_ok).toBe(false);
    expect(result.fallback_mode_ok).toBe(true);
    expect(result.conversations).toHaveLength(1);
    expect(result.conversations[0].external_chat_id).toBe('5549999999999@c.us');
  });

  it('retorna diagnostico sem expor dados pessoais', async () => {
    const service = buildService({
      getChatsThrows: true,
      fallbackChats: [
        {
          external_chat_id: '5549999999999@c.us',
          phone: '5549999999999',
          name: 'Contato Teste',
          is_group: false,
          is_archived: false,
          unread_count: 1,
          last_message_at: new Date().toISOString(),
        },
      ],
    });

    const diagnostics = await service.diagnosticsChats();

    expect(diagnostics.ready).toBe(true);
    expect(diagnostics.state).toBe('CONNECTED');
    expect(diagnostics.web_version).toBe('2.3000.0');
    expect(diagnostics.get_chats_available).toBe(false);
    expect(diagnostics.chat_collection_available).toBe(true);
    expect(diagnostics.sync_mode).toBe('compatibility');
    expect(JSON.stringify(diagnostics)).not.toContain('Contato Teste');
    expect(JSON.stringify(diagnostics)).not.toContain('5549999999999@c.us');
  });

  it('usa fallback de mensagens quando getChatById falha', async () => {
    const service = buildService({
      getChatsThrows: true,
      getChatByIdThrows: true,
      fallbackChats: [
        {
          external_chat_id: '5549999999999@c.us',
          phone: '5549999999999',
          name: 'Contato Teste',
          is_group: false,
          is_archived: false,
          unread_count: 1,
          last_message_at: new Date().toISOString(),
        },
      ],
      fallbackMessages: [
        {
          external_message_id: 'wamid.in.1',
          external_chat_id: '5549999999999@c.us',
          is_from_me: false,
          direction: 'incoming' as const,
          type: 'text',
          body: 'Sim, pode perguntar.',
          timestamp: new Date().toISOString(),
          has_media: false,
          metadata: { type: 'text', has_media: false },
        },
        {
          external_message_id: null,
          external_chat_id: '5549999999999@c.us',
          is_from_me: false,
          direction: 'incoming' as const,
          type: 'text',
          body: 'Ignorar sem id',
          timestamp: new Date().toISOString(),
          has_media: false,
          metadata: {},
        },
      ],
    });

    const result = await service.fetchConversationMessages('5549999999999@c.us', { limit: 10, days: 30 });

    expect(result.sync_mode).toBe('compatibility');
    expect(result.messages).toHaveLength(1);
    expect(result.messages[0].external_message_id).toBe('wamid.in.1');
    expect(result.messages[0].direction).toBe('incoming');
  });
});

/**
 * Reconexao automatica ao subir.
 *
 * O servico subia o HTTP e parava ai: a sessao do WhatsApp ficava
 * `not_initialized` ate alguem chamar `connect` a mao. Todo restart derrubava os
 * envios silenciosamente, porque de fora o servico respondia normalmente.
 *
 * A reconexao e condicional de proposito. Sem sessao gravada nao ha o que
 * retomar, e tentar so produziria um QR que ninguem pediu.
 */
describe('reconexao automatica ao subir', () => {
  it('nao tenta reconectar quando nao ha sessao gravada', async () => {
    const service = new WhatsAppClientService();
    let tentou = false;

    (service as any).hasStoredSession = async () => false;
    (service as any).connect = async () => {
      tentou = true;
      return { status: ConnectionStatus.Starting, message: '' };
    };

    await service.autoConnect();

    expect(tentou).toBe(false);
  });

  it('reconecta quando ha sessao gravada', async () => {
    const service = new WhatsAppClientService();
    let tentou = false;

    (service as any).hasStoredSession = async () => true;
    (service as any).connect = async () => {
      tentou = true;
      return { status: ConnectionStatus.Starting, message: '' };
    };

    await service.autoConnect();

    expect(tentou).toBe(true);
  });

  /**
   * Sessao invalida nao pode derrubar o processo: o operador ainda precisa da
   * tela de pareamento de pe para ler o QR.
   */
  it('falha na reconexao nao derruba o servico', async () => {
    const service = new WhatsAppClientService();

    (service as any).hasStoredSession = async () => true;
    (service as any).connect = async () => {
      throw new Error('sessao invalida');
    };

    await expect(service.autoConnect()).resolves.toBeUndefined();
  });
});

/**
 * Pagina morta se conserta sozinha.
 *
 * Quando o Chromium e morto por falta de memoria, o servico reconecta e a
 * sessao volta a dizer `connected` — mas a pagina fica morta, e toda chamada
 * que precisa avaliar codigo nela expira. Nada nesse estado se corrige com o
 * tempo: foram seis sincronizacoes seguidas falhando, com o status verde, ate
 * alguem reiniciar a mao.
 */
describe('recuperacao de pagina morta', () => {
  it('pagina que nao responde dispara reinicio do navegador', async () => {
    const service = buildService({ getChatsThrows: true });
    let reiniciou = false;

    (service as any).reconnect = async () => {
      reiniciou = true;
      return { status: ConnectionStatus.Starting, message: '' };
    };

    const erro = new Error('Runtime.callFunctionOn timed out.');
    erro.name = 'ProtocolError';
    (service as any).logChatAccessError('WHATSAPP_GET_CHATS_FAILED', erro);

    expect(reiniciou).toBe(true);
  });

  /**
   * Erro de negocio nao pode derrubar o navegador: chat inexistente e resposta
   * legitima, e reiniciar por causa dele criaria um ciclo de reinicios.
   */
  it('erro comum nao reinicia o navegador', async () => {
    const service = buildService();
    let reiniciou = false;

    (service as any).reconnect = async () => {
      reiniciou = true;
      return { status: ConnectionStatus.Starting, message: '' };
    };

    (service as any).logChatAccessError('WHATSAPP_CHAT_LOOKUP_FAILED', new Error('chat lookup failed'));

    expect(reiniciou).toBe(false);
  });
});
