import type { NextFunction, Request, Response } from 'express';
import { ServiceError } from '../errors/ServiceError.js';
import { logger } from '../utils/logger.js';
import { fail } from '../utils/response.js';

export function errorHandler(err: unknown, req: Request, res: Response, _next: NextFunction) {
  const requestId = typeof req.body?.request_id === 'string' ? req.body.request_id : undefined;

  if (err instanceof ServiceError) {
    logger.warn({ event: 'request_failed', error_code: err.code, request_id: requestId }, String(err.message));
    return fail(res, err.statusCode, err.code, err.message, requestId);
  }

  logger.error({ event: 'unhandled_error', err }, 'Erro interno nao tratado.');
  return fail(res, 500, 'INTERNAL_ERROR', 'Erro interno no servico do WhatsApp.');
}
