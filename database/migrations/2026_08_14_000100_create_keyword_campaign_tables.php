<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campanha por palavra-chave.
 *
 * O atendimento de entrada, da etapa anterior, produz uma conversa: um perfil,
 * um fluxo, uma abertura. Captação por palavra-chave produz outra coisa — uma
 * lista de inscritos, com prova de origem, conferível, congelável e sorteável.
 * Perfil de atendimento não guarda inscrição, não tem vigência, não tem teto de
 * participantes e não sabe dizer quem entrou primeiro.
 *
 * Nada aqui dispara sozinho: sem campanha cadastrada, a avaliação do gatilho
 * encerra na primeira consulta e o pipeline de entrada se comporta como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('rascunho');

            /*
             | As palavras já entram normalizadas.
             |
             | Normalizar na gravação e não na comparação é o que mantém o
             | caminho quente barato: a avaliação roda em toda mensagem
             | recebida, e não pode pagar por normalizar a lista de novo a cada
             | uma. A forma original digitada pelo operador não é guardada
             | porque ninguém a lê — a tela mostra a normalizada, que é a que
             | de fato casa.
             */
            $table->json('keywords');

            /*
             | `dateTime`, e não `timestamp`.
             |
             | No MariaDB, a segunda coluna TIMESTAMP NOT NULL de uma tabela
             | recebe o default implícito `0000-00-00 00:00:00`, que o modo
             | estrito recusa: a migração quebra com "Invalid default value for
             | 'ends_at'". O SQLite dos testes não reproduz isso, então a suíte
             | inteira passa e o erro só aparece na hora de implantar — foi
             | exatamente o que aconteceu aqui.
             */
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // Nulo é sem limite. Zero seria um limite de zero, que é outra
            // coisa, e o formulário recusa.
            $table->unsignedInteger('participant_limit')->nullable();

            /*
             | Teto por hora não freia nada: é alarme.
             |
             | A contenção de verdade é o limitador global de confirmação, que
             | é por unidade de tempo e vale para todas as campanhas juntas.
             | Este número existe para alguém descobrir que a divulgação pegou
             | mais do que se esperava enquanto ainda está acontecendo.
             */
            $table->unsignedInteger('hourly_alert_threshold')->nullable();
            $table->timestamp('hourly_alert_raised_at')->nullable();

            $table->text('confirmation_text');
            $table->text('already_enrolled_text');

            // Nulo é silêncio deliberado: campanha encerrada que não responde
            // nada é melhor do que campanha encerrada que responde e reabre a
            // conversa com quem chegou tarde.
            $table->text('out_of_window_text')->nullable();

            /*
             | Congelamento.
             |
             | O hash é do conteúdo da lista, não da hora em que foi tirado:
             | congelar duas vezes o mesmo conjunto produz o mesmo hash, e é
             | isso que permite a alguém de fora conferir que a lista sorteada
             | é a lista que foi publicada.
             */
            $table->timestamp('frozen_at')->nullable();
            $table->foreignId('frozen_by')->nullable()->constrained('users', indexName: 'kc_frozen_by_fk')->nullOnDelete();
            $table->string('frozen_list_hash', 64)->nullable();
            $table->unsignedInteger('frozen_list_count')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'kc_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'kc_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // O índice do caminho quente: a consulta de campanhas vigentes roda
            // em toda mensagem recebida.
            $table->index(['status', 'starts_at', 'ends_at'], 'kc_vigencia_idx');
        });

        /*
         | A participação é projeção da mensagem, não efeito colateral dela.
         |
         | Por isso `conversation_message_id` é obrigatório: é a prova de origem
         | e é o que permite reconstruir toda a lista a partir das mensagens já
         | gravadas quando um job morre no meio de uma divulgação.
         */
        Schema::create('keyword_campaign_participations', function (Blueprint $table): void {
            $table->id();

            /*
             | Nomes de chave estrangeira explícitos, e curtos.
             |
             | O nome que o Laravel inventaria para a chave única aqui —
             | `keyword_campaign_participations_keyword_campaign_id_contact_id_unique`
             | — tem 69 caracteres, e o limite do MySQL é 64. O SQLite dos
             | testes não nomeia índice assim, então a suíte passaria inteira
             | sem tocar no defeito e a migração quebraria só em produção. Já
             | aconteceu nesta base, em `inbound_attendance_attempts`.
             */
            $table->foreignId('keyword_campaign_id')->constrained('keyword_campaigns', indexName: 'kcp_campaign_fk')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts', indexName: 'kcp_contact_fk')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained('conversation_messages', indexName: 'kcp_message_fk')->cascadeOnDelete();

            $table->string('matched_keyword', 120);

            // O nome que o provedor informou, preservado mesmo depois de um
            // humano corrigir: a correção vai para `reviewed_name`.
            $table->string('captured_name', 120)->nullable();
            $table->string('reviewed_name', 120)->nullable();
            $table->foreignId('name_reviewed_by')->nullable()->constrained('users', indexName: 'kcp_name_reviewed_by_fk')->nullOnDelete();
            $table->timestamp('name_reviewed_at')->nullable();

            $table->string('status', 20)->default('valida');

            /*
             | Elegibilidade é marcada, nunca verificada na entrada.
             |
             | A campanha é entre alunos, mas exigir prova no momento da
             | mensagem cria atrito no único instante em que a pessoa está
             | engajada, e recusa por engano quem trocou de número. A marcação
             | vem depois, por importação da lista do portal, e o que não casar
             | vai para conferência humana.
             */
            $table->string('eligibility', 20)->default('nao_verificada');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'kcp_reviewed_by_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            /*
             | Invalidação não apaga.
             |
             | A participação continua gravada, sai do sorteio e carrega o
             | motivo escrito. Apagar tornaria impossível responder "por que
             | fulano não está na lista", que é a pergunta que aparece depois do
             | anúncio do ganhador.
             */
            $table->foreignId('invalidated_by')->nullable()->constrained('users', indexName: 'kcp_invalidated_by_fk')->nullOnDelete();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();

            $table->timestamps();

            /*
             | Unicidade no banco, não na aplicação.
             |
             | Duas mensagens quase simultâneas da mesma pessoa perdem a corrida
             | em qualquer verificação feita antes do insert. A trava por
             | conversa cobre o caso comum; esta chave cobre o resto.
             */
            $table->unique(['keyword_campaign_id', 'contact_id'], 'kcp_campaign_contact_unq');

            $table->index(['keyword_campaign_id', 'status'], 'kcp_campaign_status_idx');
            $table->index(['keyword_campaign_id', 'eligibility'], 'kcp_campaign_eligibility_idx');
        });

        Schema::create('keyword_campaign_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_campaign_id')->constrained('keyword_campaigns', indexName: 'kcd_campaign_fk')->cascadeOnDelete();

            // O hash da lista no momento do sorteio. Confere com o da campanha
            // enquanto ninguém recongelar, e é o que prova qual lista foi
            // sorteada mesmo depois de um recongelamento posterior.
            $table->string('list_hash', 64);

            /*
             | A semente é registrada em claro, de propósito.
             |
             | Semente guardada em segredo não serve de nada: a auditoria do
             | sorteio é justamente alguém de fora reexecutar com a mesma
             | semente e a mesma lista e chegar ao mesmo resultado.
             */
            $table->string('seed', 128);

            $table->unsignedInteger('quantity');

            // Os identificadores de participação na ordem sorteada. A ordem é
            // parte do resultado: primeiro sorteado é o ganhador, os seguintes
            // são a fila de suplentes.
            $table->json('result');

            $table->foreignId('executed_by')->nullable()->constrained('users', indexName: 'kcd_executed_by_fk')->nullOnDelete();
            // Mesma razão do `dateTime` acima: NOT NULL depois de outra coluna de tempo.
            $table->dateTime('executed_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['keyword_campaign_id', 'executed_at'], 'kcd_campaign_executed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_campaign_draws');
        Schema::dropIfExists('keyword_campaign_participations');
        Schema::dropIfExists('keyword_campaigns');
    }
};
