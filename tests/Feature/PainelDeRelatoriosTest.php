<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\InsightTopic;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Analytics\PositioningGapService;
use App\Services\Analytics\ResponseAgendaService;
use App\Services\Analytics\TopicByLocalityService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Subetapa 9F: painel agregado e pauta nominal.
 *
 * O critério de aceitação mais importante desta subetapa é uma separação: o
 * painel suprime grupo pequeno e o dossiê não suprime nada, e é justamente por
 * isso que eles têm permissões diferentes e módulos diferentes. Quem "consertar"
 * a falta de supressão no dossiê quebra o teste que diz que ela não existe.
 *
 * O segundo critério é uma ausência: nenhuma execução de modelo é criada ao ler
 * a pauta. É esse teste que impede a 9F de passar a gerar texto sem ninguém
 * perceber.
 */
class PainelDeRelatoriosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- Permissão

    public function test_sem_a_permissao_da_pauta_nao_abre_nada_do_modulo(): void
    {
        $insight = $this->insight();
        $usuario = $this->comPermissoes(['analytics.view_identification', 'analytics.view_content']);

        $this->actingAs($usuario)->get(route('admin.pauta.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('admin.pauta.show', $insight))->assertForbidden();
        $this->actingAs($usuario)->get(route('admin.pauta.caderno'))->assertForbidden();
    }

    /**
     * A permissão da pauta sozinha não basta. Ela daria acesso a nome, cidade e
     * ao texto da pessoa — que é exatamente o que as outras duas separam.
     */
    public function test_a_permissao_da_pauta_sem_a_de_identificacao_nao_abre(): void
    {
        $insight = $this->insight();
        $usuario = $this->comPermissoes(['response_agenda.view', 'analytics.view_content']);

        $this->actingAs($usuario)->get(route('admin.pauta.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('admin.pauta.show', $insight))->assertForbidden();
    }

    public function test_a_permissao_da_pauta_sem_a_de_conteudo_nao_abre(): void
    {
        $usuario = $this->comPermissoes(['response_agenda.view', 'analytics.view_identification']);

        $this->actingAs($usuario)->get(route('admin.pauta.index'))->assertForbidden();
    }

    public function test_as_tres_permissoes_juntas_abrem(): void
    {
        $insight = $this->insight();
        $usuario = $this->comPermissoes([
            'response_agenda.view',
            'analytics.view_identification',
            'analytics.view_content',
        ]);

        $this->actingAs($usuario)->get(route('admin.pauta.index'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.pauta.show', $insight))->assertOk();
        $this->actingAs($usuario)->get(route('admin.pauta.caderno'))->assertOk();
    }

    /**
     * O perfil de consulta lê o agregado e não alcança o nominal. É a mesma
     * separação que a 9E desenhou, estendida às telas novas.
     */
    public function test_consulta_abre_o_agregado_e_nao_abre_a_pauta(): void
    {
        $consulta = $this->comPapel('consulta');

        $this->actingAs($consulta)->get(route('admin.analytics.cidade-tema'))->assertOk();
        $this->actingAs($consulta)->get(route('admin.analytics.posicionamento'))->assertOk();
        $this->actingAs($consulta)->get(route('admin.pauta.index'))->assertForbidden();
    }

    // --------------------------------------------------------------- Supressão

    public function test_celula_abaixo_do_minimo_aparece_suprimida_e_continua_na_tabela(): void
    {
        $tema = InsightTopic::factory()->create(['name' => 'Saúde']);

        // Duas menções numa cidade, com o mínimo em cinco.
        $this->insight(tema: $tema, localidade: 'Chapecó');
        $this->insight(tema: $tema, localidade: 'Chapecó');

        $matriz = app(TopicByLocalityService::class)->matrix($this->de(), $this->ate());

        $this->assertCount(1, $matriz['rows'], 'A linha suprimida continua na tabela.');

        $celula = $matriz['rows'][0]['cells']['Saúde'];

        $this->assertTrue($celula['suppressed']);
        $this->assertNull($celula['total']);
        // O total da linha continua visível: ele é a mesma agregação simples que
        // a tela de geografia da 9E já mostra.
        $this->assertSame(2, $matriz['rows'][0]['total']);
    }

    public function test_zero_nunca_e_suprimido(): void
    {
        $saude = InsightTopic::factory()->create(['name' => 'Saúde']);
        $creche = InsightTopic::factory()->create(['name' => 'Creche']);

        for ($i = 0; $i < 5; $i++) {
            $this->insight(tema: $saude, localidade: 'Chapecó');
        }

        // Creche existe na tabela por causa de outra cidade, e em Chapecó vale zero.
        $this->insight(tema: $creche, localidade: 'Xanxerê');

        $matriz = app(TopicByLocalityService::class)->matrix($this->de(), $this->ate());
        $chapeco = collect($matriz['rows'])->firstWhere('locality', 'Chapecó');

        $this->assertSame(0, $chapeco['cells']['Creche']['total']);
        $this->assertFalse($chapeco['cells']['Creche']['suppressed']);
    }

    public function test_sem_localidade_declarada_e_contado_a_parte(): void
    {
        $this->insight(localidade: 'Chapecó');
        $this->insight();

        $matriz = app(TopicByLocalityService::class)->matrix($this->de(), $this->ate());

        $this->assertSame(1, $matriz['without_locality']);
        $this->assertSame(1, $matriz['total']);
    }

    /**
     * **O dossiê não sofre supressão nenhuma, e isto é deliberado.**
     *
     * A supressão protege contra identificar alguém a partir de um agregado
     * pequeno. Aqui identificar é o ponto: alguém vai responder àquela pessoa.
     * É por isso que a pauta exige três permissões e mora em módulo separado.
     *
     * Este teste existe para ninguém "consertar" a ausência depois.
     */
    public function test_o_dossie_individual_nao_sofre_supressao(): void
    {
        $insight = $this->insight(localidade: 'Chapecó');

        $dossie = app(ResponseAgendaService::class)->dossier($insight);

        $this->assertSame('O posto de saúde abre tarde demais.', $dossie['sentence']);
        $this->assertNotNull($dossie['name']);
        $this->assertNotNull($dossie['city']);
    }

    /**
     * O caderno impresso traz o telefone ao lado do nome.
     *
     * Ele vai inteiro, e não mascarado como na caixa de entrada: quem abre o
     * caderno vai responder àquela pessoa, e número mascarado não disca. A
     * exposição já está paga pelas três permissões que o módulo exige.
     */
    public function test_o_caderno_mostra_o_telefone_ao_lado_do_nome(): void
    {
        $insight = $this->insight();
        $insight->contact->update(['phone_normalized' => '5549991613378']);

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.pauta.caderno'))
            ->assertOk()
            ->assertSee('5549991613378')
            ->assertDontSee('*****');
    }

    /**
     * Sem telefone cadastrado o caderno diz isso, em vez de deixar o espaço em
     * branco: quem lê precisa saber que não vai conseguir responder por ali.
     */
    public function test_o_caderno_diz_quando_nao_ha_telefone(): void
    {
        $insight = $this->insight();
        $insight->contact->update(['phone_normalized' => null, 'phone' => null]);

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.pauta.caderno'))
            ->assertOk()
            ->assertSee('sem telefone cadastrado');
    }

    // -------------------------------------------------------------- Determinismo

    public function test_gerar_o_mesmo_dossie_duas_vezes_produz_o_mesmo_texto(): void
    {
        $insight = $this->insight(localidade: 'Chapecó');
        $pauta = app(ResponseAgendaService::class);

        $primeiro = $pauta->dossier($insight->fresh());
        $segundo = $pauta->dossier($insight->fresh());

        unset($primeiro['insight'], $segundo['insight']);

        $this->assertEquals($primeiro, $segundo);
    }

    /**
     * Nenhuma execução de modelo ao ler.
     *
     * Este é o teste que garante que a 9F não passou a gerar texto por IA sem
     * ninguém perceber: o dossiê é composição de campos já gravados, e ler não
     * pode custar uma chamada nem produzir uma frase que ninguém escreveu.
     */
    public function test_ler_a_pauta_nao_cria_nenhuma_execucao_de_modelo(): void
    {
        $insight = $this->insight();
        $usuario = $this->comPapel('administrador');

        $antes = AiRun::count();

        $this->actingAs($usuario)->get(route('admin.pauta.index'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.pauta.show', $insight))->assertOk();
        $this->actingAs($usuario)->get(route('admin.pauta.caderno'))->assertOk();

        $this->assertSame($antes, AiRun::count());
    }

    // ------------------------------------------------------------ Posicionamento

    public function test_tema_com_mencoes_e_sem_documento_aprovado_e_buraco(): void
    {
        $tema = InsightTopic::factory()->create(['name' => 'Creche']);
        $this->insight(tema: $tema);

        $buraco = $this->buracoDe($tema);

        $this->assertTrue($buraco['is_gap']);
        $this->assertSame(0, $buraco['approved_documents']);
    }

    /**
     * Indexar não aprova. A separação já existe na 9D e significa que alguém
     * decidiu que aquilo pode ser dito a uma pessoa.
     */
    public function test_documento_indexado_e_nao_aprovado_continua_sendo_buraco(): void
    {
        $tema = InsightTopic::factory()->create(['name' => 'Creche']);
        $this->insight(tema: $tema);

        KnowledgeDocument::factory()->ready()->create([
            'knowledge_base_id' => KnowledgeBase::factory()->active()->create()->id,
            'insight_topic_id' => $tema->id,
        ]);

        $this->assertTrue($this->buracoDe($tema)['is_gap']);
    }

    public function test_documento_aprovado_em_base_inativa_continua_sendo_buraco(): void
    {
        $tema = InsightTopic::factory()->create(['name' => 'Creche']);
        $this->insight(tema: $tema);

        KnowledgeDocument::factory()->approved()->create([
            'knowledge_base_id' => KnowledgeBase::factory()->inactive()->create()->id,
            'insight_topic_id' => $tema->id,
        ]);

        $this->assertTrue($this->buracoDe($tema)['is_gap']);
    }

    public function test_documento_aprovado_em_base_ativa_fecha_o_buraco(): void
    {
        $tema = InsightTopic::factory()->create(['name' => 'Creche']);
        $this->insight(tema: $tema);

        KnowledgeDocument::factory()->approved()->create([
            'knowledge_base_id' => KnowledgeBase::factory()->active()->create()->id,
            'insight_topic_id' => $tema->id,
        ]);

        $buraco = $this->buracoDe($tema);

        $this->assertFalse($buraco['is_gap']);
        $this->assertSame(1, $buraco['approved_documents']);
    }

    // ---------------------------------------------------------------- Regressão

    /**
     * As sete telas da 9E continuam abrindo. A 9F acrescenta colunas anuláveis e
     * telas novas; nenhuma delas deveria mexer no que já existia.
     */
    public function test_as_telas_da_9e_continuam_abrindo(): void
    {
        $this->insight();
        $usuario = $this->comPapel('administrador');

        foreach ([
            'admin.analytics.dashboard',
            'admin.analytics.topics',
            'admin.analytics.geography',
            'admin.analytics.demands',
            'admin.analytics.ai-quality',
            'admin.analytics.questions',
            'admin.analytics.governance',
        ] as $rota) {
            $this->actingAs($usuario)->get(route($rota))->assertOk();
        }
    }

    /**
     * Nenhuma rota do módulo nominal alcança o provedor de WhatsApp.
     *
     * A 9F é somente leitura, e restrição declarada em prosa é convenção — e
     * convenção não impede nada. Esta varredura lê o código dos controllers do
     * módulo e falha se o contrato de envio aparecer lá.
     */
    public function test_nenhuma_rota_da_pauta_alcanca_o_provedor_de_whatsapp(): void
    {
        $encontrados = [];

        foreach (glob(app_path('Http/Controllers/Admin/ResponseAgenda/*.php')) ?: [] as $arquivo) {
            $codigo = $this->codigoSemComentarios($arquivo);

            foreach (['WhatsAppProvider', 'sendMessage', 'dispatch(', 'Queue::'] as $proibido) {
                if (str_contains($codigo, $proibido)) {
                    $encontrados[] = basename($arquivo).' cita '.$proibido;
                }
            }
        }

        $this->assertSame([], $encontrados, 'A pauta de resposta não envia, não agenda e não enfileira.');
    }

    /**
     * A coluna nova de tema não chega à recuperação da 9D.
     *
     * `knowledge_documents.insight_topic_id` existe para a pauta de
     * posicionamento e para mais nada. Se um dia alguém a usar para escolher o
     * que recuperar, a opinião coletada passa a decidir a resposta oficial —
     * exatamente o que a trava estrutural daquela subetapa existe para impedir.
     *
     * O teste de isolamento da 9D já barra as tabelas de conversa e de insight
     * no recuperador. Este acrescenta a coluna, que é nova e ainda não estava
     * coberta por aquela lista.
     */
    public function test_a_recuperacao_da_9d_nao_conhece_o_tema_do_documento(): void
    {
        foreach ([
            'Services/Knowledge/LocalKnowledgeRetriever.php',
            'Data/Knowledge/RetrievalQuery.php',
        ] as $arquivo) {
            $this->assertStringNotContainsString(
                'insight_topic_id',
                $this->codigoSemComentarios(app_path($arquivo)),
                "A recuperação não pode escolher documento pelo tema que a população citou: {$arquivo}.",
            );
        }
    }

    /**
     * O único verbo de escrita do módulo grava a marca e a auditoria.
     */
    public function test_marcar_como_respondida_grava_a_marca_e_a_auditoria(): void
    {
        $insight = $this->insight();
        $usuario = $this->comPapel('administrador');

        $this->actingAs($usuario)
            ->post(route('admin.pauta.responder', $insight))
            ->assertRedirect();

        $insight->refresh();

        $this->assertNotNull($insight->answered_at);
        $this->assertSame($usuario->id, $insight->answered_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'response_agenda.marked_answered']);
    }

    public function test_gerar_o_caderno_fica_na_auditoria(): void
    {
        $this->insight();

        $this->actingAs($this->comPapel('administrador'))
            ->get(route('admin.pauta.caderno'))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'response_agenda.notebook_generated']);
    }

    // ------------------------------------------------------------------ Apoio

    private function codigoSemComentarios(string $caminho): string
    {
        $codigo = '';

        foreach (token_get_all((string) file_get_contents($caminho)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($token) ? $token[1] : $token;
        }

        return $codigo;
    }

    /** @return array<string, mixed> */
    private function buracoDe(InsightTopic $tema): array
    {
        $buracos = app(PositioningGapService::class)->gaps($this->de(), $this->ate());

        return collect($buracos)->firstWhere('topic_id', $tema->id);
    }

    private function de(): Carbon
    {
        return Carbon::parse('2026-08-01')->startOfDay();
    }

    private function ate(): Carbon
    {
        return Carbon::parse('2026-08-31')->endOfDay();
    }

    /** @param array<int, string> $permissoes */
    private function comPermissoes(array $permissoes): User
    {
        $papel = Role::create([
            'slug' => 'papel-'.uniqid(),
            'name' => 'Papel de teste',
            'description' => 'Papel montado por teste.',
        ]);

        $papel->permissions()->attach(
            Permission::whereIn('slug', $permissoes)->pluck('id'),
        );

        $usuario = User::factory()->create();
        $usuario->roles()->attach($papel);

        return $usuario;
    }

    private function comPapel(string $slug): User
    {
        $usuario = User::factory()->create();
        $usuario->roles()->attach(Role::where('slug', $slug)->firstOrFail());

        return $usuario;
    }

    private function insight(?InsightTopic $tema = null, ?string $localidade = null): ConversationInsight
    {
        $contato = Contact::factory()->create(['first_name' => 'Marta', 'city' => 'Chapecó']);
        $conversa = Conversation::factory()->create(['contact_id' => $contato->id]);
        $mensagem = ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'body' => 'O posto de saúde abre tarde demais.',
        ]);

        return ConversationInsight::factory()->create([
            'conversation_id' => $conversa->id,
            'contact_id' => $contato->id,
            'source_message_id' => $mensagem->id,
            'insight_topic_id' => ($tema ?? InsightTopic::factory()->create())->id,
            'locality_text' => $localidade,
            'locality_normalized' => $localidade,
        ]);
    }
}
