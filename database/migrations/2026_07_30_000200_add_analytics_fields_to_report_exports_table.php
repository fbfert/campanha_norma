<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 9E. Campos de finalidade e anonimização na exportação.
 *
 * A tabela da Etapa 6 já controla status, filtros, expiração e arquivo privado.
 * O que falta para a 9E e o registro de responsabilidade: qual o escopo, se o
 * conteúdo foi anonimizado e para que a exportação foi pedida.
 *
 * `pseudonym_salt` guarda o sal usado para derivar o pseudônimo daquela
 * exportação. Ele fica aqui apenas para permitir reprocessar o mesmo arquivo em
 * caso de falha; não e exibido em tela nem exportado, e sem ele o pseudônimo e
 * irreversível. Duas exportações do mesmo período recebem sais diferentes, e
 * por isso não podem ser cruzadas para reidentificar alguém.
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
