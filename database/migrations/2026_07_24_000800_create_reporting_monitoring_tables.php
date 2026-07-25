<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_type')->index();
            $table->string('format', 10);
            $table->string('status')->index();
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('worker_name');
            $table->string('queue')->index();
            $table->string('hostname')->nullable();
            $table->string('process_identifier')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('last_job_at')->nullable();
            $table->string('status')->default('healthy')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['worker_name', 'queue']);
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname')->unique();
            $table->timestamp('last_run_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_message_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->index();
            $table->string('provider')->default('web');
            $table->unsignedInteger('total_prepared')->default(0);
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_cancelled')->default(0);
            $table->unsignedInteger('total_skipped')->default(0);
            $table->unsignedInteger('total_attempts')->default(0);
            $table->unsignedInteger('total_waiting_minutes')->default(0);
            $table->timestamps();
            $table->unique(['date', 'provider']);
        });

        Schema::table('message_send_attempts', function (Blueprint $table): void {
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_send_attempts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'started_at']);
        });

        Schema::dropIfExists('daily_message_metrics');
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('worker_heartbeats');
        Schema::dropIfExists('report_exports');
    }
};
