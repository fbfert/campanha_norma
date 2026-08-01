<?php

namespace Tests\Feature;

use App\Enums\KnowledgeBaseStatus;
use App\Models\InsightTopic;
use App\Models\KnowledgeBase;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importação da taxonomia e da ficha das bases.
 *
 * Importação grava, e por isso o que se verifica aqui não e "funciona", e sim
 * "não faz mais do que prometeu": não apaga, não ativa base, não forja
 * aprovação, não aceita arquivo executável e não grava sem alguém confirmar.
 */
class ConfigurationImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('planilha.csv', $content);
    }

    /** Envia o arquivo, confere e confirma, como a tela faz. */
    private function importTopics(string $content, ?User $user = null): void
    {
        $user ??= $this->userWith('administrador');

        $preview = $this->actingAs($user)
            ->post(route('admin.insight-topics.import.preview'), ['file' => $this->csv($content)])
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.insight-topics.import.confirm'), [
                'stored' => $preview->viewData('stored'),
            ])
            ->assertRedirect(route('admin.insight-topics.index'));
    }

    // --- Duas fases ----------------------------------------------------------

    /**
     * A conferência não pode gravar. Se gravasse, o botão de confirmar seria
     * decoração e a tela estaria mentindo.
     */
    public function test_the_preview_does_not_write_anything(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.insight-topics.import.preview'), [
                'file' => $this->csv("tema,identificador\nHabitação,habitacao\n"), // ortografia:ignorar - slug e cabeçalho de CSV
            ])
            ->assertOk()
            ->assertSee('Criar');

        $this->assertSame(0, InsightTopic::count());
    }

    public function test_confirming_writes_what_the_preview_promised(): void
    {
        $this->importTopics("tema,identificador,ordem\nHabitação,habitacao,15\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $topic = InsightTopic::where('slug', 'habitacao')->firstOrFail(); // ortografia:ignorar - slug e cabeçalho de CSV
        $this->assertSame('Habitação', $topic->name);
        $this->assertSame(15, $topic->display_order);
    }

    /**
     * O identificador do arquivo vive na sessão. Sem isso, quem descobrisse o
     * identificador de outra pessoa poderia mandar gravar o arquivo dela.
     */
    public function test_confirming_a_file_that_is_not_in_the_session_is_refused(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.insight-topics.import.confirm'), ['stored' => 'qualquer-coisa.csv'])
            ->assertRedirect(route('admin.insight-topics.import'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, InsightTopic::count());
    }

    // --- Criar e atualizar ---------------------------------------------------

    public function test_an_existing_identifier_updates_instead_of_duplicating(): void
    {
        InsightTopic::factory()->create(['slug' => 'saude', 'name' => 'Nome antigo']); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->importTopics("tema,identificador\nSaúde,saude\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertSame(1, InsightTopic::where('slug', 'saude')->count()); // ortografia:ignorar - slug e cabeçalho de CSV
        $this->assertSame('Saúde', InsightTopic::where('slug', 'saude')->value('name')); // ortografia:ignorar - slug e cabeçalho de CSV
    }

    /**
     * Este e o limite que mais importa. Importação que apaga o que não esta no
     * arquivo e como se perde uma taxonomia inteira por causa de um filtro
     * esquecido na planilha.
     */
    public function test_a_topic_missing_from_the_file_is_not_deleted(): void
    {
        InsightTopic::factory()->create(['slug' => 'estradas', 'name' => 'Estradas']);

        $this->importTopics("tema,identificador\nSaúde,saude\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertNotNull(InsightTopic::where('slug', 'estradas')->first());
    }

    /**
     * Ordenar a planilha por nome basta para o filho vir antes do pai. O vínculo
     * e aplicado numa segunda passagem justamente por isso.
     */
    public function test_a_child_listed_before_its_parent_still_gets_linked(): void
    {
        $this->importTopics("tema,identificador,tema_pai\nEstradas,estradas,infraestrutura\nInfraestrutura,infraestrutura,\n");

        $parent = InsightTopic::where('slug', 'infraestrutura')->firstOrFail();
        $child = InsightTopic::where('slug', 'estradas')->firstOrFail();

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_an_unknown_parent_is_ignored_and_not_invented(): void
    {
        $this->importTopics("tema,identificador,tema_pai\nEstradas,estradas,nao-existe\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertNull(InsightTopic::where('slug', 'estradas')->value('parent_id'));
        $this->assertSame(1, InsightTopic::count());
    }

    /**
     * So pode haver um tema de fallback, e trocá-lo altera para onde vai tudo o
     * que o modelo não soube classificar.
     */
    public function test_the_fallback_column_is_ignored(): void
    {
        $original = InsightTopic::factory()->create(['slug' => 'outros', 'is_fallback' => true]);

        $this->importTopics("tema,identificador,fallback\nHabitação,habitacao,sim\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertFalse(InsightTopic::where('slug', 'habitacao')->value('is_fallback')); // ortografia:ignorar - slug e cabeçalho de CSV
        $this->assertTrue($original->refresh()->is_fallback);
    }

    // --- Linhas recusadas ----------------------------------------------------

    public function test_a_row_without_a_name_is_refused_and_the_rest_is_written(): void
    {
        $this->importTopics("tema,identificador\n,sem-nome\nHabitação,habitacao\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertNull(InsightTopic::where('slug', 'sem-nome')->first());
        $this->assertNotNull(InsightTopic::where('slug', 'habitacao')->first()); // ortografia:ignorar - slug e cabeçalho de CSV
    }

    public function test_an_identifier_repeated_inside_the_file_is_refused_once(): void
    {
        $this->importTopics("tema,identificador\nPrimeiro,repetido\nSegundo,repetido\n");

        $this->assertSame(1, InsightTopic::where('slug', 'repetido')->count());
        $this->assertSame('Primeiro', InsightTopic::where('slug', 'repetido')->value('name'));
    }

    // --- Ida e volta ---------------------------------------------------------

    /**
     * A exportação e o modelo da importação. Se o arquivo que sai não voltar,
     * a promessa da tela esta errada.
     */
    public function test_what_the_export_writes_the_import_reads_back(): void
    {
        $parent = InsightTopic::factory()->create(['slug' => 'infraestrutura', 'name' => 'Infraestrutura']);
        InsightTopic::factory()->create(['slug' => 'estradas', 'name' => 'Estradas', 'parent_id' => $parent->id]);

        $user = $this->userWith('administrador');
        $response = $this->actingAs($user)->get(route('admin.insight-topics.export'))->assertOk();

        ob_start();
        $response->sendContent();
        $exported = (string) ob_get_clean();

        InsightTopic::query()->delete();
        $this->importTopics($exported, $user);

        $reimported = InsightTopic::where('slug', 'estradas')->firstOrFail();
        $this->assertSame('Estradas', $reimported->name);
        $this->assertSame(
            InsightTopic::where('slug', 'infraestrutura')->value('id'),
            $reimported->parent_id,
            'A hierarquia precisa sobreviver a ida e volta.'
        );
    }

    // --- Markdown -------------------------------------------------------------

    private function markdown(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('tabela.md', $content);
    }

    private function importTopicsFrom(UploadedFile $file, ?User $user = null): void
    {
        $user ??= $this->userWith('administrador');

        $preview = $this->actingAs($user)
            ->post(route('admin.insight-topics.import.preview'), ['file' => $file])
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.insight-topics.import.confirm'), ['stored' => $preview->viewData('stored')])
            ->assertRedirect(route('admin.insight-topics.index'));
    }

    public function test_a_markdown_table_is_imported(): void
    {
        $this->importTopicsFrom($this->markdown(
            "| tema | identificador |\n| --- | --- |\n| Habitação | moradia |\n"
        ));

        $this->assertSame('Habitação', InsightTopic::where('slug', 'moradia')->value('name'));
    }

    /**
     * A tabela costuma voltar dentro de um documento, depois de alguém revisar.
     * O que não e linha de tabela precisa ser pulado, e não recusado.
     */
    public function test_text_around_the_table_is_ignored(): void
    {
        $this->importTopicsFrom($this->markdown(
            "# Taxonomia revisada\n\nSegue o que combinamos na reunião:\n\n"
            ."| tema | identificador |\n| --- | --- |\n| Habitação | moradia |\n\n"
            .'Qualquer dúvida me avise.'
        ));

        $this->assertSame(1, InsightTopic::count());
        $this->assertSame('Habitação', InsightTopic::where('slug', 'moradia')->value('name'));
    }

    /**
     * Os sinônimos são separados por barra vertical, que a exportação escapa.
     * Tratar a barra escapada como divisão de coluna partiria cada tema em
     * dezenas de colunas.
     */
    public function test_an_escaped_pipe_comes_back_as_content_and_not_as_a_column(): void
    {
        $this->importTopicsFrom($this->markdown(
            "| tema | identificador | sinonimos |\n| --- | --- | --- |\n" // ortografia:ignorar - cabeçalho de tabela
            ."| Habitação | moradia | casa \\| aluguel \\| moradia |\n"
        ));

        $this->assertSame(
            'casa | aluguel | moradia',
            InsightTopic::where('slug', 'moradia')->value('synonyms')
        );
    }

    /**
     * A exportação escreve `&lt;` para não deixar HTML vivo na tabela. Sem
     * desfazer isso na volta, o texto acumularia um escape a cada ida e volta.
     */
    public function test_the_html_escape_is_undone_on_the_way_back(): void
    {
        $this->importTopicsFrom($this->markdown(
            "| tema | identificador | descricao |\n| --- | --- | --- |\n" // ortografia:ignorar - cabeçalho de tabela
            ."| Habitação | moradia | Faixa &lt;3 salários |\n"
        ));

        $this->assertSame(
            'Faixa <3 salários',
            InsightTopic::where('slug', 'moradia')->value('description')
        );
    }

    public function test_a_markdown_file_without_a_table_is_refused(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.insight-topics.import.preview'), [
                'file' => $this->markdown("# Só um texto\n\nSem tabela nenhuma."),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, InsightTopic::count());
    }

    /**
     * O mesmo teste de ida e volta do CSV, agora pelo Markdown: o que a
     * exportação escreve, a importação lê de volta.
     */
    public function test_what_the_markdown_export_writes_the_import_reads_back(): void
    {
        $parent = InsightTopic::factory()->create(['slug' => 'infraestrutura', 'name' => 'Infraestrutura']);
        InsightTopic::factory()->create([
            'slug' => 'estradas',
            'name' => 'Estradas',
            'parent_id' => $parent->id,
            'synonyms' => 'asfalto|buraco|rodovia',
        ]);

        $user = $this->userWith('administrador');
        $response = $this->actingAs($user)
            ->get(route('admin.insight-topics.export', ['format' => 'markdown']))
            ->assertOk();

        ob_start();
        $response->sendContent();
        $exported = (string) ob_get_clean();

        InsightTopic::query()->delete();
        $this->importTopicsFrom($this->markdown($exported), $user);

        $reimported = InsightTopic::where('slug', 'estradas')->firstOrFail();
        $this->assertSame('Estradas', $reimported->name);
        $this->assertSame('asfalto|buraco|rodovia', $reimported->synonyms);
        $this->assertSame(
            InsightTopic::where('slug', 'infraestrutura')->value('id'),
            $reimported->parent_id
        );
    }

    // --- Arquivo SQL ---------------------------------------------------------

    /**
     * Executar um `.sql` enviado por formulário e execução de comando
     * arbitrário: quem envia o arquivo passa a poder ler ou apagar qualquer
     * tabela.
     */
    public function test_a_sql_file_is_refused(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.insight-topics.import.preview'), [
                'file' => UploadedFile::fake()->createWithContent('carga.sql', 'DROP TABLE insight_topics;'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, InsightTopic::count());
    }

    // --- Bases ----------------------------------------------------------------

    private function importBases(string $content, ?User $user = null): void
    {
        $user ??= $this->userWith('administrador');

        $preview = $this->actingAs($user)
            ->post(route('admin.knowledge.bases.import.preview'), ['file' => $this->csv($content)])
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.knowledge.bases.import.confirm'), ['stored' => $preview->viewData('stored')])
            ->assertRedirect(route('admin.knowledge.bases.index'));
    }

    public function test_a_base_is_created_from_the_file(): void
    {
        $this->importBases("base,identificador,proposito\nBase oficial,base-oficial,Material institucional\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $base = KnowledgeBase::where('slug', 'base-oficial')->firstOrFail();
        $this->assertSame('Base oficial', $base->name);
        $this->assertSame('Material institucional', $base->purpose);
    }

    /**
     * Ativar uma base e o ato que a torna alcançável pela busca. Uma planilha
     * não pode fazer isso.
     */
    public function test_an_imported_base_is_born_as_a_draft_even_if_the_file_says_active(): void
    {
        $this->importBases("base,identificador,situacao\nBase oficial,base-oficial,Ativa\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $this->assertSame(
            KnowledgeBaseStatus::Draft,
            KnowledgeBase::where('slug', 'base-oficial')->value('status')
        );
    }

    public function test_importing_never_changes_the_status_of_an_existing_base(): void
    {
        KnowledgeBase::factory()->create([
            'slug' => 'base-oficial',
            'name' => 'Nome antigo',
            'status' => KnowledgeBaseStatus::Active,
        ]);

        $this->importBases("base,identificador,situacao\nBase oficial,base-oficial,Rascunho\n"); // ortografia:ignorar - slug e cabeçalho de CSV

        $base = KnowledgeBase::where('slug', 'base-oficial')->firstOrFail();
        $this->assertSame('Base oficial', $base->name);
        $this->assertSame(KnowledgeBaseStatus::Active, $base->status, 'A situação não pode vir da planilha.');
    }

    /**
     * Aprovação e ato de uma pessoa neste sistema. Escrever isso por arquivo
     * seria forjar o registro.
     */
    public function test_importing_never_writes_approval(): void
    {
        $this->importBases("base,identificador,aprovada_por,aprovada_em\nBase oficial,base-oficial,Fulano,01/01/2026\n");

        $base = KnowledgeBase::where('slug', 'base-oficial')->firstOrFail();
        $this->assertNull($base->approved_by);
        $this->assertNull($base->approved_at);
    }

    // --- Permissão -------------------------------------------------------------

    public function test_reading_the_screen_is_not_enough_to_import(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.insight-topics.import'))
            ->assertForbidden();

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.knowledge.bases.import'))
            ->assertForbidden();
    }

    public function test_the_screens_offer_the_import_to_whoever_can_use_it(): void
    {
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->get(route('admin.insight-topics.index'))
            ->assertOk()->assertSee(route('admin.insight-topics.import'), false);

        $this->actingAs($admin)->get(route('admin.knowledge.bases.index'))
            ->assertOk()->assertSee(route('admin.knowledge.bases.import'), false);
    }
}
