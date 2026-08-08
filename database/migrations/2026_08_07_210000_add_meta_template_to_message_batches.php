<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template aprovado que abre a conversa deste lote.
 *
 * Na API oficial, a abordagem acontece fora da janela aberta pela pessoa, e ali
 * só sai template previamente aprovado pela Meta. Fica por lote — e não apenas
 * em configuração global — porque campanhas diferentes abrem conversa de formas
 * diferentes, e cada texto é um template separado.
 *
 * Vazio cai no padrão da configuração, e o WhatsApp Web ignora a coluna: lá toda
 * mensagem é livre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_batches', function (Blueprint $table): void {
            $table->string('meta_template_name')->nullable()->after('message_body_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('message_batches', function (Blueprint $table): void {
            $table->dropColumn('meta_template_name');
        });
    }
};
