<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('provider')->default('web')->after('connection_id')->index();
            $table->string('external_chat_id')->nullable()->after('provider');
            $table->timestamp('last_synced_at')->nullable()->after('last_outgoing_message_at');
            $table->unique(['provider', 'external_chat_id']);
            $table->index(['external_chat_id', 'last_message_at']);
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('external_chat_id')->nullable()->after('provider')->index();
            $table->index(['conversation_id', 'sent_at', 'id']);
            $table->index(['conversation_id', 'received_at', 'id']);
        });

        Schema::create('conversation_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('chats_found')->default(0);
            $table->unsignedInteger('chats_processed')->default(0);
            $table->unsignedInteger('chats_failed')->default(0);
            $table->unsignedInteger('messages_found')->default(0);
            $table->unsignedInteger('messages_imported')->default(0);
            $table->unsignedInteger('messages_skipped')->default(0);
            $table->unsignedInteger('messages_failed')->default(0);
            $table->string('error_code')->nullable()->index();
            $table->string('error_message')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sync_runs');

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex(['conversation_id', 'sent_at', 'id']);
            $table->dropIndex(['conversation_id', 'received_at', 'id']);
            $table->dropIndex(['external_chat_id']);
            $table->dropColumn('external_chat_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'external_chat_id']);
            $table->dropIndex(['external_chat_id', 'last_message_at']);
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'external_chat_id', 'last_synced_at']);
        });
    }
};
