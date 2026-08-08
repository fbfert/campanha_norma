<x-layouts.app title="Meta API" breadcrumbs="Inicio / Sistema / Meta API">
    <section class="card">
        <p class="muted">
            Credenciais e endereços da API oficial do WhatsApp (Cloud API). O que for preenchido aqui vale sobre o arquivo
            de ambiente e passa a valer no próximo envio, sem reiniciar o serviço.
        </p>
        <p class="muted">
            O token de acesso e o segredo do app são guardados cifrados e nunca mais aparecem nesta tela: depois de salvar,
            o que se vê são os quatro últimos caracteres, o bastante para conferir qual credencial está ali.
        </p>

        @if(session('success'))
            <p class="alert alert-success">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p class="alert alert-error">{{ session('error') }}</p>
        @endif

        @if($missing)
            <div class="alert alert-warning">
                <strong>Falta para a integração funcionar:</strong>
                <ul>
                    @foreach($missing as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="alert alert-success">Tudo que a integração exige está preenchido.</p>
        @endif
    </section>

    <form method="post" action="{{ route('admin.whatsapp.meta-settings.update') }}">
        @csrf
        @method('put')

        <section class="card">
            <h2>Conta</h2>
            <p class="muted">Copiados do painel da Meta, em WhatsApp / Configuração da API.</p>

            <div class="grid grid-2">
                <div>
                    <label for="phone_number_id">Identificador do número emissor</label>
                    <input id="phone_number_id" name="phone_number_id" inputmode="numeric" maxlength="40"
                        value="{{ old('phone_number_id', $form['phone_number_id']) }}"
                        placeholder="{{ $effective['phone_number_id'] ?: 'Só dígitos. Não é o telefone.' }}">
                    @error('phone_number_id')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="business_account_id">Identificador da conta (WABA)</label>
                    <input id="business_account_id" name="business_account_id" inputmode="numeric" maxlength="40"
                        value="{{ old('business_account_id', $form['business_account_id']) }}"
                        placeholder="{{ $effective['business_account_id'] ?: 'Só dígitos.' }}">
                    @error('business_account_id')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div style="margin-top:12px;">
                <label for="token">Token de acesso</label>
                <input id="token" name="token" type="password" maxlength="1000" autocomplete="new-password"
                    placeholder="{{ $form['token_hint'] ? 'Guardado: '.$form['token_hint'].'. Deixe em branco para manter.' : 'Cole o token aqui.' }}">
                @error('token')<p class="alert alert-error">{{ $message }}</p>@enderror
                @if($form['token_hint'])
                    <label><input type="checkbox" name="forget_token" value="1"> Apagar o token guardado</label>
                @endif
            </div>
        </section>

        <section class="card">
            <h2>Webhook</h2>
            <p class="muted">
                Cadastre este endereço no painel da Meta, em Webhooks, e assine o campo <code>messages</code>:
                <code>{{ $webhookUrl }}</code>
            </p>

            <div class="grid grid-2">
                <div>
                    <label for="app_secret">Segredo do app</label>
                    <input id="app_secret" name="app_secret" type="password" maxlength="500" autocomplete="new-password"
                        placeholder="{{ $form['app_secret_hint'] ? 'Guardado: '.$form['app_secret_hint'].'. Deixe em branco para manter.' : 'Cole o segredo aqui.' }}">
                    @error('app_secret')<p class="alert alert-error">{{ $message }}</p>@enderror
                    @if($form['app_secret_hint'])
                        <label><input type="checkbox" name="forget_app_secret" value="1"> Apagar o segredo guardado</label>
                    @endif
                    <p class="muted">Confere a assinatura de tudo que chega. Sem ele, toda mensagem recebida é recusada.</p>
                </div>
                <div>
                    <label for="verify_token">Token de verificação</label>
                    <input id="verify_token" name="verify_token" maxlength="190"
                        value="{{ old('verify_token', $form['verify_token']) }}"
                        placeholder="{{ $effective['verify_token'] ?: 'Invente um e repita no painel da Meta.' }}">
                    @error('verify_token')<p class="alert alert-error">{{ $message }}</p>@enderror
                    <p class="muted">
                        Este é escolhido por você e precisa ser digitado igual no painel da Meta, por isso fica visível.
                    </p>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Abordagem</h2>
            <p class="muted">
                Fora da janela de 24 horas aberta pela pessoa, só sai template aprovado pela Meta. É por ele que todo lote
                começa a conversa.
            </p>

            <div class="grid grid-2">
                <div>
                    <label for="invite_template">Nome do template aprovado</label>
                    <input id="invite_template" name="invite_template" maxlength="190"
                        value="{{ old('invite_template', $form['invite_template']) }}"
                        placeholder="{{ $effective['invite_template'] ?: 'convite_pergunta_unica' }}">
                    @error('invite_template')<p class="alert alert-error">{{ $message }}</p>@enderror
                    <p class="muted">Exatamente como foi aprovado: minúsculas, dígitos e sublinhado.</p>
                </div>
                <div>
                    <label for="invite_language">Idioma do template</label>
                    <input id="invite_language" name="invite_language" maxlength="10"
                        value="{{ old('invite_language', $form['invite_language']) }}"
                        placeholder="{{ $effective['invite_language'] ?: 'pt_BR' }}">
                    @error('invite_language')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Transporte</h2>
            <p class="muted">Deixe em branco para usar o padrão do arquivo de ambiente.</p>

            <div class="grid grid-2">
                <div>
                    <label for="base_url">Endereço da API</label>
                    <input id="base_url" name="base_url" maxlength="255"
                        value="{{ old('base_url', $form['base_url']) }}"
                        placeholder="{{ $effective['base_url'] }}">
                    @error('base_url')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="api_version">Versão da API</label>
                    <input id="api_version" name="api_version" maxlength="20"
                        value="{{ old('api_version', $form['api_version']) }}"
                        placeholder="{{ $effective['api_version'] }}">
                    @error('api_version')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="timeout">Tempo limite da resposta (segundos)</label>
                    <input id="timeout" name="timeout" type="number" min="1" max="300"
                        value="{{ old('timeout', $form['timeout']) }}"
                        placeholder="{{ config('whatsapp.meta.timeout') }}">
                    @error('timeout')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="connect_timeout">Tempo limite da conexão (segundos)</label>
                    <input id="connect_timeout" name="connect_timeout" type="number" min="1" max="60"
                        value="{{ old('connect_timeout', $form['connect_timeout']) }}"
                        placeholder="{{ config('whatsapp.meta.connect_timeout') }}">
                    @error('connect_timeout')<p class="alert alert-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <div class="actions">
            <button class="btn" type="submit"><x-icon name="check" size="16" />Salvar</button>
            <a class="btn ghost" href="{{ route('admin.whatsapp.connection') }}">Conexão WhatsApp</a>
        </div>
    </form>
</x-layouts.app>
