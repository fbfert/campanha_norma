<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_flows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('presentation_template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->text('presentation_text')->nullable();
            $table->text('thank_you_text')->nullable();
            $table->text('permission_denied_text')->nullable();
            $table->unsignedInteger('max_main_questions')->default(1);
            $table->unsignedInteger('max_followups')->default(0);
            $table->unsignedInteger('validity_hours')->default(48);
            $table->boolean('transparency_enabled')->default(true);
            $table->text('transparency_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conversation_flow_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_flow_id')->constrained(indexName: 'cfq_flow_fk')->cascadeOnDelete();
            $table->string('internal_title');
            $table->text('text');
            $table->string('category')->nullable()->index();
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['conversation_flow_id', 'is_active'], 'cfq_flow_active_idx');
        });

        Schema::create('conversation_flow_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained(indexName: 'cfs_conversation_fk')->cascadeOnDelete();
            $table->foreignId('conversation_flow_id')->constrained(indexName: 'cfs_flow_fk')->cascadeOnDelete();
            $table->string('current_stage')->default('inactive')->index();
            $table->foreignId('selected_question_id')->nullable()->constrained('conversation_flow_questions', indexName: 'cfs_question_fk')->nullOnDelete();
            $table->text('selected_question_snapshot')->nullable();
            $table->unsignedInteger('automated_messages_count')->default(0);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->foreignId('last_processed_message_id')->nullable()->constrained('conversation_messages', indexName: 'cfs_last_processed_fk')->nullOnDelete();
            $table->foreignId('last_automated_message_id')->nullable()->constrained('conversation_messages', indexName: 'cfs_last_automated_fk')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->boolean('is_paused')->default(false)->index();
            $table->boolean('needs_human_review')->default(false)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_transition_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id'], 'cfs_conversation_uniq');
            $table->index(['conversation_flow_id', 'current_stage'], 'cfs_flow_stage_idx');
        });

        Schema::create('conversation_flow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_flow_state_id')->constrained(indexName: 'cft_state_fk')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained(indexName: 'cft_conversation_fk')->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage')->index();
            $table->string('trigger_event')->index();
            $table->foreignId('conversation_message_id')->nullable()->constrained(indexName: 'cft_message_fk')->nullOnDelete();
            $table->string('decision')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'cft_user_fk')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at'], 'cft_conversation_created_idx');
        });

        Schema::create('conversation_flow_question_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_flow_state_id')->constrained(indexName: 'cfqu_state_fk')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained(indexName: 'cfqu_conversation_fk')->cascadeOnDelete();
            $table->foreignId('conversation_flow_question_id')->constrained(indexName: 'cfqu_question_fk')->cascadeOnDelete();
            $table->text('question_snapshot');
            $table->timestamp('selected_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('conversation_message_id')->nullable()->constrained(indexName: 'cfqu_message_fk')->nullOnDelete();
            $table->string('result')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'conversation_flow_question_id'], 'cfqu_conversation_question_uniq');
        });

        Schema::table('message_batches', function (Blueprint $table): void {
            $table->foreignId('conversation_flow_id')->nullable()->after('is_campaign')->constrained(indexName: 'mb_conversation_flow_fk')->nullOnDelete();
            $table->json('conversation_flow_snapshot')->nullable()->after('conversation_flow_id');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('origin')->default('manual')->after('provider')->index();
        });

        DB::table('conversation_messages')->where('direction', 'incoming')->update(['origin' => 'incoming']);
        DB::table('conversation_messages')->where('direction', 'outgoing')->whereNull('created_by')->update(['origin' => 'sync']);
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropColumn('origin');
        });

        Schema::table('message_batches', function (Blueprint $table): void {
            $table->dropForeign('mb_conversation_flow_fk');
            $table->dropColumn(['conversation_flow_id', 'conversation_flow_snapshot']);
        });

        Schema::dropIfExists('conversation_flow_question_usages');
        Schema::dropIfExists('conversation_flow_transitions');
        Schema::dropIfExists('conversation_flow_states');
        Schema::dropIfExists('conversation_flow_questions');
        Schema::dropIfExists('conversation_flows');
    }
};
