import crypto from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { signIncomingPayload } from '../src/services/IncomingWebhookClient.js';

describe('incoming webhook signing', () => {
  it('gera assinatura HMAC SHA-256 com timestamp nonce e corpo', () => {
    const raw = JSON.stringify({ event_id: '2f2bce01-e176-4f06-8a2d-e23e147c01a4' });
    const expected = `sha256=${crypto.createHmac('sha256', 'segredo').update(`123.nonce.${raw}`).digest('hex')}`;

    expect(signIncomingPayload(raw, '123', 'nonce', 'segredo')).toBe(expected);
  });
});
