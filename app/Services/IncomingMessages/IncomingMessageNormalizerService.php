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
            /*
             | O tipo era conferido contra uma lista fechada, e o vocabulário
             | e do WhatsApp, não nosso. Nota de voz chega como `ptt` — que
             | ficou de fora — e era recusada aqui na porta: nunca virava
             | registro, nunca disparava job nenhum. Os áudios que existem no
             | banco só entraram depois, pela sincronização, que não passa por
             | aqui. Quem mandava áudio ficava sem resposta na hora.
             |
             | A lista também trazia `vídeo` acentuado, forma que o provedor
             | nunca envia: era uma entrada que não casava com nada.
             |
             | Recusar o desconhecido não protegia de nada. O que separa
             | mensagem de pessoa de ruído de protocolo e `PROTOCOL_TYPES`,
             | conferido logo adiante em `ProcessIncomingMessageJob`, com a
             | mesma lista que a sincronização usa. Aqui basta garantir que e
             | texto curto.
             */
            'message_type' => ['required', 'string', 'max:40'],
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
            // O provedor já traduz `chat` para `text`, mas quem grava o tipo no
            // banco e esta linha: a tradução fica onde o valor e decidido.
            'message_type' => $this->messageType($data['message_type']),
            'text' => isset($data['text']) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data['text'])) : null,
            // O serviço Node manda ISO-8601 em UTC. `Carbon::parse` preserva o
            // fuso da string, e o valor ia para o banco com o horário de
            // Greenwich: mensagem recebida às 19h aparecia como 22h na tela,
            // enquanto `created_at` — gravado pelo próprio Laravel — mostrava
            // 19h. Duas horas diferentes para o mesmo evento, na mesma linha.
            'sent_at' => isset($data['sent_at']) ? Carbon::parse($data['sent_at'])->setTimezone(config('app.timezone')) : null,
            'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at'])->setTimezone(config('app.timezone')) : now(),
            'is_from_me' => (bool) $data['is_from_me'],
            'is_group' => (bool) $data['is_group'],
            'has_media' => (bool) $data['has_media'],
            'quoted_external_message_id' => $data['quoted_external_message_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    /**
     * Tipo gravado no banco, na forma que o resto do sistema compara.
     *
     * `chat` e o nome que o WhatsApp dá a mensagem de texto comum. Tudo o mais
     * passa como veio, em minúsculas: e o que permite reconhecer `ptt` e
     * `audio` mais adiante, e o que faz um tipo novo do provedor chegar
     * inteiro em vez de sumir.
     */
    private function messageType(string $raw): string
    {
        $tipo = mb_strtolower(trim($raw));

        return $tipo === 'chat' ? 'text' : $tipo;
    }
}
