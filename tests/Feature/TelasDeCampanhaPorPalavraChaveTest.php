<?php

namespace Tests\Feature;

use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Enums\ReportExportStatus;
use App\Models\Contact;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * As telas de campanha e de participantes.
 *
 * Duas ações aqui mudam quem pode ganhar o prêmio — invalidar e corrigir nome —
 * e por isso as duas exigem permissão própria, gravam autor e não apagam nada.
 */
class TelasDeCampanhaPorPalavraChaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function usuario(string $papel): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }

    public function test_lista_de_campanhas_mostra_inscritos_e_pendentes(): void
    {
        $campanha = KeywordCampaign::factory()->create(['name' => 'Sorteio de cursos']);

        KeywordCampaignParticipation::factory()->count(2)->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->alunoConfirmado()->create(['keyword_campaign_id' => $campanha->id]);

        $resposta = $this->actingAs($this->usuario('consulta'))
            ->get(route('admin.keyword-campaigns.index'));

        $resposta->assertOk();
        $resposta->assertSee('Sorteio de cursos');
        $resposta->assertSee('sorteio');
    }

    public function test_quem_nao_tem_permissao_nao_ve_a_lista(): void
    {
        $usuario = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

        $this->actingAs($usuario)
            ->get(route('admin.keyword-campaigns.index'))
            ->assertForbidden();
    }

    /**
     * Consulta acompanha, não mexe. Criar campanha é do administrador.
     */
    public function test_consulta_nao_cria_campanha(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('admin.keyword-campaigns.create'))
            ->assertForbidden();
    }

    public function test_administrador_cria_campanha_com_as_palavras_normalizadas(): void
    {
        $resposta = $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.store'), $this->dadosDoFormulario([
                'keywords' => "SORTEIO\nCurso Grátis\nsorteio",
            ]));

        $resposta->assertRedirect(route('admin.keyword-campaigns.index'));

        $campanha = KeywordCampaign::firstOrFail();

        // Normalizadas na gravação, sem duplicata: o caminho quente não pode
        // pagar por normalizar a lista de novo a cada mensagem recebida.
        $this->assertSame(['sorteio', 'curso gratis'], $campanha->keywords);
    }

    /**
     * O aviso não bloqueia: quem monta a campanha pode ter uma razão que o
     * sistema não conhece. O que não pode é descobrir pela enxurrada.
     */
    public function test_palavra_curta_demais_gera_aviso_sem_impedir_a_gravacao(): void
    {
        $resposta = $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.store'), $this->dadosDoFormulario(['keywords' => 'sim']));

        $resposta->assertRedirect(route('admin.keyword-campaigns.index'));
        $resposta->assertSessionHas('avisos');

        $avisos = session('avisos');
        $this->assertStringContainsString('curta demais', $avisos[0]);
        $this->assertSame(1, KeywordCampaign::count());
    }

    public function test_palavra_comum_demais_gera_aviso(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.store'), $this->dadosDoFormulario(['keywords' => 'quero']));

        $this->assertStringContainsString('aparece em qualquer conversa', session('avisos')[0]);
    }

    /**
     * Só pontuação sobrevive à validação de `required` mas não à normalização,
     * e deixaria uma campanha ativa que nunca casa com nada.
     */
    public function test_lista_que_some_na_normalizacao_e_recusada(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.store'), $this->dadosDoFormulario(['keywords' => '!!! ??? 🎉']))
            ->assertSessionHasErrors('keywords');

        $this->assertSame(0, KeywordCampaign::count());
    }

    public function test_fim_antes_do_inicio_e_recusado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.store'), $this->dadosDoFormulario([
                'starts_at' => '2026-09-10T10:00',
                'ends_at' => '2026-09-01T10:00',
            ]))
            ->assertSessionHasErrors('ends_at');
    }

    /**
     * Mudar a palavra, a vigência ou o texto depois de a lista ter sido fechada
     * muda o que a campanha era quando as pessoas se inscreveram.
     */
    public function test_campanha_congelada_nao_pode_ser_editada(): void
    {
        $campanha = KeywordCampaign::factory()->create([
            'frozen_at' => now(),
            'status' => KeywordCampaignStatus::Congelada,
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->put(route('admin.keyword-campaigns.update', $campanha), $this->dadosDoFormulario(['name' => 'Outro nome']))
            ->assertSessionHasErrors('status');

        $this->assertNotSame('Outro nome', $campanha->fresh()->name);
    }

    public function test_lista_de_participantes_filtra_por_situacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'Maria Válida',
        ]);
        KeywordCampaignParticipation::factory()->invalidada()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'João Invalidado',
        ]);

        $resposta = $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.participations.index', $campanha).'?'.http_build_query(['situacao' => 'invalidada']));

        $resposta->assertOk();
        $resposta->assertSee('João Invalidado');
        $resposta->assertDontSee('Maria Válida');
    }

    public function test_lista_de_participantes_filtra_quem_esta_sem_nome(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'Maria com nome',
        ]);
        KeywordCampaignParticipation::factory()->semNome()->create(['keyword_campaign_id' => $campanha->id]);

        $resposta = $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.participations.index', $campanha).'?'.http_build_query(['sem_nome' => 1]));

        $resposta->assertOk();
        $resposta->assertDontSee('Maria com nome');
    }

    public function test_busca_por_telefone_encontra_a_participacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $contato = Contact::factory()->create(['phone_normalized' => '5549988887777']);

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'captured_name' => 'Encontrada pelo número',
        ]);
        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'Outra pessoa',
        ]);

        $resposta = $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.participations.index', $campanha).'?'.http_build_query(['busca' => '(49) 98888-7777']));

        $resposta->assertOk();
        $resposta->assertSee('Encontrada pelo número');
        $resposta->assertDontSee('Outra pessoa');
    }

    /**
     * Invalidação sem motivo é indistinguível, na auditoria, de alguém tirando
     * da lista quem não queria que ganhasse.
     */
    public function test_invalidacao_sem_motivo_e_recusada(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.participations.invalidate', [$campanha, $participacao]), [])
            ->assertSessionHasErrors('invalidation_reason');

        $this->assertSame(KeywordParticipationStatus::Valida, $participacao->fresh()->status);
    }

    public function test_invalidacao_guarda_o_motivo_e_nao_apaga_a_participacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);
        $operador = $this->usuario('operador');

        $this->actingAs($operador)
            ->put(route('admin.keyword-campaigns.participations.invalidate', [$campanha, $participacao]), [
                'invalidation_reason' => 'Escreveu dizendo que não queria participar.',
            ])
            ->assertSessionHasNoErrors();

        $participacao->refresh();

        $this->assertSame(KeywordParticipationStatus::Invalidada, $participacao->status);
        $this->assertSame('Escreveu dizendo que não queria participar.', $participacao->invalidation_reason);
        $this->assertSame($operador->id, $participacao->invalidated_by);
        $this->assertNotNull($participacao->invalidated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'keyword_campaign.participation_invalidated']);
    }

    /**
     * Não dá para invalidar uma inscrição pela URL de outra campanha.
     */
    public function test_participacao_de_outra_campanha_nao_e_alcancada(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $outra = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $outra->id]);

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.participations.invalidate', [$campanha, $participacao]), [
                'invalidation_reason' => 'Tentativa por outra campanha.',
            ])
            ->assertNotFound();
    }

    public function test_consulta_nao_invalida_participacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);

        $this->actingAs($this->usuario('consulta'))
            ->put(route('admin.keyword-campaigns.participations.invalidate', [$campanha, $participacao]), [
                'invalidation_reason' => 'Não deveria conseguir.',
            ])
            ->assertForbidden();
    }

    /**
     * O original continua gravado: é o que permite responder de onde veio o
     * nome errado, e o que impede que uma correção equivocada vire a única
     * versão.
     */
    public function test_correcao_de_nome_preserva_o_que_o_provedor_informou(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->semNome()->create([
            'keyword_campaign_id' => $campanha->id,
        ]);

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.participations.name', [$campanha, $participacao]), [
                'reviewed_name' => 'Mariana Souza',
            ])
            ->assertSessionHasNoErrors();

        $participacao->refresh();

        $this->assertSame('Mariana Souza', $participacao->reviewed_name);
        $this->assertNull($participacao->captured_name);
        $this->assertNotNull($participacao->name_reviewed_by);

        // Corrigido o nome, a situação "sem nome" deixa de descrever a
        // participação: ela existia só para dizer que faltava exatamente isto.
        $this->assertSame(KeywordParticipationStatus::Valida, $participacao->status);
    }

    public function test_correcao_de_nome_nao_muda_situacao_de_participacao_invalidada(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $participacao = KeywordCampaignParticipation::factory()->invalidada()->create([
            'keyword_campaign_id' => $campanha->id,
        ]);

        $this->actingAs($this->usuario('operador'))
            ->put(route('admin.keyword-campaigns.participations.name', [$campanha, $participacao]), [
                'reviewed_name' => 'Nome corrigido',
            ]);

        $this->assertSame(KeywordParticipationStatus::Invalidada, $participacao->fresh()->status);
    }

    public function test_exportacao_exige_a_permissao_propria(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('admin.keyword-campaigns.participations.export', $campanha))
            ->assertForbidden();
    }

    /**
     * A exportação reaproveita o disco privado, a expiração e a máscara de
     * telefone da Etapa 9E.
     */
    public function test_exportacao_gera_arquivo_privado_com_telefone_mascarado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $contato = Contact::factory()->create(['phone_normalized' => '5549988887777']);

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'captured_name' => 'Maria da Silva',
        ]);

        $this->actingAs($this->usuario('operador'))
            ->post(route('admin.keyword-campaigns.participations.export', $campanha))
            ->assertRedirect();

        $export = ReportExport::firstOrFail();

        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame(1, $export->total_rows);
        $this->assertNotNull($export->expires_at);

        $conteudo = Storage::disk('local')->get($export->file_path);

        $this->assertStringContainsString('Maria da Silva', $conteudo);
        $this->assertStringNotContainsString('5549988887777', $conteudo);
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function dadosDoFormulario(array $sobrescritas = []): array
    {
        return array_merge([
            'name' => 'Sorteio de cursos',
            'description' => null,
            'status' => KeywordCampaignStatus::Rascunho->value,
            'keywords' => 'sorteio',
            'starts_at' => '2026-09-01T08:00',
            'ends_at' => '2026-09-30T20:00',
            'participant_limit' => null,
            'hourly_alert_threshold' => null,
            'confirmation_text' => 'Inscrição confirmada!',
            'already_enrolled_text' => 'Você já está inscrito.',
            'out_of_window_text' => null,
        ], $sobrescritas);
    }

    public function test_elegibilidade_aparece_no_filtro(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        KeywordCampaignParticipation::factory()->alunoConfirmado()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'Aluna confirmada',
        ]);
        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'captured_name' => 'Ainda não conferida',
        ]);

        $resposta = $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.participations.index', $campanha)
                .'?'.http_build_query(['elegibilidade' => KeywordParticipationEligibility::AlunoConfirmado->value]));

        $resposta->assertOk();
        $resposta->assertSee('Aluna confirmada');
        $resposta->assertDontSee('Ainda não conferida');
    }
}
