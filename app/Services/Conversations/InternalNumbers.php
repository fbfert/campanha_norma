<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Services\SystemSettingService;

/**
 * Números da própria equipe, que nunca recebem tratamento automático.
 *
 * O sistema não sabe distinguir quem é atendido de quem atende. A conversa da
 * candidata com a equipe — almoço com o candidato a vice, estratégia, "para
 * abrir caminho para nós" — entrou no mesmo funil de quem responde a uma
 * pesquisa, e em 07/08/2026 ela recebeu "Recebemos sua mensagem, muito
 * obrigado! Nossa equipe vai ler com atenção." duas vezes no mesmo segundo.
 *
 * Não ha regra de conteúdo que resolva isso: naquele dia ela tinha escrito
 * "Oiii", que é o que qualquer eleitor escreve. O que distingue é quem está do
 * outro lado, e isso só uma lista de gente diz.
 *
 * A lista vale para as duas portas automáticas — a rede de segurança e o
 * atendimento de entrada. Não impede resposta manual, que é o que se quer numa
 * conversa de trabalho.
 */
class InternalNumbers
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return collect(preg_split('/[|,\r\n]+/', (string) $this->settings->get('conversations.internal_phones', '')) ?: [])
            ->map(fn (string $item): string => preg_replace('/\D/', '', $item) ?? '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Só os dígitos comparam.
     *
     * O telefone chega normalizado num lugar e digitado à mão no outro, e um
     * `+55 49 9...` que não casa com `5549...` é uma trava que existe no papel
     * e não impede nada.
     */
    public function contains(?string $phone): bool
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return $digits !== '' && in_array($digits, $this->all(), true);
    }

    public function coversConversation(?Conversation $conversation): bool
    {
        if (! $conversation) {
            return false;
        }

        return $this->contains($conversation->contact?->phone_normalized)
            || $this->contains($conversation->whatsappPhoneDigits());
    }
}
