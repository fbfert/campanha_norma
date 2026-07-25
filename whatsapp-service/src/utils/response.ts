import type { Response } from 'express';
import { randomUUID } from 'node:crypto';

export function ok(res: Response, data: Record<string, unknown>, requestId?: string) {
  return res.json({
    success: true,
    data,
    meta: {
      request_id: requestId ?? randomUUID(),
      timestamp: new Date().toISOString(),
    },
  });
}

export function fail(res: Response, status: number, code: string, message: string, requestId?: string) {
  return res.status(status).json({
    success: false,
    error: { code, message },
    meta: {
      request_id: requestId ?? randomUUID(),
      timestamp: new Date().toISOString(),
    },
  });
}
