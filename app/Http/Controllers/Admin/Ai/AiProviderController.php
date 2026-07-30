<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Data\Ai\AiCompletionRequest;
use App\Exceptions\Ai\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiProviderUpdateRequest;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de configuracao do provedor de IA.
 *
 * Reune numa pagina o que antes exigia editar o arquivo de ambiente e
 * reiniciar o servico: fornecedor, modelo, credencial e limites de transporte.
 *
 * A credencial entra, e cifrada e nunca mais sai. Nenhuma acao daqui devolve a
 * chave para a tela, para o log ou para a auditoria.
 */
class AiProviderController extends Controller
{
    public function edit(Request $request, AiProviderSettings $settings): View
    {
        abort_unless($request->user()->can('ai.provider.manage'), 403);

        return view('admin.ai-provider.edit', [
            'form' => $settings->forForm(),
            'catalog' => $settings->catalog(),
            // O `.env` continua valendo quando o banco esta vazio. Mostrar o
            // que ele traz evita a leitura errada de que nada esta configurado.
            'environment' => [
                'provider' => (string) config('ai.provider'),
                'model' => (string) config('ai.providers.openai.model'),
                'url' => (string) config('ai.providers.openai.url'),
                'has_key' => ((string) config('ai.providers.openai.key')) !== '',
            ],
        ]);
    }

    public function update(AiProviderUpdateRequest $request, AiProviderSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        if (($data['forget_key'] ?? false)) {
            $settings->forgetKey('ai.key');
        }

        if (($data['forget_embedding_key'] ?? false)) {
            $settings->forgetKey('knowledge.embedding_key');
        }

        $old = $settings->save([
            'ai.provider' => $data['provider'] ?? '',
            'ai.url' => $data['url'] ?? '',
            'ai.model' => $data['model'] ?? '',
            'ai.organization' => $data['organization'] ?? '',
            'ai.key' => $data['key'] ?? '',
            'ai.timeout' => $data['timeout'],
            'ai.connect_timeout' => $data['connect_timeout'],
            'ai.max_output_tokens' => $data['max_output_tokens'],
            'ai.temperature' => $data['temperature'],
            'ai.cost_input_per_1k' => $data['cost_input_per_1k'] ?? '',
            'ai.cost_output_per_1k' => $data['cost_output_per_1k'] ?? '',
            'knowledge.embedding_provider' => $data['embedding_provider'] ?? '',
            'knowledge.embedding_url' => $data['embedding_url'] ?? '',
            'knowledge.embedding_model' => $data['embedding_model'] ?? '',
            'knowledge.embedding_dimensions' => $data['embedding_dimensions'] ?? '',
            'knowledge.embedding_key' => $data['embedding_key'] ?? '',
        ]);

        app(AuditLogger::class)->log(
            'ai_provider.updated',
            'Configuracao do provedor de IA alterada.',
            null,
            $old,
            $settings->auditable(),
        );

        return redirect()
            ->route('admin.ai-provider.edit')
            ->with('success', 'Configuracao do provedor salva.');
    }

    /**
     * Chamada real ao fornecedor, disparada apenas por acao humana.
     *
     * Serve para separar "credencial errada" de "modelo inexistente" antes de
     * ligar a geracao para cidadaos. Usa um pedido minimo e descarta a
     * resposta: o objetivo e saber se o caminho responde, nao gerar texto.
     */
    public function test(Request $request, AiProviderManager $providers, AiProviderSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->can('ai.provider.manage'), 403);

        $provider = $providers->provider();

        if ($provider->name() === 'null') {
            return back()->with('error', 'Nenhum fornecedor configurado para testar.');
        }

        try {
            $result = $provider->complete(new AiCompletionRequest(
                systemPrompt: 'Responda apenas no formato pedido.',
                userPrompt: 'Responda com ok igual a true.',
                schemaName: 'teste_de_conexao',
                jsonSchema: [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                    'required' => ['ok'],
                    'additionalProperties' => false,
                ],
                maxOutputTokens: 64,
            ));
        } catch (AiProviderException $exception) {
            app(AuditLogger::class)->log(
                'ai_provider.tested',
                'Teste de conexao com o provedor de IA falhou.',
                null,
                null,
                ['resultado' => 'falha', 'codigo' => $exception->errorCode],
            );

            return back()->with('error', "Falha no teste: {$exception->errorCode}.");
        }

        app(AuditLogger::class)->log(
            'ai_provider.tested',
            'Teste de conexao com o provedor de IA.',
            null,
            null,
            ['resultado' => 'sucesso', 'modelo' => $result->model, 'latencia_ms' => $result->latencyMs],
        );

        return back()->with(
            'success',
            "Conexao respondeu em {$result->latencyMs} ms usando o modelo {$result->model}."
        );
    }
}
