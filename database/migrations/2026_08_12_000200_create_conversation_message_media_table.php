<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mídia recebida, guardada quando alguém precisa dela.
 *
 * Até aqui mídia era só metadado: a conversa mostrava `[midia não baixada]` e o
 * arquivo existia apenas dentro da sessão do WhatsApp Web. Isso tem prazo — o
 * próprio código da transcrição já registrava que mídia antiga simplesmente não
 * volta —, e quem abre uma conversa de três semanas atrás encontrava um
 * marcador no lugar da foto.
 *
 * O cache é preguiçoso de propósito. Nada é baixado por chegar: uma
 * sincronização de trinta dias puxaria centenas de arquivos que ninguém vai
 * abrir. Baixa quem precisar primeiro — o operador que abriu a conversa, ou a
 * visão que vai descrever a imagem para o fluxo responder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations', indexName: 'cmm_conversation_fk')->cascadeOnDelete();

            // Uma mídia por mensagem. O WhatsApp manda um anexo por mensagem, e
            // o único é o que permite `firstOrCreate` sem corrida.
            $table->foreignId('conversation_message_id')->unique('cmm_message_uniq')->constrained('conversation_messages', indexName: 'cmm_message_fk')->cascadeOnDelete();

            $table->string('status', 20)->default('pending')->index('cmm_status_idx');
            $table->string('disk', 30)->nullable();
            $table->string('path')->nullable();
            $table->string('mimetype', 120)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Some arquivo repetido do mesmo conteúdo do relatório de espaço, e
            // permite conferir que o que está em disco é o que foi baixado.
            $table->string('sha256', 64)->nullable()->index('cmm_sha_idx');

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->timestamp('fetched_at')->nullable();

            /*
             | Prazo do arquivo, não do registro.
             |
             | Passados os noventa dias o arquivo sai do disco e esta linha
             | continua, marcada como expurgada. É o que permite a conversa
             | dizer "havia uma foto aqui" em vez de fingir que nunca houve — e
             | é foto de gente que estamos guardando, então o prazo importa.
             */
            $table->timestamp('purge_after')->nullable()->index('cmm_purge_idx');
            $table->timestamp('purged_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_media');
    }
};
