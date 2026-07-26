<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_sync_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversation_sync_runs', 'chats_failed')) {
                $table->unsignedInteger('chats_failed')->default(0)->after('chats_processed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversation_sync_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('conversation_sync_runs', 'chats_failed')) {
                $table->dropColumn('chats_failed');
            }
        });
    }
};
