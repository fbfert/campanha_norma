<x-layouts.app title="Provedor de IA" breadcrumbs="Inicio / Configuracoes / Provedor de IA">
    <section class="card">
        <p class="muted">
            Fornecedor, modelo e credencial usados pela interpretacao (9B), pela geracao de respostas (9C) e pela base de
            conhecimento (9D). A chave e guardada cifrada e nunca mais aparece nesta tela: o que se ve depois de salvar sao
            os quatro ultimos caracteres, o bastante para conferir qual credencial esta ali.
        </p>
        <p class="muted">
            Todos os fornecedores da lista falam o protocolo de chat da OpenAI. Os modelos Claude aparecem pelo OpenRouter,
            que os expoe nesse formato.
        </p>

        @if(session('success'))
            <p class="alert alert-success">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p class="alert alert-error">{{ session('error') }}</p>
        @endif

        @if($errors->any())
            <ul class="alert alert-error">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <div class="muted">
            <strong>Vindo do arquivo de ambiente:</strong>
            provedor <code>{{ $environment['provider'] }}</code>,
            modelo <code>{{ $environment['model'] ?: '-' }}</code>,
            credencial {{ $environment['has_key'] ? 'presente' : 'ausente' }}.
            O que for preenchido abaixo tem prioridade sobre isso.
        </div>
    </section>

    <form method="post" action="{{ route('admin.ai-provider.update') }}"
        x-data="aiProviderForm(@js($catalog), @js($form))">
        @csrf
        @method('put')

        <section class="card">
            <h2>Conversa</h2>

            <div>
                <label for="provider">Fornecedor</label>
                <select id="provider" name="provider" x-model="provider" @change="applyProvider()">
                    <option value="">Desligado (nenhuma chamada externa)</option>
                    @foreach($catalog as $slug => $item)
                        <option value="{{ $slug }}">{{ $item['label'] }}</option>
                    @endforeach
                </select>
                <p class="muted" x-show="provider !== ''" x-text="hint()"></p>
            </div>

            <template x-if="provider !== ''">
                <div>
                    <div>
                        <label for="url">URL da API</label>
                        <input id="url" name="url" type="url" maxlength="255" x-model="url">
                        <p class="muted">Endereco base, sem <code>/chat/completions</code> no final.</p>
                    </div>

                    <div>
                        <label for="model_choice">Modelo</label>
                        <select id="model_choice" name="model_choice" x-model="modelChoice">
                            <template x-for="(label, id) in models()" :key="id">
                                <option :value="id" x-text="label"></option>
                            </template>
                            <option value="__outro__">Outro (digitar o nome)</option>
                        </select>
                        <p class="muted">
                            A lista e uma conveniencia. Nome de modelo muda mais rapido que versao de sistema, entao
                            qualquer modelo pode ser digitado.
                        </p>
                    </div>

                    <div x-show="modelChoice === '__outro__'">
                        <label for="model_custom">Nome do modelo</label>
                        <input id="model_custom" type="text" maxlength="190" x-model="modelCustom"
                            placeholder="exemplo: anthropic/claude-opus-5">
                    </div>

                    <input type="hidden" name="model" :value="modelChoice === '__outro__' ? modelCustom : modelChoice">

                    <div x-show="supportsOrganization()">
                        <label for="organization">Organizacao (opcional)</label>
                        <input id="organization" name="organization" type="text" maxlength="190" x-model="organization">
                    </div>

                    <div>
                        <label for="key">Chave de API</label>
                        <input id="key" name="key" type="password" maxlength="500" autocomplete="new-password"
                            placeholder="{{ $form['key_hint'] ? 'Guardada: '.$form['key_hint'].'. Deixe em branco para manter.' : 'Cole a chave aqui.' }}">
                        <p class="muted">Em branco mantem a chave atual.</p>
                        @if($form['key_hint'])
                            <label><input type="checkbox" name="forget_key" value="1"> Apagar a chave guardada</label>
                        @endif
                    </div>
                </div>
            </template>
        </section>

        <section class="card">
            <h2>Embeddings (busca vetorial da base)</h2>
            <p class="muted">
                Opcional. A estrategia lexica de busca nao depende de embeddings e nao faz chamada externa. Preencha aqui
                so se for usar as estrategias vetorial ou hibrida.
            </p>

            <div>
                <label for="embedding_provider">Fornecedor de embeddings</label>
                <select id="embedding_provider" name="embedding_provider" x-model="embeddingProvider"
                    @change="applyEmbeddingProvider()">
                    <option value="">Desligado</option>
                    @foreach($catalog as $slug => $item)
                        <option value="{{ $slug }}">{{ $item['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <template x-if="embeddingProvider !== ''">
                <div>
                    <div>
                        <label for="embedding_url">URL da API</label>
                        <input id="embedding_url" name="embedding_url" type="url" maxlength="255" x-model="embeddingUrl">
                    </div>

                    <div>
                        <label for="embedding_choice">Modelo de embeddings</label>
                        <select id="embedding_choice" x-model="embeddingChoice" @change="applyEmbeddingModel()">
                            <template x-for="(item, id) in embeddingModels()" :key="id">
                                <option :value="id" x-text="item.label + ' (' + item.dimensions + ' dimensoes)'"></option>
                            </template>
                            <option value="__outro__">Outro (digitar o nome)</option>
                        </select>
                    </div>

                    <div x-show="embeddingChoice === '__outro__'">
                        <label for="embedding_custom">Nome do modelo</label>
                        <input id="embedding_custom" type="text" maxlength="190" x-model="embeddingCustom">
                    </div>

                    <input type="hidden" name="embedding_model"
                        :value="embeddingChoice === '__outro__' ? embeddingCustom : embeddingChoice">

                    <div>
                        <label for="embedding_dimensions">Dimensoes</label>
                        <input id="embedding_dimensions" name="embedding_dimensions" type="number" min="8" max="16383"
                            x-model="embeddingDimensions">
                        <p class="muted">
                            Precisa bater com o que o modelo devolve. O teto de 16383 vem do tamanho da coluna que guarda
                            o vetor, medido na ADR 0001.
                        </p>
                    </div>

                    <div>
                        <label for="embedding_key">Chave de API</label>
                        <input id="embedding_key" name="embedding_key" type="password" maxlength="500"
                            autocomplete="new-password"
                            placeholder="{{ $form['embedding_key_hint'] ? 'Guardada: '.$form['embedding_key_hint'].'. Deixe em branco para manter.' : 'Cole a chave aqui.' }}">
                        @if($form['embedding_key_hint'])
                            <label><input type="checkbox" name="forget_embedding_key" value="1"> Apagar a chave guardada</label>
                        @endif
                    </div>
                </div>
            </template>
        </section>

        <section class="card">
            <h2>Limites de transporte</h2>

            <div>
                <label for="timeout">Tempo limite da resposta (segundos)</label>
                <input id="timeout" name="timeout" type="number" min="1" max="300" required
                    value="{{ old('timeout', $form['timeout']) }}">
            </div>

            <div>
                <label for="connect_timeout">Tempo limite da conexao (segundos)</label>
                <input id="connect_timeout" name="connect_timeout" type="number" min="1" max="60" required
                    value="{{ old('connect_timeout', $form['connect_timeout']) }}">
            </div>

            <div>
                <label for="max_output_tokens">Maximo de tokens de saida</label>
                <input id="max_output_tokens" name="max_output_tokens" type="number" min="64" max="32000" required
                    value="{{ old('max_output_tokens', $form['max_output_tokens']) }}">
            </div>

            <div>
                <label for="temperature">Temperatura</label>
                <input id="temperature" name="temperature" type="number" step="0.1" min="0" max="2" required
                    value="{{ old('temperature', $form['temperature']) }}">
                <p class="muted">Zero deixa a resposta o mais previsivel possivel, que e o desejado aqui.</p>
            </div>

            <div>
                <label for="cost_input_per_1k">Custo por mil tokens de entrada (opcional)</label>
                <input id="cost_input_per_1k" name="cost_input_per_1k" type="number" step="0.000001" min="0"
                    value="{{ old('cost_input_per_1k', $form['cost_input_per_1k']) }}">
            </div>

            <div>
                <label for="cost_output_per_1k">Custo por mil tokens de saida (opcional)</label>
                <input id="cost_output_per_1k" name="cost_output_per_1k" type="number" step="0.000001" min="0"
                    value="{{ old('cost_output_per_1k', $form['cost_output_per_1k']) }}">
                <p class="muted">Usado apenas para estimar gasto nos relatorios. Nada deixa de funcionar sem isso.</p>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Salvar</button>
            </div>
        </section>
    </form>

    <section class="card">
        <h2>Teste de conexao</h2>
        <p class="muted">
            Faz uma chamada minima ao fornecedor com a configuracao ja salva e descarta a resposta. Serve para separar
            credencial errada de modelo inexistente antes de ligar a geracao. A acao fica registrada na auditoria.
        </p>
        <form method="post" action="{{ route('admin.ai-provider.test') }}">
            @csrf
            <button class="btn" type="submit">Testar conexao</button>
        </form>
    </section>

    <script>
        function aiProviderForm(catalog, form) {
            const known = (slug) => catalog[slug] ?? { models: {}, embedding_models: {}, url: '', key_hint: '', supports_organization: false };
            const choiceFor = (value, list) => (value !== '' && !(value in list)) ? '__outro__' : value;

            return {
                catalog,
                provider: form.provider,
                url: form.url,
                organization: form.organization,
                modelChoice: choiceFor(form.model, known(form.provider).models),
                modelCustom: form.model,
                embeddingProvider: form.embedding_provider,
                embeddingUrl: form.embedding_url,
                embeddingChoice: choiceFor(form.embedding_model, known(form.embedding_provider).embedding_models),
                embeddingCustom: form.embedding_model,
                embeddingDimensions: form.embedding_dimensions,

                models() { return known(this.provider).models; },
                embeddingModels() { return known(this.embeddingProvider).embedding_models; },
                hint() { return known(this.provider).key_hint; },
                supportsOrganization() { return known(this.provider).supports_organization === true; },

                applyProvider() {
                    // Preenche a URL sugerida apenas quando o campo esta vazio ou
                    // guarda a sugestao de outro fornecedor: uma URL digitada a
                    // mao nunca e descartada por troca de selecao.
                    const suggested = known(this.provider).url;
                    const wasSuggestion = Object.values(catalog).some((item) => item.url !== '' && item.url === this.url);
                    if (suggested !== '' && (this.url === '' || wasSuggestion)) {
                        this.url = suggested;
                    }
                    const models = this.models();
                    this.modelChoice = Object.keys(models)[0] ?? '__outro__';
                },

                applyEmbeddingProvider() {
                    const suggested = known(this.embeddingProvider).url;
                    const wasSuggestion = Object.values(catalog).some((item) => item.url !== '' && item.url === this.embeddingUrl);
                    if (suggested !== '' && (this.embeddingUrl === '' || wasSuggestion)) {
                        this.embeddingUrl = suggested;
                    }
                    this.embeddingChoice = Object.keys(this.embeddingModels())[0] ?? '__outro__';
                    this.applyEmbeddingModel();
                },

                applyEmbeddingModel() {
                    const item = this.embeddingModels()[this.embeddingChoice];
                    if (item) {
                        this.embeddingDimensions = item.dimensions;
                    }
                },
            };
        }
    </script>
</x-layouts.app>
