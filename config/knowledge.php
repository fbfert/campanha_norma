<?php

return [
    /*
     | Provedor de armazenamento da base. `null` deixa a camada instalada e
     | inerte, no mesmo padrão do provedor de IA da subetapa anterior.
     */
    'provider' => env('KNOWLEDGE_PROVIDER', 'null'),

    'providers' => [
        /*
         | Armazenamento relacional. Trechos e embeddings ficam no MySQL. A
         | justificativa técnica e os limites medidos estão em
         | docs/adr/0001-armazenamento-vetorial-e-provedor-de-conhecimento.md.
         */
        'local' => [
            'disk' => env('KNOWLEDGE_DISK', 'local'),
            'directory' => env('KNOWLEDGE_DIRECTORY', 'knowledge-documents'),
        ],
    ],

    /*
     | Provedor de embeddings. `null` desliga a estratégia vetorial sem impedir
     | a estratégia léxica, que não depende de serviço externo.
     */
    'embeddings' => [
        'provider' => env('KNOWLEDGE_EMBEDDING_PROVIDER', 'null'),

        'openai' => [
            'url' => env('KNOWLEDGE_EMBEDDING_URL', env('AI_OPENAI_URL', 'https://api.openai.com/v1')),
            'key' => env('KNOWLEDGE_EMBEDDING_KEY', env('AI_OPENAI_KEY')),
            'model' => env('KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dimensions' => (int) env('KNOWLEDGE_EMBEDDING_DIMENSIONS', 1536),
        ],
    ],

    /*
     | Limites de transporte. Valores operacionais de negócio (chunking, top_k,
     | threshold, retenção, MIME aceitos) ficam em system_settings.
     */
    'timeout' => (int) env('KNOWLEDGE_TIMEOUT', 30),
    'connect_timeout' => (int) env('KNOWLEDGE_CONNECT_TIMEOUT', 5),
    'embedding_batch_size' => (int) env('KNOWLEDGE_EMBEDDING_BATCH', 32),

    /*
     | Comandos externos. Sem caminho fixo: o ambiente decide, e a ausência do
     | binário produz falha limpa em vez de extração improvisada.
     |
     | Marcador :input e substituído pelo caminho absoluto do arquivo.
     */
    'pdf_text_command' => env('KNOWLEDGE_PDF_TEXT_COMMAND', 'pdftotext -layout -enc UTF-8 :input -'),
    'antivirus_command' => env('KNOWLEDGE_ANTIVIRUS_COMMAND', 'clamscan --no-summary --stdout :input'),
    'process_timeout' => (int) env('KNOWLEDGE_PROCESS_TIMEOUT', 120),
];
