<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orientação de resposta e linha vermelha, por tema.
 *
 * A informação pertence ao tema: é sobre saúde, sobre transporte, sobre creche
 * que se define o que dizer e o que não prometer. Morando aqui, ela aparece no
 * cadastro de temas que já existe, não exige tela nova e desaparece junto com o
 * tema quando ele é desativado.
 *
 * A alternativa seria tabela própria com cadastro próprio — migration, model,
 * controller, formulário e permissão para guardar dois campos de texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_topics', function (Blueprint $table): void {
            $table->text('response_guidance')->nullable()->after('description');
            $table->text('red_lines')->nullable()->after('response_guidance');
        });
    }

    public function down(): void
    {
        Schema::table('insight_topics', function (Blueprint $table): void {
            $table->dropColumn(['response_guidance', 'red_lines']);
        });
    }
};
