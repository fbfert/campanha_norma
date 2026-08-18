<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os cupons do prêmio.
 *
 * Cupom é valor: um código vazado é resgatado por quem o encontrar. Por isso o
 * código não aparece em log, em exportação nem no histórico da conversa — o que
 * vai para o histórico é uma referência a esta linha, não o código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_campaign_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_campaign_id')->constrained('keyword_campaigns', indexName: 'kcc_campaign_fk')->cascadeOnDelete();

            $table->string('code', 120);
            $table->string('status', 20)->default('disponivel');

            /*
             | O cupom aponta para a participação, e não o contrário.
             |
             | É o que permite a atribuição ser uma escrita só, dentro de uma
             | transação, com a garantia de unicidade vindo do banco: dois
             | ganhadores não podem receber o mesmo código porque o código é
             | uma linha só, e ela só tem um dono.
             */
            $table->foreignId('keyword_campaign_participation_id')
                ->nullable()
                ->constrained('keyword_campaign_participations', indexName: 'kcc_participation_fk')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Referência que vai para o histórico da conversa no lugar do
            // código. Curta e sem valor por si só.
            $table->string('reference', 40)->nullable();

            $table->foreignId('imported_by')->nullable()->constrained('users', indexName: 'kcc_imported_by_fk')->nullOnDelete();
            $table->timestamps();

            // Reimportar o mesmo arquivo não duplica código.
            $table->unique(['keyword_campaign_id', 'code'], 'kcc_campaign_code_unq');
            $table->index(['keyword_campaign_id', 'status'], 'kcc_campaign_status_idx');

            // Um cupom por ganhador: a chave única é o que impede duas
            // atribuições concorrentes darem dois cupons à mesma pessoa.
            $table->unique('keyword_campaign_participation_id', 'kcc_participation_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_campaign_coupons');
    }
};
