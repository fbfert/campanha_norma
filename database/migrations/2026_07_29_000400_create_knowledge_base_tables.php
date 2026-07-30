<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique('kb_slug_uniq');
            $table->text('description')->nullable();
            $table->text('purpose')->nullable();
            $table->text('usage_policy')->nullable();
            $table->string('status')->index('kb_status_idx');
            $table->unsignedInteger('version')->default(1);

            // Provedor e identificador remoto ficam na propria base: trocar de
            // armazenamento nao exige adivinhar onde cada base foi criada.
            $table->string('provider');
            $table->string('external_store_id')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'kb_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'kb_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'kb_updated_by_fk')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained(indexName: 'kd_base_fk')->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->index('kd_type_idx');
            $table->string('source')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->date('document_date')->nullable();

            $table->string('disk');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('content_hash', 64);

            $table->string('status')->index('kd_status_idx');
            $table->unsignedInteger('version')->default(1);

            // Versao nova nao sobrescreve rastreabilidade: aponta para a anterior
            // e a anterior vira obsoleta, permanecendo legivel.
            $table->foreignId('supersedes_document_id')->nullable()
                ->constrained('knowledge_documents', indexName: 'kd_supersedes_fk')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->string('provider_file_id')->nullable();

            // Erro sanitizado: codigo operacional visivel na tela, sem credencial
            // e sem conteudo integral do arquivo.
            $table->string('error_message')->nullable();

            // Deteccao de tentativa de injecao de prompt no proprio documento.
            $table->boolean('injection_flagged')->default(false)->index('kd_injection_idx');
            $table->text('injection_findings')->nullable();
            $table->string('antivirus_result')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'kd_created_by_fk')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'kd_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users', indexName: 'kd_rejected_by_fk')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('obsoleted_by')->nullable()->constrained('users', indexName: 'kd_obsoleted_by_fk')->nullOnDelete();
            $table->timestamp('obsoleted_at')->nullable();
            $table->timestamps();

            // Deduplicacao por base: o mesmo arquivo pode existir em bases
            // diferentes com finalidades diferentes, nunca duas vezes na mesma.
            $table->unique(['knowledge_base_id', 'content_hash'], 'kd_base_hash_uniq');
            $table->index(['knowledge_base_id', 'status'], 'kd_base_status_idx');
            $table->index(['status', 'created_at'], 'kd_status_created_idx');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_document_id')->constrained(indexName: 'kc_document_fk')->cascadeOnDelete();

            // Base desnormalizada: a recuperacao filtra por base antes de tudo, e
            // um join a mais em cada consulta nao paga nada aqui.
            $table->foreignId('knowledge_base_id')->constrained(indexName: 'kc_base_fk')->cascadeOnDelete();

            $table->unsignedInteger('chunk_index');
            $table->string('external_chunk_id')->nullable();
            $table->longText('content');

            /*
             | Copia normalizada do conteudo: minuscula, sem acento e sem
             | pontuacao. Existe para que a busca lexica encontre "saude" quando o
             | documento escreveu "saude" com acento — comparar acento no `LIKE`
             | dependeria de collation e falharia em silencio.
             */
            $table->longText('search_text')->nullable();

            $table->string('content_hash', 64)->index('kc_hash_idx');
            $table->unsignedInteger('token_estimate')->default(0);
            $table->unsignedInteger('page')->nullable();
            $table->string('section')->nullable();

            /*
             | Embedding como sequencia de floats de 32 bits, nao JSON. BLOB
             | acomoda ate 16.384 dimensoes, muito acima dos 1.536 do modelo
             | pequeno em uso. A dimensao fica na propria linha para que trocar de
             | modelo nao corrompa leitura: vetor de dimensao divergente e
             | ignorado na busca e sinalizado pelo diagnostico.
             |
             | A justificativa completa esta no ADR 0001.
             */
            $table->binary('embedding')->nullable();
            $table->string('embedding_provider')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();

            $table->timestamps();

            $table->unique(['knowledge_document_id', 'chunk_index'], 'kc_document_index_uniq');
            $table->index(['knowledge_base_id', 'knowledge_document_id'], 'kc_base_document_idx');
        });

        Schema::create('conversation_flow_knowledge_base', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_flow_id')->constrained(indexName: 'cfkb_flow_fk')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained(indexName: 'cfkb_base_fk')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(['conversation_flow_id', 'knowledge_base_id'], 'cfkb_flow_base_uniq');
        });

        Schema::create('knowledge_retrievals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained(indexName: 'kr_conversation_fk')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()
                ->constrained('conversation_messages', indexName: 'kr_source_fk')->nullOnDelete();
            $table->foreignId('conversation_flow_id')->nullable()->constrained(indexName: 'kr_flow_fk')->nullOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained(indexName: 'kr_run_fk')->nullOnDelete();

            // Consulta truncada. Serve para auditar o que foi buscado, nao para
            // reconstruir a mensagem da pessoa.
            $table->text('query_text')->nullable();
            $table->string('strategy');
            $table->unsignedInteger('top_k');
            $table->decimal('threshold', 6, 4);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('returned_count')->default(0);
            $table->decimal('max_score', 8, 6)->nullable();
            $table->decimal('min_score', 8, 6)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('provider');
            $table->string('degraded_reason')->nullable();
            $table->boolean('is_test')->default(false)->index('kr_is_test_idx');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at'], 'kr_conversation_created_idx');
            $table->index('created_at', 'kr_created_idx');
        });

        Schema::create('knowledge_retrieval_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_retrieval_id')->constrained(indexName: 'krc_retrieval_fk')->cascadeOnDelete();

            /*
             | A chave estrangeira permite navegar; o snapshot permite auditar.
             | Sem o snapshot, excluir um documento apagaria a explicacao de toda
             | resposta que ele sustentou — e o escopo exige que documento
             | obsoleto saia da busca sem apagar historico.
             */
            $table->foreignId('knowledge_chunk_id')->nullable()->constrained(indexName: 'krc_chunk_fk')->nullOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained(indexName: 'krc_document_fk')->nullOnDelete();
            $table->string('document_title_snapshot')->nullable();
            $table->unsignedInteger('document_version')->nullable();
            $table->string('chunk_reference')->nullable();
            $table->longText('content_snapshot')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->string('section')->nullable();
            $table->decimal('score', 8, 6)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['knowledge_retrieval_id', 'position'], 'krc_retrieval_position_idx');
        });

        Schema::create('reply_suggestion_citations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_reply_suggestion_id')
                ->constrained('conversation_reply_suggestions', indexName: 'rsc_suggestion_fk')->cascadeOnDelete();
            $table->foreignId('knowledge_retrieval_chunk_id')->nullable()
                ->constrained('knowledge_retrieval_chunks', indexName: 'rsc_retrieval_chunk_fk')->nullOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()
                ->constrained('knowledge_documents', indexName: 'rsc_document_fk')->nullOnDelete();

            $table->string('document_title_snapshot')->nullable();
            $table->unsignedInteger('document_version')->nullable();
            $table->string('chunk_reference')->nullable();
            $table->longText('content_snapshot')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->string('section')->nullable();
            $table->decimal('score', 8, 6)->nullable();
            $table->boolean('is_valid')->default(true);
            $table->string('invalid_reason')->nullable();
            $table->timestamps();

            $table->index('conversation_reply_suggestion_id', 'rsc_suggestion_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('reply_suggestion_citations');
        Schema::dropIfExists('knowledge_retrieval_chunks');
        Schema::dropIfExists('knowledge_retrievals');
        Schema::dropIfExists('conversation_flow_knowledge_base');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
