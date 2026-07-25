<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batches', function (Blueprint $table): void {
            $table->boolean('is_campaign')->default(false)->after('description')->index();
            $table->json('campaign_templates_snapshot')->nullable()->after('message_body_snapshot');
        });

        Schema::table('message_batch_recipients', function (Blueprint $table): void {
            $table->foreignId('message_template_id')->nullable()->after('contact_id')->constrained('message_templates')->nullOnDelete();
            $table->unsignedInteger('message_template_version')->nullable()->after('message_template_id');
            $table->string('message_template_name_snapshot')->nullable()->after('message_template_version');
        });
    }

    public function down(): void
    {
        Schema::table('message_batch_recipients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_template_id');
            $table->dropColumn(['message_template_version', 'message_template_name_snapshot']);
        });

        Schema::table('message_batches', function (Blueprint $table): void {
            $table->dropColumn(['is_campaign', 'campaign_templates_snapshot']);
        });
    }
};
