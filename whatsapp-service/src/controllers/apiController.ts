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
  };
}
