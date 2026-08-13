<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atendimento automático de quem escreve primeiro.
 *
 * Até aqui todo fluxo conversacional nascia de um lote: o sistema mandava a
 * mensagem inicial e, no envio bem-sucedido, abria o fluxo na conversa. Quem
 * escrevia por conta própria caía em `handleIncomingMessage` sem estado, e o
 * motor saía calado — atendimento humano, ou silêncio quando ninguém estava
 * olhando.
 *
 * O perfil é o equivalente do lote para esse lado: diz qual fluxo usar, o que
 * responder na abertura, em que horário e com que teto. A diferença é a
 * seleção — o lote escolhe contatos na base, e aqui quem escolhe é quem
 * escreve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_attendance_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');

            /*
             | Perfil que atende o que sobrou.
             |
             | Roteamento por expressão erra sempre para o mesmo lado: alguém
             | escreve algo que nenhuma regra previu. Sem um destino para o
             | resto, essa pessoa fica sem resposta justamente por ter escrito
             | algo fora do script. Exatamente um perfil ativo carrega esta
             | marca, e o formulário recusa salvar sem ela.
             */
            $table->boolean('is_fallback')->default(false);
            $table->text('match_expressions')->nullable();
            $table->unsignedInteger('match_priority')->default(100);

            $table->foreignId('conversation_flow_id')->nullable()->constrained('conversation_flows')->nullOnDelete();
            $table->string('opening_mode', 30)->default('ai_then_survey');
            $table->text('presentation_text')->nullable();

            // Janela própria: o perfil de fora do horário comercial existe
            // justamente para dizer outra coisa fora do horário comercial.
            $table->string('window_start', 5)->nullable();
            $table->string('window_end', 5)->nullable();

            $table->unsignedInteger('daily_start_limit')->default(50);

            /*
             | Homologação: perfil novo não sai sozinho.
             |
             | O primeiro dia de um perfil é onde se descobre que a expressão
             | pegou o que não devia e que o texto de abertura soa errado. Até
             | acumular `homologation_threshold` conversas iniciadas por gente,
             | toda mensagem que cair neste perfil espera um clique.
             */
            $table->unsignedInteger('homologation_threshold')->default(5);
            $table->unsignedInteger('approved_starts_count')->default(0);
            $table->timestamp('homologated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'match_priority']);
            $table->index('is_fallback');
        });

        /*
         | Toda decisão fica registrada, inclusive a de não fazer nada.
         |
         | Sem isto, "por que essa conversa não foi atendida" só se responde
         | lendo log de servidor. O motivo é o que a tela de fila mostra ao
         | lado de cada conversa parada, e é por esta tabela que o teto diário
         | conta o que já saiu hoje.
         */
        Schema::create('inbound_attendance_attempts', function (Blueprint $table): void {
            $table->id();
            /*
             | Nomes de chave estrangeira explícitos, e não os que o Laravel
             | inventa.
             |
             | `inbound_attendance_attempts_inbound_attendance_profile_id_foreign`
             | tem 65 caracteres, e o limite de identificador no MySQL é 64. A
             | migração quebrava no meio: as duas tabelas ficavam criadas e a
             | chave estrangeira, não. O SQLite dos testes não nomeia chave
             | assim, então a suíte inteira passava sem tocar no defeito.
             */
            $table->foreignId('conversation_id')->constrained('conversations', indexName: 'iaa_conversation_fk')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages', indexName: 'iaa_message_fk')->nullOnDelete();
            $table->foreignId('inbound_attendance_profile_id')->nullable()->constrained('inbound_attendance_profiles', indexName: 'iaa_profile_fk')->nullOnDelete();
            $table->string('outcome', 30);
            $table->string('reason', 60)->nullable();

            // Preenchido quando alguém clicou em iniciar. Nulo é automático, e
            // é essa distinção que faz a contagem de homologação.
            $table->foreignId('started_by')->nullable()->constrained('users', indexName: 'iaa_started_by_fk')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'iaa_conversation_idx');
            $table->index(['outcome', 'created_at'], 'iaa_outcome_created_idx');
        });

        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            // Mesma razão do nome curto acima: o padrão passaria de 64.
            $table->foreignId('inbound_attendance_profile_id')
                ->nullable()
                ->after('conversation_flow_id')
                ->constrained('inbound_attendance_profiles', indexName: 'cfs_inbound_profile_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_flow_states', function (Blueprint $table): void {
            $table->dropForeign('cfs_inbound_profile_fk');
            $table->dropColumn('inbound_attendance_profile_id');
        });

        Schema::dropIfExists('inbound_attendance_attempts');
        Schema::dropIfExists('inbound_attendance_profiles');
    }
};
