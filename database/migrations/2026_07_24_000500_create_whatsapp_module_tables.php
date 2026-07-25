<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('web')->index();
            $table->string('status')->default('not_initialized')->index();
            $table->string('phone_number')->nullable();
            $table->string('display_name')->nullable();
            $table->string('session_identifier')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_status_check_at')->nullable();
            $table->timestamp('last_qr_generated_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_connection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connections')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('status')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('whatsapp_test_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('phone_snapshot');
            $table->text('message');
            $table->string('status')->default('pending')->index();
            $table->string('external_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_test_messages');
        Schema::dropIfExists('whatsapp_connection_events');
        Schema::dropIfExists('whatsapp_connections');
    }
};
