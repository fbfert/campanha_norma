<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Limpeza e a lixeira dela.
 *
 * Três coisas acontecem aqui.
 *
 * A primeira e o par `cleanup_operations` / `cleanup_items`: cada limpeza vira
 * uma operação com os itens que ela tirou do ar. Isso existe porque a lixeira
 * precisa saber o que **a Limpeza** removeu, e não simplesmente listar tudo que
 * está com `deleted_at` preenchido — um contato excluído pela tela de Contatos
 * também deixa linha marcada, e misturar as duas coisas faria a restauração
 * ressuscitar registro que ninguém mandou ressuscitar.
 *
 * A segunda e o `deleted_at` nas tabelas de participação. Sem ele a Limpeza
 * teria de apagar de verdade, e a decisão foi lixeira reversível.
 *
 * A terceira e `cleanup_trash_key`, e ela merece explicação.
 *
 * Marcar uma linha como excluída não a tira do índice único. Uma inscrição
 * limpa continuaria ocupando `(campanha, contato)`, e a pessoa nunca mais
 * conseguiria se inscrever naquela campanha: o `INSERT` bateria contra uma
 * linha que a consulta não enxerga e o banco enxerga. O mesmo vale para o
 * destinatário de lote, para o identificador externo da mensagem e para o
 * código do cupom, que uma reimportação precisa poder gravar de novo.
 *
 * A saída óbvia — acrescentar `deleted_at` ao índice — não funciona, e o modo
 * como ela falha e pior do que não fazer nada. Em MySQL e em SQLite, NULL conta
 * como valor **distinto** dentro de índice único; como toda linha viva tem
 * `deleted_at` nulo, duas linhas vivas iguais passariam a ser aceitas. O índice
 * continuaria existindo e pararia de garantir o que existe para garantir. Isso
 * foi tentado nesta mesma migração e a suíte acusou na hora: a segunda
 * inscrição da mesma pessoa na mesma campanha deixou de ser recusada.
 *
 * `cleanup_trash_key` resolve sem NULL nenhum. Vale 0 enquanto a linha está
 * viva e passa a valer o próprio id quando ela vai para a lixeira. Linha viva
 * duplicada colide em 0, como sempre colidiu; linha na lixeira nunca colide com
 * nada, porque id não se repete. Quem mantem o valor e
 * `App\Support\MantemChaveDeLixeira`, e ele cobre tanto a exclusão de um
 * modelo quanto a exclusão em massa pelo construtor de consultas.
 *
 * ## O índice novo nasce antes de o antigo morrer
 *
 * A ordem não e estilo. No MySQL, `kcc_participation_unq` e
 * `cfs_conversation_uniq` são os únicos índices que sustentam suas chaves
 * estrangeiras; derrubá-los primeiro devolve errno 150 e a migração morre no
 * meio, em produção, com metade das tabelas alteradas. O SQLite dos testes não
 * tem essa restrição e aprovaria a ordem errada sem reclamar — foi preciso ler
 * o `SHOW CREATE TABLE` do banco real para descobrir.
 *
 * Criando o índice novo primeiro, ele passa a servir a chave estrangeira (mesma
 * coluna à esquerda) e o antigo sai sem drama. Por isso o índice novo também
 * ganha nome próprio em vez de reaproveitar o antigo: dois índices não podem
 * dividir um nome, e renomear depois seria um passo a mais para dar errado.
 *
 * Fica registrado de passagem: `conversation_tags` usa `unique(slug,
 * deleted_at)` e tem o defeito descrito lá em cima. Não e mexido aqui porque
 * não e assunto desta migração, mas e o mesmo problema esperando alguém
 * cadastrar duas etiquetas de mesmo slug.
 */
return new class extends Migration
{
    /**
     * Tabelas que ganham lixeira, e os índices únicos a refazer em cada uma.
     *
     * Cada índice e `[nome antigo, nome novo, colunas]`. `contact_history` não
     * tem índice único e por isso não ganha a chave: a coluna só existe onde
     * ela tem trabalho a fazer.
     *
     * @var array<string, list<array{0: string, 1: string, 2: list<string>}>>
     */
    private const TABELAS = [
        'keyword_campaign_participations' => [
            ['kcp_campaign_contact_unq', 'kcp_campaign_contact_trash_unq', ['keyword_campaign_id', 'contact_id']],
        ],
        'keyword_campaign_coupons' => [
            ['kcc_campaign_code_unq', 'kcc_campaign_code_trash_unq', ['keyword_campaign_id', 'code']],
            ['kcc_participation_unq', 'kcc_participation_trash_unq', ['keyword_campaign_participation_id']],
        ],
        'message_batch_recipients' => [
            ['mbr_request_id_uniq', 'mbr_request_id_trash_unq', ['request_id']],
            ['message_batch_recipients_message_batch_id_contact_id_unique', 'mbr_batch_contact_trash_unq', ['message_batch_id', 'contact_id']],
        ],
        'conversation_messages' => [
            ['conversation_messages_event_id_unique', 'cm_event_id_trash_unq', ['event_id']],
            ['conversation_messages_provider_external_message_id_unique', 'cm_provider_external_trash_unq', ['provider', 'external_message_id']],
            ['conversation_messages_request_id_unique', 'cm_request_id_trash_unq', ['request_id']],
        ],
        'conversation_insights' => [
            ['ci_message_version_uniq', 'ci_message_version_trash_unq', ['source_message_id', 'extraction_version']],
        ],
        'conversation_flow_states' => [
            ['cfs_conversation_uniq', 'cfs_conversation_trash_unq', ['conversation_id']],
        ],
        'whatsapp_test_messages' => [
            ['whatsapp_test_messages_request_id_unique', 'wtm_request_id_trash_unq', ['request_id']],
        ],
        'contact_history' => [],
    ];

    public function up(): void
    {
        Schema::create('cleanup_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name_snapshot')->nullable();
            $table->string('contact_phone_snapshot');
            $table->json('targets');
            $table->text('reason');
            $table->unsignedInteger('items_count')->default(0);
            $table->boolean('involved_draw')->default(false);
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            /*
             | `dateTime` e não `timestamp`, e não é preferência.
             |
             | Com `explicit_defaults_for_timestamp=0` — que é como este MySQL
             | está — só a primeira coluna TIMESTAMP da tabela ganha default
             | implícito. As seguintes recebem '0000-00-00 00:00:00', que o
             | NO_ZERO_DATE do sql_mode recusa, e o CREATE TABLE morre inteiro.
             |
             | Os dois campos são obrigatórios de verdade: sem `expires_at` a
             | lixeira não sabe quando expurgar, e deixá-lo nullable só para
             | contornar o MySQL trocaria a garantia por uma convenção.
             */
            $table->dateTime('executed_at');
            $table->dateTime('expires_at')->index();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'executed_at']);
        });

        Schema::create('cleanup_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleanup_operation_id')->constrained('cleanup_operations', indexName: 'cli_operation_fk')->cascadeOnDelete();
            $table->string('target');
            $table->string('record_table');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('summary');
            $table->json('restore_payload')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['record_table', 'record_id'], 'cli_record_idx');
        });

        foreach (self::TABELAS as $tabela => $indices) {
            Schema::table($tabela, function (Blueprint $table) use ($indices): void {
                $table->softDeletes();

                if ($indices !== []) {
                    $table->unsignedBigInteger('cleanup_trash_key')->default(0);
                }
            });

            foreach ($indices as [$antigo, $novo, $colunas]) {
                Schema::table($tabela, function (Blueprint $table) use ($novo, $colunas): void {
                    $table->unique([...$colunas, 'cleanup_trash_key'], $novo);
                });

                Schema::table($tabela, function (Blueprint $table) use ($antigo): void {
                    $table->dropUnique($antigo);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela => $indices) {
            foreach ($indices as [$antigo, $novo, $colunas]) {
                Schema::table($tabela, function (Blueprint $table) use ($antigo, $colunas): void {
                    $table->unique($colunas, $antigo);
                });

                Schema::table($tabela, function (Blueprint $table) use ($novo): void {
                    $table->dropUnique($novo);
                });
            }

            Schema::table($tabela, function (Blueprint $table) use ($indices): void {
                if ($indices !== []) {
                    $table->dropColumn('cleanup_trash_key');
                }

                $table->dropSoftDeletes();
            });
        }

        Schema::dropIfExists('cleanup_items');
        Schema::dropIfExists('cleanup_operations');
    }
};
