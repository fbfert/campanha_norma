import { Router } from 'express';
import type { WhatsAppRuntime } from '../types/WhatsAppService.js';
import { controller } from '../controllers/apiController.js';

function asyncHandler(fn: (...args: any[]) => Promise<unknown>) {
  return (req: any, res: any, next: any) => {
    Promise.resolve(fn(req, res, next)).catch(next);
  };
}

export function apiRoutes(runtime: WhatsAppRuntime) {
  const router = Router();
  const api = controller(runtime);

  router.get('/health', api.health);
  router.get('/status', api.status);
  router.post('/connect', asyncHandler(api.connect));
  router.get('/qrcode', asyncHandler(api.qrcode));
  router.post('/reconnect', asyncHandler(api.reconnect));
  router.post('/disconnect', asyncHandler(api.disconnect));
  router.get('/diagnostics/chats', asyncHandler(api.diagnosticsChats));
  router.delete('/session', asyncHandler(api.clearSession));
  router.post('/test-message', asyncHandler(api.sendTestMessage));
  router.get('/conversations', asyncHandler(api.conversations));
  router.get('/conversations/:chatId/messages', asyncHandler(api.conversationMessages));

  return router;
}
