import pino from 'pino';
import { config } from '../config/env.js';

export const logger = pino({
  level: config.nodeEnv === 'production' ? 'info' : 'debug',
  base: {
    service: config.serviceName,
  },
  redact: ['req.headers.authorization', '*.qr_code', '*.token', '*.session', '*.cookies'],
});
