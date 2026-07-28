import express from 'express';
import rateLimit from 'express-rate-limit';
import helmet from 'helmet';
import { auth } from './middleware/auth.js';
import { requireJson } from './middleware/contentType.js';
import { errorHandler } from './middleware/errorHandler.js';
import { apiRoutes } from './routes/api.js';
import type { WhatsAppRuntime } from './types/WhatsAppService.js';

export function createApp(runtime: WhatsAppRuntime) {
  const app = express();

  app.disable('x-powered-by');
  app.use(helmet());
  app.use(express.json({ limit: '256kb' }));
  app.use(requireJson);
  app.use('/api', rateLimit({ windowMs: 60_000, limit: 120, standardHeaders: true, legacyHeaders: false }));
  app.use('/api', auth);
  app.use('/api', apiRoutes(runtime));
  app.use(errorHandler);

  return app;
}
