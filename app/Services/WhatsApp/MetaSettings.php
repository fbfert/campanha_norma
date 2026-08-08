<?php

namespace App\Services\WhatsApp;

use App\Models\SystemSetting;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuração da API oficial da Meta vinda do banco.
 *
 * A integração nasceu lendo tudo do arquivo de ambiente. Funciona, mas exige
 * acesso ao servidor e um reinício para cada ajuste — e os dados que faltam
 * aqui (número emissor, token, template aprovado) só existem depois que alguém
 * termina o cadastro no painel da Meta, que não é quem tem acesso ao servidor.
 *
 * A ordem é: banco, depois `.env`. Campo em branco no banco não apaga o do
 * ambiente, apenas não o sobrescreve. Quem preferir manter tudo no `.env` não
 * precisa fazer nada.
 *
 * O token e o segredo do app são guardados cifrados com a `APP_KEY` e nunca
 * voltam para a tela nem para a auditoria. O que a tela mostra é uma dica de
 * quatro dígitos, suficiente para conferir qual credencial está ali e
 * insuficiente para usá-la.
 *
 * O token de verificação é exceção deliberada: ele é inventado por nós e
 * precisa ser digitado no painel da Meta. Escondê-lo obrigaria a pessoa a
 * anotá-lo em algum lugar menos protegido que este banco.
 */
class MetaSettings
{
    /**
     * Credenciais que entram cifradas e não voltam.
     */
    private const SECRETS = ['whatsapp.meta_token', 'whatsapp.meta_app_secret'];

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Sobrescreve a configuração em memória com o que estiver no banco.
     *
     * Chamado uma vez no boot. O provedor, o webhook e o envio por template
     * continuam lendo `config()`, sem saber que existe banco: manter um único
     * caminho de leitura evita que o envio enxergue uma credencial e a
     * conferência da assinatura enxergue outra.
     */
    public function applyToConfig(): void
    {
        $this->override('whatsapp.meta.base_url', 'whatsapp.meta_base_url');
        $this->override('whatsapp.meta.api_version', 'whatsapp.meta_api_version');
        $this->override('whatsapp.meta.phone_number_id', 'whatsapp.meta_phone_number_id');
        $this->override('whatsapp.meta.business_account_id', 'whatsapp.meta_business_account_id');
        $this->override('whatsapp.meta.verify_token', 'whatsapp.meta_verify_token');
        $this->override('whatsapp.meta.invite_template', 'whatsapp.meta_invite_template');
        $this->override('whatsapp.meta.invite_language', 'whatsapp.meta_invite_language');
        $this->override('whatsapp.meta.timeout', 'whatsapp.meta_timeout');
        $this->override('whatsapp.meta.connect_timeout', 'whatsapp.meta_connect_timeout');

        if (($token = $this->secret('whatsapp.meta_token')) !== null) {
            Config::set('whatsapp.meta.token', $token);
        }

        if (($segredo = $this->secret('whatsapp.meta_app_secret')) !== null) {
            Config::set('whatsapp.meta.app_secret', $segredo);
        }
    }

    /**
     * Grava o formulário.
     *
     * Campo de credencial em branco preserva a credencial atual: obrigar a
     * redigitar o token a cada ajuste de template levaria alguém a deixá-lo
     * anotado em algum lugar mais fácil de ler que este banco.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string> valores anteriores, já sem credencial
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
    public function forgetSecret(string $key): void
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
            'base_url' => (string) $this->settings->get('whatsapp.meta_base_url', ''),
            'api_version' => (string) $this->settings->get('whatsapp.meta_api_version', ''),
            'phone_number_id' => (string) $this->settings->get('whatsapp.meta_phone_number_id', ''),
            'business_account_id' => (string) $this->settings->get('whatsapp.meta_business_account_id', ''),
            'verify_token' => (string) $this->settings->get('whatsapp.meta_verify_token', ''),
            'invite_template' => (string) $this->settings->get('whatsapp.meta_invite_template', ''),
            'invite_language' => (string) $this->settings->get('whatsapp.meta_invite_language', ''),
            'timeout' => (string) $this->settings->get('whatsapp.meta_timeout', ''),
            'connect_timeout' => (string) $this->settings->get('whatsapp.meta_connect_timeout', ''),
            'token_hint' => $this->hint('whatsapp.meta_token'),
            'app_secret_hint' => $this->hint('whatsapp.meta_app_secret'),
        ];
    }

    /**
     * O que vale agora, somando banco e ambiente.
     *
     * Existe porque a tela precisa dizer se a integração está pronta, e o campo
     * em branco não significa "não configurado" — pode estar vindo do `.env`.
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        return [
            'base_url' => (string) config('whatsapp.meta.base_url'),
            'api_version' => (string) config('whatsapp.meta.api_version'),
            'phone_number_id' => (string) config('whatsapp.meta.phone_number_id'),
            'business_account_id' => (string) config('whatsapp.meta.business_account_id'),
            'verify_token' => (string) config('whatsapp.meta.verify_token'),
            'invite_template' => (string) config('whatsapp.meta.invite_template'),
            'invite_language' => (string) config('whatsapp.meta.invite_language'),
            'has_token' => ((string) config('whatsapp.meta.token')) !== '',
            'has_app_secret' => ((string) config('whatsapp.meta.app_secret')) !== '',
        ];
    }

    /**
     * O que ainda falta para a integração funcionar, em português.
     *
     * Uma tela de configuração que só mostra campos deixa a pessoa adivinhar se
     * terminou. Esta lista é a resposta, e é a mesma condição que o envio e o
     * webhook conferem em tempo de execução.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $atual = $this->effective();
        $faltando = [];

        if ($atual['phone_number_id'] === '') {
            $faltando[] = 'Identificador do número emissor, que a Meta mostra no painel do WhatsApp.';
        }

        if (! $atual['has_token']) {
            $faltando[] = 'Token de acesso. Sem ele nenhuma mensagem sai.';
        }

        if (! $atual['has_app_secret']) {
            $faltando[] = 'Segredo do app. Sem ele todo webhook recebido é recusado, e nenhuma resposta entra.';
        }

        if ($atual['verify_token'] === '') {
            $faltando[] = 'Token de verificação. Sem ele o cadastro do webhook no painel da Meta não conclui.';
        }

        if ($atual['invite_template'] === '') {
            $faltando[] = 'Nome do template aprovado que abre a conversa. Sem ele o lote não tem como ser enviado.';
        }

        return $faltando;
    }

    /**
     * Dica da credencial: os quatro últimos caracteres, ou nulo quando não há
     * credencial gravada no banco.
     */
    public function hint(string $key): ?string
    {
        $value = $this->secret($key);

        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) <= 4
            ? str_repeat('*', mb_strlen($value))
            : str_repeat('*', 4).mb_substr($value, -4);
    }

    /**
     * Credencial em claro, guardada no banco.
     *
     * Devolve nulo quando não há credencial gravada ou quando a `APP_KEY` mudou
     * desde a gravação. Credencial que não decifra e credencial inexistente são
     * a mesma coisa: fingir que existe produziria uma falha de autenticação
     * confusa lá na frente.
     */
    public function secret(string $key): ?string
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

        unset($form['token_hint'], $form['app_secret_hint']);

        return array_map(fn ($value): string => (string) $value, $form);
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, self::SECRETS, true);
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
