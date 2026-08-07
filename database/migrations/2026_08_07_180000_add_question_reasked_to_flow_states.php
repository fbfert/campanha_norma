<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que a pergunta já foi refeita uma vez nesta conversa.
 *
 * Quem responde ao convite enquanto a pergunta já está a caminho manda algo que
 * não é resposta a ela — "pode sim", "tudo bem", "claro". O fluxo contava
 * aquilo como a resposta e seguia adiante, e a pergunta ficava sem ser
 * respondida para sempre. Em 43% das mensagens que chegam em até quinze
 * segundos das nossas é exatamente isso.
 *
 * Refazer a pergunta resolve, mas precisa de teto: sem esta marca, alguém que
 * responda "sim" de novo receberia a pergunta de novo, indefinidamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            $table->timestamp('question_reasked_at')->nullable()->after('selected_question_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            $table->dropColumn('question_reasked_at');
        });
    }
};
