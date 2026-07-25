import type { NextFunction, Request, Response } from 'express';
import { timingSafeEqual } from 'node:crypto';
import { config } from '../config/env.js';
import { fail } from '../utils/response.js';

function sameToken(provided: string, expected: string): boolean {
  if (!provided || !expected || provided.length !== expected.length) {
    return false;
  }

  return timingSafeEqual(Buffer.from(provided), Buffer.from(expected));
}

export function auth(req: Request, res: Response, next: NextFunction) {
  const header = req.header('authorization') ?? '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : '';
  const expected = process.env.SERVICE_TOKEN ?? config.serviceToken;

  if (!sameToken(token, expected)) {
    return fail(res, 401, 'UNAUTHORIZED_SERVICE_REQUEST', 'Requisicao interna nao autorizada.');
  }

  return next();
}
