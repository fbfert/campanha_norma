<?php

return [
    /*
     | Provedor ativo. `null` desliga qualquer chamada externa sem exigir que a
     | camada de interpretacao seja removida do sistema.
     */
    'provider' => env('AI_PROVIDER', 'null'),

    'providers' => [
        /*
         | Compativel com APIs de chat no formato OpenAI (OpenAI, Azure OpenAI,
         | OpenRouter, Groq e servidores locais como Ollama e vLLM).
         */
        'openai' => [
            'url' => env('AI_OPENAI_URL', 'https://api.openai.com/v1'),
            'key' => env('AI_OPENAI_KEY'),
            'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
            'organization' => env('AI_OPENAI_ORGANIZATION'),
        ],
    ],

    /*
     | Limites de transporte. Valores operacionais de negocio (thresholds de
     | confianca, versoes de prompt, retencao) ficam em system_settings.
     */
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 900),
    'temperature' => (float) env('AI_TEMPERATURE', 0),

    /*
     | Custo estimado opcional, em unidade monetaria por mil tokens. Nenhuma
     | funcionalidade depende destes valores estarem preenchidos.
     */
    'cost' => [
        'input_per_1k' => env('AI_COST_INPUT_PER_1K'),
        'output_per_1k' => env('AI_COST_OUTPUT_PER_1K'),
    ],

    /*
     | Catalogo exibido na tela de provedor. E uma lista de conveniencia, nao
     | uma restricao: cada fornecedor aceita modelo digitado a mao, porque nome
     | de modelo muda mais rapido que release de sistema. Um catalogo que
     | impede de usar o modelo lancado ontem seria pior que nenhum catalogo.
     |
     | Todos os fornecedores abaixo falam o protocolo de chat da OpenAI, que e
     | o unico que `OpenAiCompatibleProvider` implementa. A API propria da
     | Anthropic nao fala esse protocolo: os modelos Claude aparecem aqui pelo
     | OpenRouter, que os expoe no formato compativel.
     */
    'catalog' => [
        'openai' => [
            'label' => 'OpenAI',
            'url' => 'https://api.openai.com/v1',
            'key_hint' => 'Chave de API do painel da OpenAI.',
            'supports_organization' => true,
            'models' => [
                'gpt-4o-mini' => 'GPT-4o mini (rapido e barato)',
                'gpt-4o' => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 mini',
                'gpt-4.1' => 'GPT-4.1',
            ],
            'embedding_models' => [
                'text-embedding-3-small' => ['label' => 'text-embedding-3-small', 'dimensions' => 1536],
                'text-embedding-3-large' => ['label' => 'text-embedding-3-large', 'dimensions' => 3072],
            ],
        ],

        'openrouter' => [
            'label' => 'OpenRouter',
            'url' => 'https://openrouter.ai/api/v1',
            'key_hint' => 'Chave de API do OpenRouter. Da acesso a modelos de varios fornecedores por uma credencial so.',
            'supports_organization' => false,
            'models' => [
                'anthropic/claude-sonnet-5' => 'Claude Sonnet 5 (equilibrio)',
                'anthropic/claude-opus-5' => 'Claude Opus 5 (mais capaz)',
                'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5 (mais barato)',
                'openai/gpt-4o-mini' => 'GPT-4o mini',
                'openai/gpt-4o' => 'GPT-4o',
                'google/gemini-2.5-flash' => 'Gemini 2.5 Flash',
            ],
            'embedding_models' => [
                'openai/text-embedding-3-small' => ['label' => 'text-embedding-3-small', 'dimensions' => 1536],
            ],
        ],

        'azure' => [
            'label' => 'Azure OpenAI',
            'url' => '',
            'key_hint' => 'Chave do recurso no portal do Azure. A URL e a do seu endpoint, terminando no caminho do deployment.',
            'supports_organization' => false,
            'models' => [],
            'embedding_models' => [],
        ],

        'groq' => [
            'label' => 'Groq',
            'url' => 'https://api.groq.com/openai/v1',
            'key_hint' => 'Chave de API do console da Groq.',
            'supports_organization' => false,
            'models' => [
                'llama-3.3-70b-versatile' => 'Llama 3.3 70B',
                'llama-3.1-8b-instant' => 'Llama 3.1 8B (instantaneo)',
            ],
            'embedding_models' => [],
        ],

        'ollama' => [
            'label' => 'Ollama ou servidor local',
            'url' => 'http://127.0.0.1:11434/v1',
            'key_hint' => 'Servidor local costuma aceitar qualquer valor. Preencha com "local" se nao houver chave.',
            'supports_organization' => false,
            'models' => [
                'llama3.1' => 'Llama 3.1',
                'qwen2.5' => 'Qwen 2.5',
            ],
            'embedding_models' => [
                'nomic-embed-text' => ['label' => 'nomic-embed-text', 'dimensions' => 768],
            ],
        ],

        'custom' => [
            'label' => 'Outro compativel com OpenAI',
            'url' => '',
            'key_hint' => 'Qualquer servico que exponha /chat/completions no formato da OpenAI.',
            'supports_organization' => false,
            'models' => [],
            'embedding_models' => [],
        ],
    ],
];
