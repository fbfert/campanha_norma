<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Migração nova precisa poder descer no banco da suíte.
 *
 * `dropForeign('nome_da_chave')` estoura no SQLite: a gramática recusa derrubar
 * chave estrangeira por nome. `dropForeign(['coluna'])` passa nos dois bancos —
 * o SQLite resolve durante a reconstrução da tabela, e o MySQL deriva o nome
 * pela convenção `{tabela}_{coluna}_foreign`.
 *
 * O efeito de errar isso é discreto: a subida funciona em todo lugar, e só a
 * descida quebra — em SQLite, que é justamente onde ela poderia ser exercitada.
 * Na prática a migração vira irreversível sem ninguém perceber.
 *
 * Derrubar por coluna exige que a chave tenha o nome da convenção. Batizar à
 * mão continua certo quando o nome gerado passa de 64 caracteres, que é o
 * limite do MySQL — nesse caso o `down()` realmente só roda em MySQL, e o
 * arquivo entra na lista de exceções abaixo com o motivo escrito.
 */
class MigracaoPodeSerRevertidaTest extends TestCase
{
    /**
     * Migrações já implantadas com chave batizada à mão.
     *
     * Não estão erradas: estão fora de alcance. Corrigi-las exigiria reverter
     * migrações que acrescentam coluna a tabela com dado dentro, e a reversão
     * apaga a coluna. O custo é conhecido e aceito — o `down()` delas roda em
     * MySQL, que é a produção, e não roda em SQLite.
     *
     * A lista não cresce. Migração nova usa a forma por coluna.
     *
     * @var list<string>
     */
    private const HERDADAS = [
        '2026_07_29_000100_create_conversation_automation_tables.php',
        '2026_07_29_000300_create_reply_suggestion_tables.php',
        '2026_07_29_000500_add_grounding_to_conversation_reply_suggestions.php',
        '2026_08_12_000100_create_inbound_attendance_tables.php',
    ];

    public function test_migracao_nova_derruba_chave_estrangeira_por_coluna(): void
    {
        $problemas = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $caminho) {
            $arquivo = basename($caminho);

            if (in_array($arquivo, self::HERDADAS, true)) {
                continue;
            }

            foreach (file($caminho) ?: [] as $indice => $linha) {
                // Comentário não é código. O comentário desta própria regra cita
                // a forma errada como exemplo, e sem esta linha o teste se
                // acusaria.
                if (preg_match('/^\s*(\*|\||\/\/|\/\*|#)/', $linha)) {
                    continue;
                }

                // Só a forma com string literal: `dropForeign(['coluna'])` passa.
                if (preg_match("/dropForeign\(\s*['\"]/", $linha)) {
                    $problemas[] = $arquivo.':'.($indice + 1).' — '.trim($linha);
                }
            }
        }

        $this->assertSame([], $problemas, implode(PHP_EOL, array_merge(
            ['Chave estrangeira derrubada por nome. O SQLite recusa, e a migração deixa de ser reversível na suíte.', ''],
            $problemas,
            ['', 'Troque por `dropForeign([\'nome_da_coluna\'])` e deixe o `up()` sem `indexName`,',
                'para que a chave receba o nome da convenção.',
                'Se o nome gerado passar de 64 caracteres, batize à mão e acrescente o arquivo',
                'à lista HERDADAS deste teste, com o motivo.'],
        )));
    }

    /**
     * A exceção precisa continuar descrevendo um arquivo que existe.
     *
     * Sem isto, um arquivo renomeado deixaria uma entrada morta na lista, e a
     * próxima migração com o mesmo nome herdaria a dispensa sem que ninguém
     * tivesse decidido isso.
     */
    public function test_a_lista_de_excecoes_nao_tem_entrada_morta(): void
    {
        foreach (self::HERDADAS as $arquivo) {
            $this->assertFileExists(
                database_path('migrations/'.$arquivo),
                "A migração {$arquivo} não existe mais: tire-a da lista de exceções.",
            );
        }
    }
}
