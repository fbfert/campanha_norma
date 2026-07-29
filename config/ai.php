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
];
