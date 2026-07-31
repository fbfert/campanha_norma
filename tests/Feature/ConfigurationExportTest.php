<?php

namespace Tests\Feature;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\Contact;
use App\Models\InsightTopic;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exportação das telas de configuração: taxonomia de temas e bases.
 *
 * O que se verifica aqui não e o formato do arquivo, e sim três decisões: a
 * exportação sai completa, não vaza conteúdo de documento, e nenhuma célula
 * pode virar fórmula ao ser aberta numa planilha.
 */
class ConfigurationExportTest extends TestCase
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

    private function download(string $route, string $roleSlug = 'administrador', array $query = []): string
    {
        $response = $this->actingAs($this->userWith($roleSlug))->get(route($route, $query))->assertOk();

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    // --- Taxonomia -----------------------------------------------------------

    public function test_the_taxonomy_screen_offers_the_export(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.insight-topics.index'))
            ->assertOk()
            ->assertSee(route('admin.insight-topics.export'), false);
    }

    public function test_the_taxonomy_export_brings_the_topics(): void
    {
        InsightTopic::factory()->create(['name' => 'Saúde', 'slug' => 'saude']);
        InsightTopic::factory()->create(['name' => 'Estradas', 'slug' => 'estradas']);

        $content = $this->download('admin.insight-topics.export');

        $this->assertStringContainsString('Saúde', $content);
        $this->assertStringContainsString('Estradas', $content);
        $this->assertStringContainsString('identificador', $content);
    }

    /**
     * A tela mostra trinta temas por vez. A exportação não pode herdar esse
     * corte: uma taxonomia partida em páginas não serve para conferir nem
     * comparar, que e justamente o motivo de exportar.
     */
    public function test_the_taxonomy_export_is_not_limited_to_one_page(): void
    {
        InsightTopic::factory()->count(35)->create();

        $content = $this->download('admin.insight-topics.export');

        // Cabeçalho mais 35 linhas.
        $this->assertSame(36, count(array_filter(explode("\n", trim($content)))));
    }

    public function test_a_query_profile_can_export_the_taxonomy_it_already_sees(): void
    {
        InsightTopic::factory()->create(['name' => 'Educação']);

        $this->assertStringContainsString('Educação', $this->download('admin.insight-topics.export', 'consulta'));
    }

    // --- Bases ---------------------------------------------------------------

    public function test_the_knowledge_screen_offers_the_export(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.knowledge.bases.index'))
            ->assertOk()
            ->assertSee(route('admin.knowledge.bases.export'), false);
    }

    public function test_the_knowledge_export_brings_the_bases_and_their_counts(): void
    {
        $base = KnowledgeBase::factory()->create(['name' => 'Base oficial', 'status' => KnowledgeBaseStatus::Active]);
        KnowledgeDocument::factory()->count(2)->create([
            'knowledge_base_id' => $base->id,
            'status' => KnowledgeDocumentStatus::Approved,
        ]);
        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $base->id,
            'status' => KnowledgeDocumentStatus::Draft,
        ]);

        $content = $this->download('admin.knowledge.bases.export');

        $this->assertStringContainsString('Base oficial', $content);
        // Três documentos, dois aprovados.
        $this->assertMatchesRegularExpression('/,3,2,/', $content);
    }

    /**
     * Este e o limite que importa nesta tela. Uma planilha com o texto dos
     * documentos seria uma cópia do material oficial fora do controle de
     * aprovação.
     */
    public function test_the_knowledge_export_never_leaks_document_content(): void
    {
        $base = KnowledgeBase::factory()->create(['name' => 'Base oficial']);
        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $base->id,
            'title' => 'Documento reservado',
            'extracted_text' => 'SENTINELA-9271-RESERVADA',
        ]);

        $content = $this->download('admin.knowledge.bases.export');

        $this->assertStringNotContainsString('SENTINELA-9271-RESERVADA', $content);
        $this->assertStringNotContainsString('Documento reservado', $content);
    }

    // --- Injeção de fórmula ---------------------------------------------------

    /**
     * Nome de tema e digitado por quem administra, mas a defesa não pode
     * depender de confiança: e a mesma planilha, aberta pela mesma equipe.
     */
    public function test_a_topic_name_cannot_become_a_formula(): void
    {
        InsightTopic::factory()->create(['name' => '=HYPERLINK("http://x","clique")']);

        $content = $this->download('admin.insight-topics.export');

        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_a_base_name_cannot_become_a_formula(): void
    {
        KnowledgeBase::factory()->create(['name' => '@SUM(1+1)']);

        $content = $this->download('admin.knowledge.bases.export');

        $this->assertStringContainsString("'@SUM", $content);
    }

    /**
     * A exportação de contatos e anterior a estas telas e nascera sem a
     * protecão. Nome de contato vem de planilha alheia ou do que a própria
     * pessoa informou — e o caso mais exposto do sistema, não o menos.
     */
    public function test_a_contact_name_cannot_become_a_formula(): void
    {
        Contact::factory()->create(['name' => '=cmd|calc']);

        $content = $this->download('admin.contacts.export');

        $this->assertStringContainsString("'=cmd", $content);
        $this->assertStringNotContainsString("\n=cmd", $content);
    }

    // --- Rotas ---------------------------------------------------------------

    /**
     * `export` precisa ser rota própria, e não ser lida como identificador de
     * base pela rota `/knowledge/bases/{base}`, que vem logo depois.
     */
    public function test_the_export_route_is_not_swallowed_by_the_show_route(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get('/admin/knowledge/bases/export')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=bases-de-conhecimento.csv');
    }
}
