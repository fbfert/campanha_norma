<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 9D: colunas de fundamentacao na sugestao de resposta.
 *
 * Migration separada da criacao das tabelas de conhecimento de proposito. As
 * duas mudancas tem perfis de reversao diferentes: dar baixa na camada de
 * conhecimento nao deveria obrigar a mexer na tabela da subetapa anterior, e o
 * contrario tambem vale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_reply_suggestions', function (Blueprint $table): void {
            // Colunas relacionais, nao JSON: a subetapa seguinte precisa filtrar
            // e agregar por fundamentacao.
            $table->boolean('grounded')->default(false)->after('confidence');
            $table->string('grounding_status')->nullable()->after('grounded');
            $table->string('grounding_error')->nullable()->after('grounding_status');
            $table->unsignedInteger('citation_count')->default(0)->after('grounding_error');
            $table->unsignedBigInteger('knowledge_retrieval_id')->nullable()->after('citation_count');

            $table->index('grounding_status', 'crs_grounding_status_idx');
            $table->foreign('knowledge_retrieval_id', 'crs_retrieval_fk')
                ->references('id')->on('knowledge_retrievals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_reply_suggestions', function (Blueprint $table): void {
            $table->dropForeign('crs_retrieval_fk');
            $table->dropIndex('crs_grounding_status_idx');
            $table->dropColumn([
                'grounded',
                'grounding_status',
                'grounding_error',
                'citation_count',
                'knowledge_retrieval_id',
            ]);
        });
    }
};
