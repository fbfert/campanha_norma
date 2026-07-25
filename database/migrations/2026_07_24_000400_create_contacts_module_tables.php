<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('state', 2)->nullable()->index();
            $table->string('country', 2)->default('BR');
            $table->text('notes')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source')->default('manual')->index();
            $table->string('consent_status')->default('not_informed')->index();
            $table->string('consent_source')->nullable();
            $table->text('consent_text')->nullable();
            $table->date('consent_at')->nullable();
            $table->boolean('do_not_contact')->default(false)->index();
            $table->timestamp('do_not_contact_at')->nullable();
            $table->text('do_not_contact_reason')->nullable();
            $table->timestamp('last_contacted_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country', 'state']);
            $table->index(['created_at']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#176b4d');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contact_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['contact_id', 'tag_id']);
        });

        Schema::create('contact_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('contact_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('status')->default('uploaded')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('ignored_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_import_id')->constrained('contact_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data')->nullable();
            $table->json('normalized_data')->nullable();
            $table->string('status')->index();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('error_messages')->nullable();
            $table->timestamps();
            $table->unique(['contact_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_rows');
        Schema::dropIfExists('contact_imports');
        Schema::dropIfExists('contact_history');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
    }
};
