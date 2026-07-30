<?php

namespace App\Services\Ai;

use App\Models\SystemSetting;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuracao do provedor de IA vinda do banco.
 *
 * A Etapa 9B deixou provedor, modelo, URL e chave no arquivo de ambiente. Isso
 * funciona, mas exige acesso ao servidor para trocar de modelo. Esta classe
 * acrescenta uma segunda fonte, de prioridade maior, para que a troca seja
 * feita por quem opera o sistema.
 *
 * A ordem e: banco, depois `.env`. Uma chave em branco no banco nao apaga a do
 * ambiente, apenas nao a sobrescreve. Quem preferir manter tudo no `.env` nao
 * precisa fazer nada.
 *
 * A credencial e guardada cifrada com a `APP_KEY` e nunca volta para a tela nem
 * para o log de auditoria. O que a tela mostra e uma dica de quatro digitos,
 * suficiente para conferir qual chave esta ali e insuficiente para usa-la.
 */
class AiProviderSettings
{
    public const DISABLED = '';

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Sobrescreve a configuracao em memoria com o que estiver no banco.
     *
     * Chamado uma vez no boot. Os provedores continuam lendo `config()`, sem
     * saber que existe banco: manter um unico caminho de leitura evita que
     * metade do sistema enxergue uma configuracao e a outra metade enxergue
     * outra.
     */
    public function applyToConfig(): void
    {
        $provider = $this->provider();

        if ($provider !== self::DISABLED) {
            Config::set('ai.provider', 'openai');
            $this->override('ai.providers.openai.url', 'ai.url');
            $this->override('ai.providers.openai.model', 'ai.model');
            $this->override('ai.providers.openai.organization', 'ai.organization');
            $this->override('ai.timeout', 'ai.timeout');
            $this->override('ai.connect_timeout', 'ai.connect_timeout');
            $this->override('ai.max_output_tokens', 'ai.max_output_tokens');
            $this->override('ai.temperature', 'ai.temperature');
            $this->override('ai.cost.input_per_1k', 'ai.cost_input_per_1k');
            $this->override('ai.cost.output_per_1k', 'ai.cost_output_per_1k');

            if (($key = $this->key('ai.key')) !== null) {
                Config::set('ai.providers.openai.key', $key);
            }
        }

        if ($this->embeddingProvider() !== self::DISABLED) {
            Config::set('knowledge.embeddings.provider', 'openai');
            $this->override('knowledge.embeddings.openai.url', 'knowledge.embedding_url');
            $this->override('knowledge.embeddings.openai.model', 'knowledge.embedding_model');
            $this->override('knowledge.embeddings.openai.dimensions', 'knowledge.embedding_dimensions');

            if (($key = $this->key('knowledge.embedding_key')) !== null) {
                Config::set('knowledge.embeddings.openai.key', $key);
            }
        }
    }

    public function provider(): string
    {
        return (string) $this->settings->get('ai.provider', self::DISABLED);
    }

    public function embeddingProvider(): string
    {
        return (string) $this->settings->get('knowledge.embedding_provider', self::DISABLED);
    }

    /**
     * Grava o formulario.
     *
     * Campo de chave em branco preserva a chave atual: obrigar a redigitar o
     * segredo a cada ajuste de modelo levaria alguem a deixa-lo anotado em
     * algum lugar mais facil de ler que este banco.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string> valores anteriores, ja sem segredo
     */
    public function save(array $values): array
    {
        $previous = $this->auditable();

        foreach ($values as $key => $value) {
            if ($this->isSecret($key)) {
                if ($value === null || $value === '') {
                    continue;
                }

                $value = Crypt::encryptString((string) $value);
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => str($key)->before('.')->toString(),
                    'value' => (string) $value,
                    'type' => $this->isSecret($key) ? 'secret' : 'string',
                    'is_public' => false,
                ]
            );
        }

        $this->settings->forget();

        return $previous;
    }

    /**
     * Remove uma credencial guardada.
     */
    public function forgetKey(string $key): void
    {
        SystemSetting::query()->where('key', $key)->delete();
        $this->settings->forget();
    }

    /**
     * Estado atual para a tela, com as credenciais reduzidas a uma dica.
     *
     * @return array<string, mixed>
     */
    public function forForm(): array
    {
        return [
            'provider' => $this->provider(),
            'url' => (string) $this->settings->get('ai.url', ''),
            'model' => (string) $this->settings->get('ai.model', ''),
            'organization' => (string) $this->settings->get('ai.organization', ''),
            'key_hint' => $this->hint('ai.key'),
            'timeout' => (string) $this->settings->get('ai.timeout', (string) config('ai.timeout')),
            'connect_timeout' => (string) $this->settings->get('ai.connect_timeout', (string) config('ai.connect_timeout')),
            'max_output_tokens' => (string) $this->settings->get('ai.max_output_tokens', (string) config('ai.max_output_tokens')),
            'temperature' => (string) $this->settings->get('ai.temperature', (string) config('ai.temperature')),
            'cost_input_per_1k' => (string) $this->settings->get('ai.cost_input_per_1k', ''),
            'cost_output_per_1k' => (string) $this->settings->get('ai.cost_output_per_1k', ''),
            'embedding_provider' => $this->embeddingProvider(),
            'embedding_url' => (string) $this->settings->get('knowledge.embedding_url', ''),
            'embedding_model' => (string) $this->settings->get('knowledge.embedding_model', ''),
            'embedding_dimensions' => (string) $this->settings->get('knowledge.embedding_dimensions', ''),
            'embedding_key_hint' => $this->hint('knowledge.embedding_key'),
        ];
    }

    /**
     * Dica da credencial: os quatro ultimos caracteres, ou nulo quando nao ha
     * chave gravada no banco.
     */
    public function hint(string $key): ?string
    {
        $value = $this->key($key);

        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) <= 4
            ? str_repeat('*', mb_strlen($value))
            : str_repeat('*', 4).mb_substr($value, -4);
    }

    /**
     * Credencial em claro, so para quem vai efetivamente chamar o provedor.
     *
     * Devolve nulo quando nao ha chave gravada ou quando a `APP_KEY` mudou
     * desde a gravacao. Chave que nao decifra e chave inexistente: fingir que
     * existe produziria uma falha de autenticacao confusa la na frente.
     */
    public function key(string $key): ?string
    {
        $stored = (string) $this->settings->get($key, '');

        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Recorte do estado que pode ir para a auditoria, sem nenhuma credencial.
     *
     * @return array<string, string>
     */
    public function auditable(): array
    {
        $form = $this->forForm();

        unset($form['key_hint'], $form['embedding_key_hint']);

        return array_map(fn ($value): string => (string) $value, $form);
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return (array) config('ai.catalog', []);
    }

    private function isSecret(string $key): bool
    {
        return str_ends_with($key, '.key') || str_ends_with($key, '_key');
    }

    private function override(string $configKey, string $settingKey): void
    {
        $value = $this->settings->get($settingKey);

        if ($value === null || $value === '') {
            return;
        }

        $current = config($configKey);

        Config::set($configKey, match (true) {
            is_int($current) => (int) $value,
            is_float($current) => (float) $value,
            default => (string) $value,
        });
    }
}
