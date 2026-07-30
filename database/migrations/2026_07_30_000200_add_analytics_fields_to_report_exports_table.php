<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 9E. Campos de finalidade e anonimizacao na exportacao.
 *
 * A tabela da Etapa 6 ja controla status, filtros, expiracao e arquivo privado.
 * O que falta para a 9E e o registro de responsabilidade: qual o escopo, se o
 * conteudo foi anonimizado e para que a exportacao foi pedida.
 *
 * `pseudonym_salt` guarda o sal usado para derivar o pseudonimo daquela
 * exportacao. Ele fica aqui apenas para permitir reprocessar o mesmo arquivo em
 * caso de falha; nao e exibido em tela nem exportado, e sem ele o pseudonimo e
 * irreversivel. Duas exportacoes do mesmo periodo recebem sais diferentes, e
 * por isso nao podem ser cruzadas para reidentificar alguem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('scope')->default('aggregate')->after('report_type');
            $table->text('purpose')->nullable()->after('scope');
            $table->boolean('anonymized')->default(true)->after('purpose');
            $table->string('pseudonym_salt', 64)->nullable()->after('anonymized');

            $table->index(['scope'], 'report_exports_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropIndex('report_exports_scope_idx');
            $table->dropColumn(['scope', 'purpose', 'anonymized', 'pseudonym_salt']);
        });
    }
};
