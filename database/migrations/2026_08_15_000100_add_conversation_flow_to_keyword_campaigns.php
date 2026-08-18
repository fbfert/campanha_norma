<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campanha por palavra-chave passa a poder abrir uma pesquisa.
 *
 * O caminho é o mesmo do lote: o lote carrega um `conversation_flow_id` e, no
 * envio bem-sucedido, chama `activateForConversation`. Aqui a campanha carrega
 * o mesmo campo e faz a mesma chamada quando a confirmação sai. Todo o resto —
 * pedir permissão, escolher a pergunta, interpretar a resposta e continuar a
 * conversa — é o motor da 9A, da 9B e da 9C, sem nada novo.
 *
 * Nulo é o padrão, e significa campanha que só sorteia: inscreve, confirma e
 * não pergunta nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_campaigns', function (Blueprint $table): void {
            /*
             | Sem nome de índice próprio, de propósito.
             |
             | O nome que o Laravel gera aqui —
             | `keyword_campaigns_conversation_flow_id_foreign` — tem 46
             | caracteres e cabe folgado no limite de 64 do MySQL. Batizar à
             | mão só é necessário quando o nome gerado estoura, como acontece
             | em `keyword_campaign_participations`.
             |
             | E o nome convencional é o que torna a migração reversível nos
             | dois bancos: o SQLite recusa derrubar chave estrangeira por
             | nome, mas aceita por coluna — e derrubar por coluna exige que o
             | nome seja o que a convenção prevê. Ver o `down()`.
             */
            $table->foreignId('conversation_flow_id')
                ->nullable()
                ->after('status')
                ->constrained('conversation_flows')
                ->nullOnDelete();

            /*
             | O texto que emenda a confirmação ao pedido de permissão.
             |
             | Nulo usa o `presentation_text` do fluxo, que é onde o pedido de
             | permissão já mora. O campo existe para o caso em que a frase
             | precisa mudar por causa do sorteio — "além disso, posso te fazer
             | uma pergunta?" lê diferente de uma abertura fria.
             */
            $table->text('survey_invite_text')->nullable()->after('already_enrolled_text');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_campaigns', function (Blueprint $table): void {
            /*
             | Por coluna, e não por nome.
             |
             | `dropForeign('nome')` estoura no SQLite: a gramática recusa
             | derrubar chave estrangeira por nome. `dropForeign(['coluna'])`
             | passa nos dois — o SQLite resolve durante a reconstrução da
             | tabela, e o MySQL deriva o nome da convenção.
             |
             | Sem isto o `down()` só rodava em MySQL, e a reversão ficava sem
             | como ser exercitada pela suíte.
             */
            $table->dropForeign(['conversation_flow_id']);
            $table->dropColumn(['conversation_flow_id', 'survey_invite_text']);
        });
    }
};
