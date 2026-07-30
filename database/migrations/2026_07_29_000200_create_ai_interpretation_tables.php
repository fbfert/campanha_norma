<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('insight_topics', indexName: 'it_parent_fk')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // Sinônimos separados por barra vertical, no mesmo padrão das listas
            // de expressões já usadas na 9A.
            $table->text('synonyms')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            // Tema obrigatório de destino quando a saída do modelo não casa com
            // nenhum tema cadastrado. Protegido contra exclusão e desativação.
            $table->boolean('is_fallback')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'it_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'it_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['parent_id', 'display_order'], 'it_parent_order_idx');
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained(indexName: 'air_conversation_fk')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('conversation_messages', indexName: 'air_message_fk')->nullOnDelete();
            $table->foreignId('conversation_flow_id')->nullable()->constrained(indexName: 'air_flow_fk')->nullOnDelete();
            $table->string('purpose')->index();
            $table->string('provider')->index();
            $table->string('model');
            $table->string('prompt_version');
            $table->unsignedInteger('schema_version');
            $table->string('status')->index();
            $table->string('request_hash', 64)->index();
            $table->json('result')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('error_code')->nullable()->index();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['source_message_id', 'purpose'], 'air_message_purpose_idx');
            $table->index(['status', 'created_at'], 'air_status_created_idx');
        });

        Schema::create('conversation_message_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained(indexName: 'cmc_conversation_fk')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained('conversation_messages', indexName: 'cmc_message_fk')->cascadeOnDelete();
            $table->string('purpose')->default('classify');
            $table->string('classification')->index();
            $table->string('source')->index();
            $table->decimal('confidence', 4, 3)->nullable();
            // Nome explícito: o gerado automaticamente ficaria com 64 caracteres,
            // exatamente no limite do MySQL.
            $table->boolean('requires_human_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->string('prompt_version');
            $table->unsignedInteger('schema_version');
            $table->foreignId('ai_run_id')->nullable()->constrained(indexName: 'cmc_run_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(
                ['conversation_message_id', 'purpose', 'prompt_version', 'schema_version'],
                'cmc_message_purpose_version_uniq'
            );
            $table->index('requires_human_review', 'cmc_needs_review_idx');
        });

        Schema::create('conversation_insights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained(indexName: 'ci_conversation_fk')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained(indexName: 'ci_contact_fk')->nullOnDelete();
            $table->foreignId('source_message_id')->constrained('conversation_messages', indexName: 'ci_message_fk')->cascadeOnDelete();
            $table->foreignId('conversation_flow_id')->nullable()->constrained(indexName: 'ci_flow_fk')->nullOnDelete();
            $table->foreignId('conversation_flow_question_id')->nullable()->constrained(indexName: 'ci_question_fk')->nullOnDelete();
            $table->text('question_snapshot')->nullable();
            $table->text('summary')->nullable();
            // Tema principal relacional: filtros e relatórios nunca dependem de JSON.
            $table->foreignId('insight_topic_id')->nullable()->constrained(indexName: 'ci_topic_fk')->nullOnDelete();
            $table->string('main_topic_raw')->nullable();
            $table->json('secondary_topics_raw')->nullable();
            $table->text('identified_problem')->nullable();
            $table->text('suggested_action')->nullable();
            $table->text('desired_result')->nullable();
            $table->string('affected_group')->nullable();
            // Localidade apenas quando declarada. Nunca inferida.
            $table->string('locality_text')->nullable();
            $table->string('locality_normalized')->nullable()->index();
            $table->string('region')->nullable()->index();
            $table->string('urgency')->nullable()->index();
            $table->string('sentiment')->nullable()->index();
            $table->json('keywords')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_human_review')->default(false)->index();
            $table->string('review_reason')->nullable();
            $table->boolean('reviewed')->default(false)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'ci_reviewed_by_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('extraction_version');
            $table->string('prompt_version');
            $table->foreignId('ai_run_id')->nullable()->constrained(indexName: 'ci_run_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_message_id', 'extraction_version'], 'ci_message_version_uniq');
            // Índices de agregação. A 9E não esta implementada, mas os recortes
            // previsíveis (por tema, por fluxo e por período) ficam cobertos
            // agora para não exigir migration de índice sobre tabela cheia.
            $table->index(['insight_topic_id', 'created_at'], 'ci_topic_created_idx');
            $table->index(['conversation_flow_id', 'created_at'], 'ci_flow_created_idx');
            $table->index('created_at', 'ci_created_idx');
        });

        Schema::create('conversation_insight_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_insight_id')->constrained(indexName: 'cit_insight_fk')->cascadeOnDelete();
            $table->foreignId('insight_topic_id')->constrained(indexName: 'cit_topic_fk')->cascadeOnDelete();
            $table->string('role')->default('secondary')->index();
            $table->string('raw_value')->nullable();
            $table->timestamps();
            $table->unique(['conversation_insight_id', 'insight_topic_id', 'role'], 'cit_insight_topic_role_uniq');
            // Recorte inverso: quais insights citam determinado tema, principal
            // ou secundário.
            $table->index(['insight_topic_id', 'role'], 'cit_topic_role_idx');
        });

        Schema::create('conversation_insight_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_insight_id')->nullable()->constrained(indexName: 'cic_insight_fk')->cascadeOnDelete();
            $table->foreignId('conversation_message_classification_id')->nullable()->constrained('conversation_message_classifications', indexName: 'cic_classification_fk')->cascadeOnDelete();
            $table->string('field');
            $table->text('original_value')->nullable();
            $table->text('corrected_value')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'cic_user_fk')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_insight_id', 'created_at'], 'cic_insight_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_insight_corrections');
        Schema::dropIfExists('conversation_insight_topics');
        Schema::dropIfExists('conversation_insights');
        Schema::dropIfExists('conversation_message_classifications');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('insight_topics');
    }
};
