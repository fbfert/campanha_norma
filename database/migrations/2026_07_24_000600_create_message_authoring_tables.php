<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('body');
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('message_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('body');
            $table->json('placeholders')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['message_template_id', 'version']);
        });

        Schema::create('message_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('message_template_id')->nullable()->constrained('message_templates')->nullOnDelete()->index();
            $table->unsignedInteger('message_template_version')->nullable();
            $table->text('message_body_snapshot');
            $table->json('placeholders_snapshot')->nullable();
            $table->string('selection_type')->default('manual');
            $table->json('selection_filters')->nullable();
            $table->unsignedInteger('selection_total')->default(0);
            $table->unsignedInteger('eligible_total')->default(0);
            $table->unsignedInteger('ineligible_total')->default(0);
            $table->string('status')->default('draft')->index();
            $table->string('random_seed')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at']);
        });

        Schema::create('message_batch_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('random_position')->nullable()->index();
            $table->string('eligibility_status')->default('eligible')->index();
            $table->text('ineligibility_reason')->nullable();
            $table->string('contact_name_snapshot');
            $table->string('contact_first_name_snapshot')->nullable();
            $table->string('contact_phone_snapshot')->nullable();
            $table->string('contact_email_snapshot')->nullable();
            $table->string('contact_city_snapshot')->nullable();
            $table->string('contact_state_snapshot')->nullable();
            $table->string('contact_country_snapshot')->nullable();
            $table->text('rendered_message')->nullable();
            $table->json('render_errors')->nullable();
            $table->timestamps();
            $table->unique(['message_batch_id', 'contact_id']);
            $table->index(['message_batch_id', 'random_position']);
        });

        Schema::create('message_batch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_batch_events');
        Schema::dropIfExists('message_batch_recipients');
        Schema::dropIfExists('message_batches');
        Schema::dropIfExists('message_template_versions');
        Schema::dropIfExists('message_templates');
    }
};
