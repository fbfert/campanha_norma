import type { Request, Response } from 'express';
import { z } from 'zod';
import { ServiceError } from '../errors/ServiceError.js';
import type { WhatsAppRuntime } from '../types/WhatsAppService.js';
import { ok } from '../utils/response.js';

const testMessageSchema = z.object({
  request_id: z.string().uuid(),
  phone: z.string().regex(/^\d{10,15}$/),
  message: z.string().trim().min(1).max(4096),
});

const conversationListSchema = z.object({
  limit: z.coerce.number().int().min(1).max(500).default(100),
  include_archived: z.union([z.literal('1'), z.literal('true'), z.literal('0'), z.literal('false')]).optional(),
});

const conversationMessagesSchema = z.object({
  chatId: z.string().trim().min(5).max(128).regex(/^[\w.-]+@(c\.us|lid)$/),
  limit: z.coerce.number().int().min(1).max(500).default(50),
  days: z.coerce.number().int().min(1).max(365).default(30),
});

const messageMediaSchema = z.object({
  chatId: z.string().trim().min(5).max(128).regex(/^[\w.-]+@(c\.us|lid)$/),
  messageId: z.string().trim().min(5).max(256),
  // Audio de pesquisa e curto. O teto protege a memoria do processo que
  // mantem a sessao do WhatsApp de pe.
  maxBytes: z.coerce.number().int().min(1024).max(26_214_400).default(16_777_216),
});

export function controller(runtime: WhatsAppRuntime) {
  return {
    health(_req: Request, res: Response) {
      return ok(res, runtime.health());
    },

    status(_req: Request, res: Response) {
      return ok(res, runtime.status());
    },

    async connect(_req: Request, res: Response) {
      return ok(res, await runtime.connect());
    },

    async qrcode(_req: Request, res: Response) {
      return ok(res, await runtime.qrcode());
    },

    async reconnect(_req: Request, res: Response) {
      return ok(res, await runtime.reconnect());
    },

    async disconnect(_req: Request, res: Response) {
      return ok(res, await runtime.disconnect());
    },

    async diagnosticsChats(_req: Request, res: Response) {
      return ok(res, await runtime.diagnosticsChats());
    },

    async clearSession(_req: Request, res: Response) {
      return ok(res, await runtime.clearSession());
    },

    async sendTestMessage(req: Request, res: Response) {
      const parsed = testMessageSchema.safeParse(req.body);
      if (!parsed.success) {
        throw new ServiceError('INVALID_REQUEST', 'Dados invalidos para mensagem de teste.', 422);
      }

      return ok(res, await runtime.sendTestMessage(parsed.data), parsed.data.request_id);
    },

    async conversations(req: Request, res: Response) {
      const parsed = conversationListSchema.safeParse(req.query);
      if (!parsed.success) {
        throw new ServiceError('INVALID_REQUEST', 'Parametros invalidos para listar conversas.', 422);
      }

      return ok(res, await runtime.listConversations({
        limit: parsed.data.limit,
        include_archived: ['1', 'true'].includes(String(parsed.data.include_archived)),
      }));
    },

    async conversationMessages(req: Request, res: Response) {
      const parsed = conversationMessagesSchema.safeParse({ ...req.query, chatId: req.params.chatId });
      if (!parsed.success) {
        throw new ServiceError('INVALID_REQUEST', 'Parametros invalidos para mensagens da conversa.', 422);
      }

      return ok(res, await runtime.fetchConversationMessages(parsed.data.chatId, {
        limit: parsed.data.limit,
        days: parsed.data.days,
      }));
    },

    async diagnosticsMedia(req: Request, res: Response) {
      const parsed = messageMediaSchema.safeParse({ chatId: req.params.chatId, messageId: req.params.messageId });

      if (!parsed.success) {
        throw new ServiceError('INVALID_REQUEST', 'Parametros invalidos para diagnostico de midia.', 422);
      }

      return ok(res, await runtime.diagnosticsMedia(parsed.data.chatId, parsed.data.messageId));
    },

    async messageMedia(req: Request, res: Response) {
      const parsed = messageMediaSchema.safeParse({ ...req.query, chatId: req.params.chatId, messageId: req.params.messageId });
      if (!parsed.success) {
        throw new ServiceError('INVALID_REQUEST', 'Parametros invalidos para a midia da mensagem.', 422);
      }

      return ok(res, await runtime.fetchMessageMedia(parsed.data.chatId, parsed.data.messageId, parsed.data.maxBytes));
    },
  };
}
