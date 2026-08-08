<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingMessageJob;
use App\Services\IncomingMessages\MetaWebhookTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook da API oficial da Meta.
 *
 * Separado do webhook do serviço Node porque o contrato é da Meta, não nosso:
 * o formato do corpo, a forma de assinar e a verificação por desafio são
 * definidos por ela e não podem ser adaptados ao que já existe.
 */
class MetaWebhookController extends Controller
{
    /**
     * Verificação por desafio, feita uma vez ao cadastrar o webhook.
     *
     * A Meta chama com um token combinado e espera o desafio de volta em texto
     * puro. Devolver JSON aqui faz o cadastro falhar sem explicar por quê.
     */
    public function verify(Request $request): Response
    {
        $esperado = (string) config('whatsapp.meta.verify_token');

        if ($esperado === '' || ! hash_equals($esperado, (string) $request->query('hub_verify_token'))) {
            return response('', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaWebhookTranslator $translator): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            return response()->json(['error' => 'INVALID_PAYLOAD'], 422);
        }

        foreach ($translator->messages($payload) as $mensagem) {
            ProcessIncomingMessageJob::dispatch($mensagem)
                ->onQueue(config('whatsapp.incoming.queue', 'whatsapp-incoming'));
        }

        /*
         | Confirmação de entrega ainda não é aplicada, e por isso fica no log
         | em vez de sumir. Descartar em silêncio faria a próxima pessoa
         | acreditar que o recurso existe — foi assim que uma nota de voz passou
         | dias sendo recusada na validação sem deixar rastro.
         */
        foreach ($translator->statuses($payload) as $status) {
            Log::info('meta_webhook.status_received', $status + ['aplicado' => false]);
        }

        /*
         | A Meta reenvia enquanto não receber 200, e reenviar é pior que
         | perder: a mensagem já foi enfileirada. Por isso a resposta é sempre
         | 200 depois de enfileirar, e o que falhar depois falha na fila, com
         | tentativa própria.
         */
        return response()->json(['received' => true]);
    }

    /**
     * Assinatura da Meta: HMAC-SHA256 do corpo cru, com o segredo do app.
     *
     * Precisa ser do corpo **cru**. Reserializar o JSON muda espaços e ordem, e
     * a conta deixa de bater por um motivo que não aparece em lugar nenhum.
     */
    private function signatureIsValid(Request $request): bool
    {
        $segredo = (string) config('whatsapp.meta.app_secret');

        if ($segredo === '') {
            return false;
        }

        $enviada = (string) $request->header('X-Hub-Signature-256');
        $calculada = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($calculada, $enviada);
    }
}
