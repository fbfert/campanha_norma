<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mensagem que o ganhador recebe junto com o cupom.
 *
 * Estava fixa no job: "Parabéns! Você foi sorteado. Seu código de acesso é:".
 * Frase de código não serve para prêmio nenhum — o cupom pode ser um curso, um
 * ingresso ou um desconto, e cada um pede uma instrução diferente do que fazer
 * com o código depois de recebê-lo.
 *
 * Nulo mantém o texto que já saía, para campanha existente não mudar de
 * comportamento por causa desta migração.
 *
 * O que fica gravado aqui é o molde, com `{codigo}` no lugar do código. O
 * código em si continua sem existir fora do momento do envio: é isso que
 * permite guardar a mensagem em banco sem guardar o prêmio junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_campaigns', function (Blueprint $table): void {
            $table->text('coupon_text')->nullable()->after('out_of_window_text');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_campaigns', function (Blueprint $table): void {
            $table->dropColumn('coupon_text');
        });
    }
};
