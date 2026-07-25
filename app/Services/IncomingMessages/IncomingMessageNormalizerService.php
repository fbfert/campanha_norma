<?php

namespace App\Services\IncomingMessages;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class IncomingMessageNormalizerService
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $validator = Validator::make($payload, [
            'event_id' => ['required', 'uuid'],
            'provider' => ['required', 'string', 'max:40'],
            'connection_id' => ['nullable', 'string', 'max:80'],
            'external_message_id' => ['required', 'string', 'max:255'],
            'sender_phone' => ['required', 'string', 'max:30'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'message_type' => ['required', 'string', 'in:text,unknown,unsupported,image,audio,video,document,location,contact,sticker'],
            'text' => ['nullable', 'string', 'max:4096'],
            'sent_at' => ['nullable', 'date'],
            'received_at' => ['nullable', 'date'],
            'is_from_me' => ['required', 'boolean'],
            'is_group' => ['required', 'boolean'],
            'has_media' => ['required', 'boolean'],
            'quoted_external_message_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        return [
            'event_id' => $data['event_id'],
            'provider' => $data['provider'],
            'connection_id' => $data['connection_id'] ?? null,
            'external_message_id' => $data['external_message_id'],
            'sender_phone' => preg_replace('/\D+/', '', $data['sender_phone']),
            'sender_name' => trim((string) ($data['sender_name'] ?? '')),
            'recipient_phone' => isset($data['recipient_phone']) ? preg_replace('/\D+/', '', $data['recipient_phone']) : null,
            'message_type' => in_array($data['message_type'], ['text', 'unknown', 'unsupported'], true) ? $data['message_type'] : $data['message_type'],
            'text' => isset($data['text']) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data['text'])) : null,
            'sent_at' => isset($data['sent_at']) ? Carbon::parse($data['sent_at']) : null,
            'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
            'is_from_me' => (bool) $data['is_from_me'],
            'is_group' => (bool) $data['is_group'],
            'has_media' => (bool) $data['has_media'],
            'quoted_external_message_id' => $data['quoted_external_message_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];
    }
}
