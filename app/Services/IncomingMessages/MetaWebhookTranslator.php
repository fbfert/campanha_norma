<?php

namespace App\Services\IncomingMessages;

use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Traduz o webhook da Meta para o formato que o sistema já entende.
 *
 * A Meta manda um envelope com várias mensagens por requisição, e o resto do
 * sistema espera uma mensagem por vez, no mesmo formato que o serviço Node
 * entrega hoje. Traduzir aqui mantém `ProcessIncomingMessageJob` sem saber de
 * qual provedor a mensagem veio — que é o que permite os dois conviverem
 * durante a transição.
 */
class MetaWebhookTranslator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>> uma entrada por mensagem recebida
     */
    public function messages(array $payload): array
    {
        $mensagens = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $mensagem) {
                    $mensagens[] = $this->translate($mensagem, $value);
                }
            }
        }

        return $mensagens;
    }

    /**
     * Confirmações de entrega e leitura, que o WhatsApp Web nunca deu.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{external_message_id: string, status: string, timestamp: ?CarbonImmutable, error: ?array<string, mixed>}>
     */
    public function statuses(array $payload): array
    {
        $status = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $item) {
                    $status[] = [
                        'external_message_id' => (string) ($item['id'] ?? ''),
                        'status' => (string) ($item['status'] ?? 'unknown'),
                        'timestamp' => $this->moment($item['timestamp'] ?? null),
                        'error' => $item['errors'][0] ?? null,
                    ];
                }
            }
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function translate(array $mensagem, array $value): array
    {
        $tipo = (string) ($mensagem['type'] ?? 'unknown');
        $remetente = (string) ($mensagem['from'] ?? '');
        $identificador = (string) ($mensagem['id'] ?? '');

        return [
            /*
             | O identificador do evento é derivado do da mensagem, e não
             | sorteado.
             |
             | A Meta reenvia o webhook quando não recebe 200 rápido o
             | bastante, e um identificador novo a cada tentativa faria a mesma
             | mensagem entrar duas vezes — a checagem de duplicidade compara
             | justamente esses dois campos.
             */
            'event_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'meta:'.$identificador),
            'provider' => 'meta',
            'connection_id' => (string) ($value['metadata']['phone_number_id'] ?? ''),
            'external_message_id' => $identificador,
            'sender_phone' => $remetente,
            'sender_name' => $this->name($remetente, $value),
            'recipient_phone' => preg_replace('/\D+/', '', (string) ($value['metadata']['display_phone_number'] ?? '')),
            'message_type' => $tipo,
            'text' => $this->text($mensagem, $tipo),
            'received_at' => $this->moment($mensagem['timestamp'] ?? null)?->toIso8601String(),
            // A Cloud API entrega em `messages` apenas o que a pessoa mandou. O
            // que nós enviamos volta em `statuses`, que é outro caminho.
            'is_from_me' => false,
            // Não existe grupo na Cloud API.
            'is_group' => false,
            'has_media' => $this->hasMedia($tipo),
            /*
             | A Cloud API põe o alvo da reação em outro lugar.
             |
             | Citação vem em `context.id`; reação vem em
             | `reaction.message_id`. Ler só o primeiro descartava a mensagem
             | reagida, e sem ela a reação chegava aqui como um emoji pairando
             | sobre coisa nenhuma — impossível saber se respondia à pergunta de
             | permissão ou a uma mensagem de três semanas atrás.
             */
            'quoted_external_message_id' => $tipo === 'reaction'
                ? ($mensagem['reaction']['message_id'] ?? null)
                : ($mensagem['context']['id'] ?? null),
            'metadata' => array_filter([
                'type' => $tipo,
                'media_id' => $mensagem[$tipo]['id'] ?? null,
                'mime_type' => $mensagem[$tipo]['mime_type'] ?? null,
                // Nota de voz chega como `audio` com `voice`, e não como um
                // tipo próprio: sem isto não dá para distingui-la de um arquivo
                // de áudio anexado.
                'voice' => $mensagem['audio']['voice'] ?? null,
            ], fn ($valor): bool => $valor !== null),
        ];
    }

    /**
     * O texto que a pessoa escreveu, seja qual for o tipo.
     *
     * Legenda de imagem é texto, e ignorá-la faria a foto com pergunta escrita
     * embaixo virar mídia muda. Reação é um emoji, e é o que a pessoa disse.
     *
     * @param  array<string, mixed>  $mensagem
     */
    private function text(array $mensagem, string $tipo): ?string
    {
        return match ($tipo) {
            'text' => $mensagem['text']['body'] ?? null,
            'button' => $mensagem['button']['text'] ?? null,
            'reaction' => $mensagem['reaction']['emoji'] ?? null,
            'interactive' => $mensagem['interactive']['button_reply']['title']
                ?? $mensagem['interactive']['list_reply']['title']
                ?? null,
            default => $mensagem[$tipo]['caption'] ?? null,
        };
    }

    private function hasMedia(string $tipo): bool
    {
        return in_array($tipo, ['image', 'audio', 'video', 'document', 'sticker'], true);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function name(string $telefone, array $value): ?string
    {
        foreach ($value['contacts'] ?? [] as $contato) {
            if ((string) ($contato['wa_id'] ?? '') === $telefone) {
                return $contato['profile']['name'] ?? null;
            }
        }

        return null;
    }

    private function moment(mixed $timestamp): ?CarbonImmutable
    {
        // A Meta manda segundos desde a época, como texto.
        return blank($timestamp) ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }
}
