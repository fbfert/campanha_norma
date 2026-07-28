<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batches', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('prepared_at');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('pause_requested_at')->nullable()->after('processing_started_at');
            $table->timestamp('paused_at')->nullable()->after('pause_requested_at');
            $table->timestamp('resume_requested_at')->nullable()->after('paused_at');
            $table->timestamp('stop_requested_at')->nullable()->after('resume_requested_at');
            $table->timestamp('stopped_at')->nullable()->after('stop_requested_at');
            $table->timestamp('completed_at')->nullable()->after('stopped_at');
            $table->timestamp('failed_at')->nullable()->after('completed_at');
            $table->timestamp('last_dispatch_at')->nullable()->after('failed_at');
            $table->timestamp('next_dispatch_at')->nullable()->after('last_dispatch_at');
            $table->unsignedInteger('total_pending')->default(0)->after('next_dispatch_at');
            $table->unsignedInteger('total_queued')->default(0)->after('total_pending');
            $table->unsignedInteger('total_processing')->default(0)->after('total_queued');
            $table->unsignedInteger('total_sent')->default(0)->after('total_processing');
            $table->unsignedInteger('total_failed')->default(0)->after('total_sent');
            $table->unsignedInteger('total_cancelled')->default(0)->after('total_failed');
            $table->unsignedInteger('total_retrying')->default(0)->after('total_cancelled');
            $table->unsignedInteger('processing_version')->default(1)->after('total_retrying');
            $table->string('last_error_code')->nullable()->after('processing_version');
            $table->text('last_error_message')->nullable()->after('last_error_code');
            $table->index(['status', 'next_dispatch_at'], 'mb_status_next_idx');
        });

        Schema::table('message_batch_recipients', function (Blueprint $table): void {
            $table->string('processing_status')->default('eligible')->after('eligibility_status');
            $table->unsignedInteger('attempts')->default(0)->after('processing_status');
            $table->unsignedInteger('max_attempts')->default(3)->after('attempts');
            $table->uuid('request_id')->nullable()->after('max_attempts');
            $table->unsignedInteger('processing_version')->default(1)->after('request_id');
            $table->timestamp('queued_at')->nullable()->after('processing_version');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('sent_at')->nullable()->after('processing_started_at');
            $table->timestamp('failed_at')->nullable()->after('sent_at');
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');
            $table->timestamp('retry_at')->nullable()->after('cancelled_at');
            $table->timestamp('last_attempt_at')->nullable()->after('retry_at');
            $table->string('external_message_id')->nullable()->after('last_attempt_at');
            $table->string('error_code')->nullable()->after('external_message_id');
            $table->text('error_message')->nullable()->after('error_code');
            $table->json('technical_payload')->nullable()->after('error_message');
            $table->index(['processing_status'], 'mbr_proc_status_idx');
            $table->unique(['request_id'], 'mbr_request_id_uniq');
            $table->index(['message_batch_id', 'processing_status'], 'mbr_batch_proc_idx');
            $table->index(['message_batch_id', 'random_position', 'processing_status'], 'mbr_batch_pos_proc_idx');
            $table->index(['retry_at'], 'mbr_retry_at_idx');
        });

        Schema::create('sending_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('max_per_minute')->default(1);
            $table->unsignedInteger('max_per_hour')->default(15);
            $table->unsignedInteger('max_per_day')->default(40);
            $table->unsignedInteger('minimum_interval_seconds')->default(60);
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->json('allowed_weekdays');
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->unsignedInteger('max_attempts')->default(3);
            $table->unsignedInteger('retry_interval_minutes')->default(15);
            $table->string('retry_backoff_type')->default('fixed');
            $table->boolean('pause_when_disconnected')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('message_processing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_batch_recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('status')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['message_batch_id', 'created_at'], 'mpe_batch_created_idx');
        });

        Schema::create('message_send_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_batch_recipient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('request_id');
            $table->string('status');
            $table->string('provider')->default('web');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('external_message_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();
            $table->index(['request_id'], 'msa_request_id_idx');
            $table->index(['status'], 'msa_status_idx');
            $table->index(['error_code'], 'msa_error_code_idx');
            $table->unique(['message_batch_recipient_id', 'attempt_number'], 'msa_batch_attempt_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_send_attempts');
        Schema::dropIfExists('message_processing_events');
        Schema::dropIfExists('sending_settings');

        Schema::table('message_batch_recipients', function (Blueprint $table): void {
            $table->dropColumn([
                'processing_status',
                'attempts',
                'max_attempts',
                'request_id',
                'processing_version',
                'queued_at',
                'processing_started_at',
                'sent_at',
                'failed_at',
                'cancelled_at',
                'retry_at',
                'last_attempt_at',
                'external_message_id',
                'error_code',
                'error_message',
                'technical_payload',
            ]);
        });

        Schema::table('message_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'queued_at',
                'processing_started_at',
                'pause_requested_at',
                'paused_at',
                'resume_requested_at',
                'stop_requested_at',
                'stopped_at',
                'completed_at',
                'failed_at',
                'last_dispatch_at',
                'next_dispatch_at',
                'total_pending',
                'total_queued',
                'total_processing',
                'total_sent',
                'total_failed',
                'total_cancelled',
                'total_retrying',
                'processing_version',
                'last_error_code',
                'last_error_message',
            ]);
        });
    }
};
