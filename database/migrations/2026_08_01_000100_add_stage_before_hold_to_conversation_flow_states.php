<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            // Estágio de onde a conversa saiu ao ser pausada ou encaminhada
            // para atendimento humano. Nulo quando ela nunca esteve em espera.
            $table->string('stage_before_hold')->nullable()->after('current_stage');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            $table->dropColumn('stage_before_hold');
        });
    }
};
