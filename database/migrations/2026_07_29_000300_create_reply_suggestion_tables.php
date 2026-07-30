<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reply_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained(indexName: 'crs_conversation_fk')->cascadeOnDelete();
            $table->foreignId('conversation_flow_state_id')->nullable()->constrained(indexName: 'crs_state_fk')->nullOnDelete();
            $table->foreignId('conversation_flow_id')->nullable()->constrained(indexName: 'crs_flow_fk')->nullOnDelete();
            $table->foreignId('source_message_id')->constrained('conversation_messages', indexName: 'crs_source_fk')->cascadeOnDelete();

            /*
             | MySQL nao possui indice unico parcial. Esta coluna espelha
             | `source_message_id` enquanto a sugestao esta viva (pendente ou
             | aprovada) e vira NULL quando ela sai de circulacao. Como o indice
             | unico aceita multiplos NULL, o efeito e "no maximo uma sugestao
             | viva por mensagem recebida", sem impedir o historico de
             | regeneracoes.
             */
            $table->unsignedBigInteger('active_source_message_id')->nullable()->unique('crs_active_source_uniq');

            $table->foreignId('ai_run_id')->nullable()->constrained(indexName: 'crs_run_fk')->nullOnDelete();
            $table->foreignId('conversation_insight_id')->nullable()->constrained(indexName: 'crs_insight_fk')->nullOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('conversation_message_classifications', indexName: 'crs_classification_fk')->nullOnDelete();

            $table->string('status')->index();
            $table->string('action')->index();
            $table->string('follow_up_type')->nullable();
            $table->foreignId('insight_topic_id')->nullable()->constrained(indexName: 'crs_topic_fk')->nullOnDelete();

            // Texto gerado nunca e sobrescrito; a edicao do operador vai para o final.
            $table->text('generated_text')->nullable();
            $table->text('final_text')->nullable();

            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_human_review')->default(false);
            $table->string('handoff_reason')->nullable()->index();
            $table->string('validation_error')->nullable();
            $table->string('blocked_reason')->nullable();

            $table->string('mode');
            $table->string('prompt_version');
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('turn_number')->default(1);
            $table->unsignedInteger('generation_attempt')->default(1);

            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'crs_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users', indexName: 'crs_rejected_by_fk')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('regeneration_reason')->nullable();

            $table->foreignId('sent_message_id')->nullable()->constrained('conversation_messages', indexName: 'crs_sent_fk')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('auto_sent')->default(false);
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('feedback')->nullable()->index();
            $table->text('feedback_reason')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users', indexName: 'crs_feedback_by_fk')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'status'], 'crs_conversation_status_idx');
            $table->index(['status', 'created_at'], 'crs_status_created_idx');
            $table->index(['source_message_id', 'generation_attempt'], 'crs_source_attempt_idx');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            // Metadados de autoria de IA. Colunas relacionais, nao JSON: precisam
            // ser filtraveis e agregaveis pelos relatorios da subetapa seguinte.
            $table->boolean('generated_by_ai')->default(false)->after('origin');
            $table->unsignedBigInteger('ai_run_id')->nullable()->after('generated_by_ai');
            $table->string('ai_prompt_version')->nullable()->after('ai_run_id');
            $table->decimal('ai_confidence', 4, 3)->nullable()->after('ai_prompt_version');
            $table->unsignedBigInteger('approved_by')->nullable()->after('ai_confidence');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('automation_state_transition_id')->nullable()->after('approved_at');

            $table->index('generated_by_ai', 'cm_generated_by_ai_idx');
            $table->foreign('ai_run_id', 'cm_ai_run_fk')->references('id')->on('ai_runs')->nullOnDelete();
            $table->foreign('approved_by', 'cm_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('automation_state_transition_id', 'cm_transition_fk')
                ->references('id')->on('conversation_flow_transitions')->nullOnDelete();
        });

        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            // Contador proprio: aprofundamentos confirmados, nao mensagens automaticas.
            $table->unsignedInteger('followups_count')->default(0)->after('automated_messages_count');
            $table->timestamp('last_suggestion_at')->nullable()->after('last_transition_at');
        });

        Schema::table('conversation_flows', function (Blueprint $table): void {
            // Nulo significa herdar o modo global. O modo do fluxo so restringe.
            $table->string('response_mode')->nullable()->after('max_followups');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_flows', function (Blueprint $table): void {
            $table->dropColumn('response_mode');
        });

        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            $table->dropColumn(['followups_count', 'last_suggestion_at']);
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropForeign('cm_transition_fk');
            $table->dropForeign('cm_approved_by_fk');
            $table->dropForeign('cm_ai_run_fk');
            $table->dropIndex('cm_generated_by_ai_idx');
            $table->dropColumn([
                'generated_by_ai',
                'ai_run_id',
                'ai_prompt_version',
                'ai_confidence',
                'approved_by',
                'approved_at',
                'automation_state_transition_id',
            ]);
        });

        Schema::dropIfExists('conversation_reply_suggestions');
    }
};
