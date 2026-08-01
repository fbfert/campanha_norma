<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\DocumentIngestionService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O mesmo arquivo em bases diferentes.
 *
 * Enviar o mesmo PDF para duas bases criava dois documentos sem que nada
 * avisasse — a checagem de duplicata só olhava dentro da própria base. Quem
 * enviou achou que o sistema tinha duplicado sozinho, porque da tela de uma base
 * não há como ver a outra.
 *
 * Repetir dentro da mesma base continua barrado. Entre bases diferentes passa a
 * avisar, e não a impedir: uma base institucional e uma de campanha podem
 * legitimamente querer cada uma a sua cópia.
 */
class DocumentoRepetidoEntreBasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    private function servico(): DocumentIngestionService
    {
        return app(DocumentIngestionService::class);
    }

    /**
     * A tela de uma base lista só os documentos dela.
     *
     * Isto sempre funcionou, e mesmo assim foi relatado duas vezes: a coluna
     * "Tipo" ao lado do título fazia "Biografia aprovada" parecer o nome de um
     * segundo documento. O teste existe para que a resposta a essa dúvida seja
     * uma execução, e não uma leitura de código.
     */
    public function test_a_tela_da_base_lista_somente_os_documentos_dela(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $primeira = KnowledgeBase::factory()->create(['name' => 'Sobre a Norma']);
        $segunda = KnowledgeBase::factory()->create(['name' => 'Apresentação institucional']);

        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $primeira->id,
            'title' => 'Documento da primeira',
        ]);
        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $segunda->id,
            'title' => 'Documento da segunda',
        ]);

        $user = \App\Models\User::factory()->create();
        $user->roles()->attach(\App\Models\Role::where('slug', 'administrador')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.knowledge.bases.show', $primeira))
            ->assertOk()
            ->assertSee('Documento da primeira')
            ->assertDontSee('Documento da segunda')
            ->assertSee('Documentos desta base (1)');
    }

    public function test_o_mesmo_arquivo_em_outra_base_e_apontado(): void
    {
        $primeira = KnowledgeBase::factory()->create(['name' => 'Sobre a Norma']);
        $segunda = KnowledgeBase::factory()->create(['name' => 'Apresentação institucional']);

        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $primeira->id,
            'content_hash' => 'mesmo-conteudo',
        ]);

        $novo = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $segunda->id,
            'content_hash' => 'mesmo-conteudo',
        ]);

        $repetidos = $this->servico()->duplicatesInOtherBases($novo);

        $this->assertCount(1, $repetidos);
        $this->assertSame('Sobre a Norma', $repetidos->first()->base->name);
    }

    /**
     * O documento não pode acusar a si mesmo, nem aos irmãos da própria base —
     * dentro da base a duplicata já e barrada no envio.
     */
    public function test_o_proprio_documento_nao_conta_como_repetido(): void
    {
        $base = KnowledgeBase::factory()->create();

        $documento = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $base->id,
            'content_hash' => 'unico',
        ]);

        $this->assertCount(0, $this->servico()->duplicatesInOtherBases($documento));
    }

    public function test_arquivos_diferentes_nao_sao_apontados(): void
    {
        $primeira = KnowledgeBase::factory()->create();
        $segunda = KnowledgeBase::factory()->create();

        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $primeira->id,
            'content_hash' => 'um',
        ]);

        $outro = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $segunda->id,
            'content_hash' => 'outro',
        ]);

        $this->assertCount(0, $this->servico()->duplicatesInOtherBases($outro));
    }

    /**
     * Hash vazio não pode casar com todos os outros que também estão vazios.
     *
     * A coluna e NOT NULL, então o caso e improvável — mas comparar vazio com
     * vazio apontaria toda a base como repetida, e o custo da guarda e uma
     * linha.
     */
    public function test_hash_vazio_nao_aponta_nada(): void
    {
        $primeira = KnowledgeBase::factory()->create();
        $segunda = KnowledgeBase::factory()->create();

        KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $primeira->id,
            'content_hash' => '',
        ]);

        $outro = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $segunda->id,
            'content_hash' => '',
        ]);

        $this->assertCount(0, $this->servico()->duplicatesInOtherBases($outro));
    }
}
