<?php

namespace App\Services\WhatsApp;

use App\Contracts\SendsTemplates;
use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\SendResult;
use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor da API oficial do WhatsApp, a Cloud API da Meta.
 *
 * Diferente do WhatsApp Web em três coisas que mudam o sistema, e não apenas o
 * código:
 *
 * Não há sessão. A autenticação é por credencial permanente, então não existe
 * QR Code para ler, reconectar nem limpar — por isso este provedor não
 * implementa `PairsBySession`.
 *
 * Não há histórico. Chega o que o webhook entregar, e o que se perdeu está
 * perdido: nada de `ReadsConversationHistory`, e a sincronização periódica
 * deixa de fazer sentido.
 *
 * Não há mensagem livre para quem não falou primeiro. Fora da janela aberta
 * pela própria pessoa, só sai template aprovado — daí `SendsTemplates`.
 *
 * O envio de texto livre continua existindo e é o que o fluxo usa depois que a
 * pessoa responde, que é onde a conversa acontece: a mediana das nossas
 * conversas é de dezessete minutos.
 */
class MetaCloudProvider implements SendsTemplates, WhatsAppProvider
{
    public function getStatus(): ConnectionStatus
    {
        if (! $this->configured()) {
            return new ConnectionStatus(
                status: WhatsAppConnectionStatus::NotInitialized,
                errorCode: 'META_NOT_CONFIGURED',
                errorMessage: 'Credenciais da API oficial ausentes: informe o identificador do número e o token.',
            );
        }

        $numero = (string) config('whatsapp.meta.phone_number_id');
        $resposta = $this->get($numero, ['fields' => 'display_phone_number,verified_name,quality_rating']);

        return new ConnectionStatus(
            status: WhatsAppConnectionStatus::Connected,
            phoneNumber: $resposta['display_phone_number'] ?? null,
            displayName: $resposta['verified_name'] ?? null,
            lastActivityAt: CarbonImmutable::now(),
            // A credencial não expira sozinha e não há navegador: para o resto
            // do sistema, estar configurado e responder já é estar de pé.
            sessionAvailable: true,
            metadata: ['quality_rating' => $resposta['quality_rating'] ?? null],
        );
    }

    public function sendMessage(string $phone, string $message, string $requestId): SendResult
    {
        return $this->send($requestId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ]);
    }

    public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
    {
        return $this->sendMessage($phone, $message, $requestId);
    }

    public function sendTemplate(
        string $phone,
        string $template,
        array $parameters,
        string $requestId,
        string $language = 'pt_BR',
    ): SendResult {
        $componentes = [];

        if ($parameters !== []) {
            $componentes[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $valor): array => ['type' => 'text', 'text' => $valor],
                    array_values($parameters),
                ),
            ];
        }

        return $this->send($requestId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $componentes ?: null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $requestId, array $payload): SendResult
    {
        $numero = (string) config('whatsapp.meta.phone_number_id');
        $resposta = $this->post("{$numero}/messages", $payload);

        return new SendResult(
            requestId: $requestId,
            status: 'sent',
            externalMessageId: $resposta['messages'][0]['id'] ?? null,
            // A Meta não devolve horário de envio: ela aceita e confirma
            // depois, por webhook de status. O instante do aceite é o que
            // temos, e é honesto — o horário real de entrega chega separado.
            sentAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->handle(fn (): Response => $this->client()->get($this->url($path), $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->handle(fn (): Response => $this->client()->post($this->url($path), $payload));
    }

    /**
     * @param  callable(): Response  $chamada
     * @return array<string, mixed>
     */
    private function handle(callable $chamada): array
    {
        $this->assertConfigured();

        try {
            $resposta = $chamada();
        } catch (ConnectionException $excecao) {
            /*
             | Não alcançar a Meta e ela demorar demais são coisas diferentes, e
             | o Guzzle entrega as duas como a mesma exceção — a mesma confusão
             | que fez seis sincronizações falharem dizendo "indisponível"
             | enquanto o serviço respondia normalmente.
             */
            $tempoEsgotado = str_contains(mb_strtolower($excecao->getMessage()), 'timed out')
                || str_contains(mb_strtolower($excecao->getMessage()), 'timeout');

            throw new WhatsAppServiceException(
                $tempoEsgotado ? 'SERVICE_TIMEOUT' : 'SERVICE_UNAVAILABLE',
                $tempoEsgotado
                    ? 'A API do WhatsApp não respondeu a tempo.'
                    : 'Não foi possível alcançar a API do WhatsApp.',
                0,
                ['exception' => $excecao::class],
            );
        }

        if ($resposta->failed()) {
            $erro = (array) $resposta->json('error', []);

            /*
             | O código da Meta vai no `errorCode` porque é por ele que se
             | descobre o que fazer: 190 é token vencido, 131030 é número fora
             | da lista de testes, 132001 é template inexistente. Guardar só
             | "falhou" obrigaria a abrir log para cada caso.
             */
            throw new WhatsAppServiceException(
                'META_'.($erro['code'] ?? $resposta->status()),
                (string) ($erro['message'] ?? 'Falha na comunicação com a API do WhatsApp.'),
                $resposta->status(),
                [
                    'type' => $erro['type'] ?? null,
                    'subcode' => $erro['error_subcode'] ?? null,
                    // O identificador que o suporte da Meta pede para investigar.
                    'fbtrace_id' => $erro['fbtrace_id'] ?? null,
                ],
            );
        }

        return (array) $resposta->json();
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('whatsapp.meta.token'))
            ->acceptJson()
            ->timeout((int) config('whatsapp.meta.timeout'))
            ->connectTimeout((int) config('whatsapp.meta.connect_timeout'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.meta.base_url'), '/')
            .'/'.trim((string) config('whatsapp.meta.api_version'), '/')
            .'/'.ltrim($path, '/');
    }

    private function configured(): bool
    {
        return filled(config('whatsapp.meta.phone_number_id')) && filled(config('whatsapp.meta.token'));
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new WhatsAppServiceException(
                'META_NOT_CONFIGURED',
                'Credenciais da API oficial ausentes: informe o identificador do número e o token antes de enviar.',
            );
        }
    }

    /**
     * A Meta aceita só dígitos, e o número precisa trazer o código do país.
     *
     * Máscara, parêntese e traço vêm do cadastro e seriam recusados com erro
     * genérico, caro de diagnosticar depois. Já um número sem o código do país
     * é pior: ele não falha — a Meta entrega para outra pessoa, em outro país,
     * e ninguém descobre.
     *
     * Por isso o piso de doze dígitos, que é o mínimo brasileiro com código do
     * país. Recusar aqui custa uma mensagem não enviada; não recusar custa uma
     * mensagem enviada a um desconhecido.
     */
    private function normalizePhone(string $phone): string
    {
        $digitos = (string) preg_replace('/\D+/', '', $phone);

        if (mb_strlen($digitos) < 12) {
            throw new WhatsAppServiceException(
                'PHONE_WITHOUT_COUNTRY_CODE',
                'O telefone precisa incluir o código do país. Sem ele, a mensagem pode ser entregue a outra pessoa.',
                0,
                ['digits' => mb_strlen($digitos)],
            );
        }

        return $digitos;
    }
}
