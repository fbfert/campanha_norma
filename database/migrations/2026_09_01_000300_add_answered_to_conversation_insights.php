<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcação manual de "já respondi".
 *
 * A marcação principal é detectada: se a candidata gravar o áudio na mesma
 * conta pareada ao sistema, ele aparece na sincronização como saída com mídia e
 * a fila se marca sozinha. Disciplina é o que não sobrevive à terceira semana
 * de campanha, e por isso a detecção vem primeiro.
 *
 * Estas colunas são a reserva, para o caso de ela responder de outro número —
 * situação em que a detecção não tem como funcionar. A marcação manual tem
 * precedência sobre a detecção: ela é a afirmação de uma pessoa, e a detecção é
 * evidência forte, não prova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_insights', function (Blueprint $table): void {
            // Índice e chave ficam com o nome da convenção, para o `down()`
            // poder derrubá-los por coluna. Nome batizado à mão só se justifica
            // quando o gerado passa de 64 caracteres, que não é o caso aqui.
            $table->timestamp('answered_at')->nullable()->after('reviewed_at')->index();
            $table->foreignId('answered_by')
                ->nullable()
                ->after('answered_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_insights', function (Blueprint $table): void {
            $table->dropForeign(['answered_by']);
            $table->dropIndex(['answered_at']);
            $table->dropColumn(['answered_at', 'answered_by']);
        });
    }
};
