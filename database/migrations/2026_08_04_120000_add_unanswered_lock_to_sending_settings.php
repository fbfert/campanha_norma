<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trava de reciprocidade nas configurações de envio.
 *
 * Os limites que existiam eram todos de ritmo — por minuto, por hora, por dia.
 * Nenhum deles olhava para o outro lado: era possível abordar mil pessoas em
 * ritmo impecável sem que uma única respondesse, e nada no sistema notava.
 *
 * O teto zerado desliga a trava, que é o comportamento de quem já estava
 * enviando antes desta coluna existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sending_settings', function (Blueprint $table): void {
            $table->unsignedInteger('unanswered_lock_threshold')
                ->default(10)
                ->after('max_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('sending_settings', function (Blueprint $table): void {
            $table->dropColumn('unanswered_lock_threshold');
        });
    }
};
