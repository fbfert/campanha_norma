import type { SendResultPayload } from '../types/WhatsAppService.js';

export class IdempotencyStore {
  private readonly results = new Map<string, SendResultPayload>();

  get(requestId: string): SendResultPayload | undefined {
    return this.results.get(requestId);
  }

  remember(result: SendResultPayload): SendResultPayload {
    this.results.set(result.request_id, result);
    return result;
  }
}
