<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A que tema da população este documento oficial responde.
 *
 * Serve a uma pergunta só: quais temas foram citados e ainda não têm posição
 * escrita. É a pauta de posicionamento da 9F, e nada mais.
 *
 * **A recuperação da 9D não usa esta coluna em nenhuma hipótese.** A trava
 * daquela subetapa é estrutural — um teste lê o código do recuperador, com os
 * comentários removidos, e falha se as tabelas de conversa ou de insight
 * aparecerem lá. A razão é que a opinião da população nunca pode virar fonte de
 * resposta individual: o que o eleitor disse não é posição da campanha.
 *
 * O cruzamento entre tema e documento aprovado acontece fora daquela camada, em
 * serviço próprio, que consulta estas tabelas diretamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            // Sem `indexName`: a chave recebe o nome da convenção, e é isso que
            // permite ao `down()` derrubá-la por coluna. Derrubar por nome
            // estoura no SQLite, e a migração viraria irreversível justamente no
            // banco da suíte, que é onde a descida seria exercitada.
            $table->foreignId('insight_topic_id')
                ->nullable()
                ->after('type')
                ->constrained('insight_topics')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropForeign(['insight_topic_id']);
            $table->dropColumn('insight_topic_id');
        });
    }
};
