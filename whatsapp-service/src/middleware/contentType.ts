import type { NextFunction, Request, Response } from 'express';
import { fail } from '../utils/response.js';

export function requireJson(req: Request, res: Response, next: NextFunction) {
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(req.method) && Number(req.headers['content-length'] ?? 0) > 0 && req.is('application/json') === false) {
    return fail(res, 415, 'INVALID_REQUEST', 'Content-Type deve ser application/json.');
  }

  return next();
}
