<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingMessageJob;
use App\Services\IncomingMessages\IncomingWebhookSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppIncomingController extends Controller
{
    public function __invoke(Request $request, IncomingWebhookSignatureService $signature): JsonResponse
    {
        if (! config('whatsapp.incoming.enabled', true)) {
            return response()->json(['success' => false, 'error' => ['code' => 'INCOMING_DISABLED']], 403);
        }

        if (! str_contains((string) $request->header('Content-Type'), 'application/json')) {
            return response()->json(['success' => false, 'error' => ['code' => 'INVALID_CONTENT_TYPE']], 415);
        }

        if (strlen($request->getContent()) > (int) config('whatsapp.incoming.max_body_size', 262144)) {
            return response()->json(['success' => false, 'error' => ['code' => 'PAYLOAD_TOO_LARGE']], 413);
        }

        if (! $signature->validate($request)) {
            return response()->json(['success' => false, 'error' => ['code' => 'INVALID_SIGNATURE']], 401);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['success' => false, 'error' => ['code' => 'INVALID_PAYLOAD']], 422);
        }

        ProcessIncomingMessageJob::dispatch($payload)->onQueue(config('whatsapp.incoming.queue', 'whatsapp-incoming'));

        return response()->json(['success' => true, 'data' => ['queued' => true]]);
    }
}
