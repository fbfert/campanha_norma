<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_flows', function (Blueprint $table): void {
            // `sorteio` preserva o comportamento de todo fluxo que já existe.
            $table->string('question_order')->default('sorteio')->after('max_main_questions');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_flows', function (Blueprint $table): void {
            $table->dropColumn('question_order');
        });
    }
};
