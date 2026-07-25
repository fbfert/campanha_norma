<?php

namespace App\Services\IncomingMessages;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IncomingWebhookSignatureService
{
    public function validate(Request $request): bool
    {
        $secret = (string) config('whatsapp.incoming.secret');
        if ($secret === '') {
            return false;
        }

        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
        $nonce = (string) $request->header('X-Webhook-Nonce', '');
        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('whatsapp.incoming.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $nonceKey = 'whatsapp:incoming:nonce:'.$nonce;
        if (! Cache::add($nonceKey, true, $tolerance)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function sign(string $rawBody, string $timestamp, string $nonce, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$rawBody, $secret);
    }
}
