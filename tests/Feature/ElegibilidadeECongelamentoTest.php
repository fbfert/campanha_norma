<?php

namespace Tests\Feature;

use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Models\Contact;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\Role;
use App\Models\User;
use App\Services\KeywordCampaigns\CampaignFreezer;
use App\Services\KeywordCampaigns\StudentEligibilityImporter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Elegibilidade de aluno e congelamento da lista.
 *
 * O furo que esta fase fecha: a campanha é entre alunos, mas a entrada não
 * verifica nada. Sem tratamento, a lista congelada conteria inelegíveis, o
 * sorteio apontaria um deles e seria preciso resortear — e sorteio refeito
 * porque o ganhador não servia é indistinguível, de fora, de sorteio refeito
 * porque o ganhador não agradou.
 */
class ElegibilidadeECongelamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function usuario(string $papel = 'administrador'): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }

    private function inscrito(KeywordCampaign $campanha, string $telefone): KeywordCampaignParticipation
    {
        return KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => Contact::factory()->create(['phone_normalized' => $telefone])->id,
        ]);
    }

    private function importer(): StudentEligibilityImporter
    {
        return app(StudentEligibilityImporter::class);
    }

    private function freezer(): CampaignFreezer
    {
        return app(CampaignFreezer::class);
    }

    public function test_importacao_marca_quem_casa_e_deixa_o_resto_esperando(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $aluno = $this->inscrito($campanha, '5549999990001');
        $desconhecido = $this->inscrito($campanha, '5549999990002');

        $resultado = $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());

        $this->assertSame(1, $resultado['marked']);
        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $aluno->fresh()->eligibility);
        $this->assertSame(KeywordParticipationEligibility::NaoVerificada, $desconhecido->fresh()->eligibility);
    }

    /**
     * A importação marca, não filtra. Nenhuma inscrição sai da lista por não
     * estar no arquivo, e nenhum contato novo é criado.
     */
    public function test_importacao_nao_cria_contato_nem_remove_inscricao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');

        $contatosAntes = Contact::count();

        // Um telefone que não está inscrito em campanha nenhuma.
        $this->importer()->marcar($campanha, ['5549988887777'], $this->usuario());

        $this->assertSame($contatosAntes, Contact::count());
        $this->assertSame(1, KeywordCampaignParticipation::count());
    }

    public function test_importacao_e_idempotente(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $aluno = $this->inscrito($campanha, '5549999990001');

        $primeira = $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());
        $segunda = $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());

        $this->assertSame(1, $primeira['marked']);
        $this->assertSame(0, $segunda['marked']);
        $this->assertSame(1, $segunda['already_marked']);
        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $aluno->fresh()->eligibility);
    }

    /**
     * Rodar de novo com um arquivo maior só acrescenta marcações.
     */
    public function test_arquivo_maior_acrescenta_sem_desfazer(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $primeiro = $this->inscrito($campanha, '5549999990001');
        $segundo = $this->inscrito($campanha, '5549999990002');

        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());
        $this->importer()->marcar($campanha, ['5549999990001', '5549999990002'], $this->usuario());

        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $primeiro->fresh()->eligibility);
        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $segundo->fresh()->eligibility);
    }

    /**
     * O arquivo é um retrato do portal num instante; a decisão humana veio de
     * olhar o caso. Deixar o arquivo sobrescrever faria a conferência se
     * desfazer a cada importação.
     */
    public function test_decisao_humana_de_nao_aluno_nao_e_sobrescrita(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '5549999990001');

        $participacao->update([
            'eligibility' => KeywordParticipationEligibility::NaoAluno,
            'reviewed_by' => $this->usuario()->id,
            'reviewed_at' => now(),
        ]);

        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());

        $this->assertSame(KeywordParticipationEligibility::NaoAluno, $participacao->fresh()->eligibility);
    }

    /**
     * O portal pode ter o número com nove dígitos e o WhatsApp entregar com
     * oito, ou o contrário.
     */
    public function test_telefone_com_e_sem_o_nono_digito_casa(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '554999990001');

        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());

        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $participacao->fresh()->eligibility);
    }

    public function test_telefone_formatado_no_arquivo_casa(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '5549999990001');

        $this->importer()->marcar($campanha, ['+55 (49) 99999-0001'], $this->usuario());

        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $participacao->fresh()->eligibility);
    }

    public function test_telefone_invalido_e_contado_e_nao_derruba_a_importacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '5549999990001');

        $resultado = $this->importer()->marcar($campanha, ['123', '', '5549999990001'], $this->usuario());

        $this->assertSame(2, $resultado['invalid_phones']);
        $this->assertSame(1, $resultado['marked']);
        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $participacao->fresh()->eligibility);
    }

    /**
     * O cabeçalho é identificador, lido pelo código: sem acento.
     */
    public function test_csv_com_cabecalho_de_telefone_e_lido(): void
    {
        // ortografia:ignorar — conteúdo de CSV lido pelo importador.
        $csv = "nome,telefone\nMaria,5549999990001\nJoao,5549999990002\n";

        $telefones = $this->importer()->lerTelefones(
            UploadedFile::fake()->createWithContent('alunos.csv', $csv),
        );

        $this->assertSame(['5549999990001', '5549999990002'], $telefones);
    }

    /**
     * Uma coluna só, sem cabeçalho: é o que sai de um "copiar e colar" do
     * portal, e recusá-lo obrigaria o operador a montar um CSV à mão.
     */
    public function test_arquivo_de_uma_coluna_sem_cabecalho_e_lido_como_lista(): void
    {
        $telefones = $this->importer()->lerTelefones(
            UploadedFile::fake()->createWithContent('alunos.csv', "5549999990001\n5549999990002\n"),
        );

        $this->assertSame(['5549999990001', '5549999990002'], $telefones);
    }

    public function test_congelamento_e_recusado_enquanto_houver_conferencia_pendente(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $this->inscrito($campanha, '5549999990002');

        try {
            $this->freezer()->congelar($campanha, $this->usuario());
            $this->fail('O congelamento deveria ter sido recusado.');
        } catch (ValidationException $excecao) {
            $this->assertStringContainsString('2 inscrições ainda não foram conferidas', $excecao->validator->errors()->first('campanha'));
        }

        $this->assertNull($campanha->fresh()->frozen_at);
    }

    public function test_congelamento_aceito_grava_hash_situacao_e_contagem(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $this->inscrito($campanha, '5549999990002');
        $this->importer()->marcar($campanha, ['5549999990001', '5549999990002'], $this->usuario());

        $congelada = $this->freezer()->congelar($campanha, $this->usuario());

        $this->assertNotNull($congelada->frozen_at);
        $this->assertSame(2, $congelada->frozen_list_count);
        $this->assertSame(KeywordCampaignStatus::Congelada, $congelada->status);
        $this->assertNotEmpty($congelada->frozen_list_hash);
    }

    /**
     * O hash é do conteúdo, não do instante: é o que permite a alguém de fora
     * conferir que a lista sorteada é a lista publicada.
     */
    public function test_a_mesma_lista_produz_o_mesmo_hash(): void
    {
        $ids = [7, 3, 11];

        $this->assertSame(
            $this->freezer()->hash($ids),
            $this->freezer()->hash(array_reverse($ids)),
            'A ordem em que os identificadores chegam não pode mudar o hash.',
        );

        $this->assertNotSame(
            $this->freezer()->hash($ids),
            $this->freezer()->hash([7, 3, 12]),
        );
    }

    /**
     * Quem foi marcado como não aluno fica de fora da lista congelada, mesmo
     * tendo a inscrição válida.
     */
    public function test_lista_congelada_so_tem_aluno_confirmado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $aluno = $this->inscrito($campanha, '5549999990001');
        $naoAluno = $this->inscrito($campanha, '5549999990002');

        $this->freezer()->conferirEmLote($campanha, [$aluno->id], KeywordParticipationEligibility::AlunoConfirmado, $this->usuario());
        $this->freezer()->conferirEmLote($campanha, [$naoAluno->id], KeywordParticipationEligibility::NaoAluno, $this->usuario());

        $congelada = $this->freezer()->congelar($campanha, $this->usuario());

        $this->assertSame(1, $congelada->frozen_list_count);
        $this->assertSame([$aluno->id], $this->freezer()->listaElegivel($campanha));
    }

    public function test_congelar_duas_vezes_e_recusado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());

        $this->freezer()->congelar($campanha, $this->usuario());

        $this->expectException(ValidationException::class);
        $this->freezer()->congelar($campanha->fresh(), $this->usuario());
    }

    public function test_congelamento_sem_ninguem_elegivel_e_recusado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '5549999990001');

        $this->freezer()->conferirEmLote($campanha, [$participacao->id], KeywordParticipationEligibility::NaoAluno, $this->usuario());

        try {
            $this->freezer()->congelar($campanha, $this->usuario());
            $this->fail('Deveria recusar sem ninguém elegível.');
        } catch (ValidationException $excecao) {
            $this->assertStringContainsString('Nenhuma inscrição elegível', $excecao->validator->errors()->first('campanha'));
        }
    }

    /**
     * Depois de congelada, invalidar não mexe no que foi congelado. O hash e a
     * contagem gravados continuam descrevendo a lista que foi fechada.
     */
    public function test_invalidacao_depois_do_congelamento_nao_altera_a_lista_congelada(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $primeiro = $this->inscrito($campanha, '5549999990001');
        $this->inscrito($campanha, '5549999990002');
        $this->importer()->marcar($campanha, ['5549999990001', '5549999990002'], $this->usuario());

        $congelada = $this->freezer()->congelar($campanha, $this->usuario());
        $hashOriginal = $congelada->frozen_list_hash;
        $totalOriginal = $congelada->frozen_list_count;

        $primeiro->update(['status' => KeywordParticipationStatus::Invalidada]);

        $depois = $campanha->fresh();

        $this->assertSame($hashOriginal, $depois->frozen_list_hash);
        $this->assertSame($totalOriginal, $depois->frozen_list_count);
    }

    /**
     * Campanha congelada não aceita mais ninguém.
     */
    public function test_campanha_congelada_nao_e_avaliavel(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());
        $this->freezer()->congelar($campanha, $this->usuario());

        $this->assertSame(0, KeywordCampaign::query()->avaliavel()->count());
        $this->assertSame(0, KeywordCampaign::query()->vigente()->count());
    }

    public function test_descongelar_exige_motivo_e_registra(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $this->importer()->marcar($campanha, ['5549999990001'], $this->usuario());
        $this->freezer()->congelar($campanha, $this->usuario());

        $this->freezer()->descongelar($campanha->fresh(), 'Ganhador inelegível descoberto depois.', $this->usuario());

        $this->assertNull($campanha->fresh()->frozen_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'keyword_campaign.list_unfrozen']);
    }

    public function test_tela_de_conferencia_exige_permissao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $usuario = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

        $this->actingAs($usuario)
            ->get(route('admin.keyword-campaigns.eligibility.index', $campanha))
            ->assertForbidden();
    }

    public function test_tela_de_conferencia_mostra_a_fila(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001')->update(['captured_name' => 'Maria Pendente']);

        $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.eligibility.index', $campanha))
            ->assertOk()
            ->assertSee('Maria Pendente');
    }

    /**
     * Marcar todos vale para a página que está na tela: a paginação esconde o
     * resto. A fila inteira é uma segunda escolha, explícita, e não um efeito
     * colateral deste botão.
     */
    public function test_tela_de_conferencia_oferece_marcar_todos(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');

        $resposta = $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.eligibility.index', $campanha))
            ->assertOk()
            ->assertSee('Marcar todos');

        $html = $resposta->getContent();

        $this->assertStringContainsString('selecao-pendente', $html); // ortografia:ignorar - classe de CSS, que é identificador e não leva acento
        $this->assertStringContainsString('querySelectorAll(\'.selecao-pendente\')', $html); // ortografia:ignorar - classe de CSS, que é identificador e não leva acento
    }

    /**
     * A fila inteira é conferida sem depender da paginação: quem marca a opção
     * alcança também quem ficou nas páginas seguintes, que é o caso de uma
     * divulgação grande em que todo mundo da fila é aluno.
     */
    public function test_conferencia_alcanca_a_fila_inteira_quando_pedida(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $this->inscrito($campanha, '554999999'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.eligibility.review', $campanha), [
                'fila_inteira' => '1',
                'eligibility' => 'aluno_confirmado',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $campanha->pendentesDeConferencia()->count());
        $this->assertSame(3, $campanha->participations()->elegivelParaSorteio()->count());
    }

    /**
     * A fila inteira desta campanha é a fila desta campanha. Sem filtro por
     * campanha, a opção viraria um botão de conferir o sistema inteiro.
     */
    public function test_fila_inteira_nao_alcanca_outra_campanha(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $outra = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');
        $deOutra = $this->inscrito($outra, '5549999990002');

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.eligibility.review', $campanha), [
                'fila_inteira' => '1',
                'eligibility' => 'aluno_confirmado',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(KeywordParticipationEligibility::NaoVerificada, $deOutra->fresh()->eligibility);
    }

    /**
     * Sem seleção e sem a fila inteira não há o que conferir, e a tela diz isso
     * em vez de responder "0 inscrições conferidas".
     */
    public function test_conferencia_sem_selecao_recusa(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.eligibility.review', $campanha), [
                'eligibility' => 'aluno_confirmado',
            ])
            ->assertSessionHasErrors('participations');

        $this->assertSame(1, $campanha->pendentesDeConferencia()->count());
    }

    public function test_conferencia_em_lote_pela_tela(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $primeiro = $this->inscrito($campanha, '5549999990001');
        $segundo = $this->inscrito($campanha, '5549999990002');

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.eligibility.review', $campanha), [
                'participations' => [$primeiro->id, $segundo->id],
                'eligibility' => 'aluno_confirmado',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $campanha->pendentesDeConferencia()->count());
    }

    /**
     * Não dá para conferir inscrição de outra campanha pela URL desta.
     */
    public function test_conferencia_em_lote_nao_alcanca_outra_campanha(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $outra = KeywordCampaign::factory()->create();
        $deOutra = $this->inscrito($outra, '5549999990002');

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.eligibility.review', $campanha), [
                'participations' => [$deOutra->id],
                'eligibility' => 'aluno_confirmado',
            ]);

        $this->assertSame(KeywordParticipationEligibility::NaoVerificada, $deOutra->fresh()->eligibility);
    }

    public function test_congelar_pela_tela_informa_quantas_faltam(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $this->inscrito($campanha, '5549999990001');

        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.eligibility.freeze', $campanha))
            ->assertSessionHasErrors('campanha');

        $this->assertNull($campanha->fresh()->frozen_at);
    }

    public function test_operador_nao_congela(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->actingAs($this->usuario('operador'))
            ->post(route('admin.keyword-campaigns.eligibility.freeze', $campanha))
            ->assertForbidden();
    }

    public function test_importacao_pela_tela_marca(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = $this->inscrito($campanha, '5549999990001');

        $this->actingAs($this->usuario('operador'))
            ->post(route('admin.keyword-campaigns.eligibility.import', $campanha), [
                // ortografia:ignorar — conteúdo de CSV lido pelo importador.
                'arquivo' => UploadedFile::fake()->createWithContent('alunos.csv', "telefone\n5549999990001\n"),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(KeywordParticipationEligibility::AlunoConfirmado, $participacao->fresh()->eligibility);
    }
}
