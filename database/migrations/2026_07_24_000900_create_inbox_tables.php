<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('has_replied')->default(false)->after('last_contacted_at');
            $table->timestamp('first_replied_at')->nullable()->after('has_replied');
            $table->timestamp('last_replied_at')->nullable()->after('first_replied_at');
            $table->index(['has_replied', 'last_replied_at']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('connection_id')->nullable();
            $table->string('status')->index();
            $table->string('priority')->default('normal')->index();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_message_direction')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_incoming_message_at')->nullable();
            $table->timestamp('last_outgoing_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'assigned_user_id']);
            $table->index(['contact_id', 'last_message_at']);
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_batch_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction')->index();
            $table->string('message_type')->default('text');
            $table->string('provider')->default('web');
            $table->string('external_message_id')->nullable();
            $table->string('event_id')->nullable();
            $table->uuid('request_id')->nullable()->unique();
            $table->string('sender_phone_snapshot')->nullable();
            $table->string('recipient_phone_snapshot')->nullable();
            $table->string('sender_name_snapshot')->nullable();
            $table->text('body')->nullable();
            $table->boolean('has_media')->default(false);
            $table->json('media_metadata')->nullable();
            $table->string('quoted_message_id')->nullable();
            $table->string('status')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->string('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'external_message_id']);
            $table->unique(['event_id']);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('conversation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('color')->default('#176b4d');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['slug', 'deleted_at']);
        });

        Schema::create('conversation_conversation_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'conversation_tag_id']);
        });

        Schema::create('conversation_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
        Schema::dropIfExists('conversation_conversation_tag');
        Schema::dropIfExists('conversation_tags');
        Schema::dropIfExists('conversation_assignments');
        Schema::dropIfExists('conversation_events');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['has_replied', 'last_replied_at']);
            $table->dropColumn(['has_replied', 'first_replied_at', 'last_replied_at']);
        });
    }
};
