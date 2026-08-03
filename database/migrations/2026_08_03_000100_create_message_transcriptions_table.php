<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transcrição de áudio recebido.
     *
     * Tabela própria, e não uma coluna em `conversation_messages`, por três
     * motivos: o corpo da mensagem continua sendo o registro do que chegou —
     * áudio chega vazio mesmo, e sobrescrever apagaria essa distinção; uma
     * transcrição pode ser refeita com outro modelo sem perder a anterior; e a
     * retenção de transcrição e uma decisão separada da retenção da mensagem.
     */
    public function up(): void
    {
        Schema::create('message_transcriptions', function (Blueprint $table): void {
            $table->id();

            // Ligada a conversa e a mensagem: a mensagem e a âncora exata, a
            // conversa e o que se consulta na prática.
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('pending')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('media_type')->nullable();

            $table->text('text')->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('media_bytes')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('transcribed_at')->nullable();

            $table->timestamps();

            // Uma transcrição viva por mensagem. Refazer marca a anterior como
            // substituída, e não cria duas valendo ao mesmo tempo.
            $table->index(['conversation_message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_transcriptions');
    }
};
