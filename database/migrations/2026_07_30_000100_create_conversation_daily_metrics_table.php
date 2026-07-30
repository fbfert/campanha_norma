<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 9E. Métricas diárias de participação.
 *
 * Guarda apenas contagem que aparece no painel executivo e não muda depois do
 * dia fechado. Tema, geografia e detalhamento ficam em consulta ao vivo, porque
 * são recortados de muitas formas e mudam quando alguém corrige uma
 * classificação.
 *
 * A chave natural e (dia, fluxo). O fluxo aceita nulo para representar o total
 * do dia somando todos os fluxos, e a coluna auxiliar `flow_key` existe porque
 * MySQL não trata nulos como iguais em índice único: sem ela, reconstruir o
 * mesmo dia criaria linha nova a cada execução em vez de atualizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->foreignId('conversation_flow_id')->nullable()->constrained('conversation_flows')->nullOnDelete();
            $table->unsignedBigInteger('flow_key')->default(0);

            $table->unsignedInteger('approached')->default(0);
            $table->unsignedInteger('permission_granted')->default(0);
            $table->unsignedInteger('permission_denied')->default(0);
            $table->unsignedInteger('opted_out')->default(0);
            $table->unsignedInteger('answers_received')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('waiting_human')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('automated_messages')->default(0);
            $table->unsignedInteger('conversations_with_turns')->default(0);
            $table->unsignedInteger('first_reply_seconds_total')->default(0);
            $table->unsignedInteger('first_reply_samples')->default(0);

            $table->timestamp('rebuilt_at')->nullable();
            $table->timestamps();

            $table->unique(['date', 'flow_key'], 'cdm_date_flow_unique');
            $table->index(['date'], 'cdm_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_daily_metrics');
    }
};
