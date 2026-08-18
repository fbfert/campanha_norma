<?php

namespace Tests\Feature;

use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Enums\PermissionSlug;
use App\Models\Contact;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A fundação de dados da campanha por palavra-chave.
 *
 * Nada aqui tem comportamento de runtime: o que estes testes cobram é o que só
 * o schema pode garantir. A unicidade da inscrição é a mais importante — ela
 * vive no banco justamente porque duas mensagens quase simultâneas da mesma
 * pessoa perdem qualquer corrida decidida na aplicação.
 */
class FundacaoDeCampanhaPorPalavraChaveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A chave única precisa recusar de verdade.
     *
     * Verificar antes do insert não basta: o teste dispara o segundo insert sem
     * consultar nada, que é o que dois workers fazem quando chegam juntos.
     */
    public function test_segunda_participacao_da_mesma_pessoa_na_mesma_campanha_e_recusada(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $contato = Contact::factory()->create();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
        ]);

        $this->expectException(QueryException::class);

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
        ]);
    }

    /**
     * A mesma pessoa em duas campanhas é o caso normal: duas divulgações
     * diferentes, duas inscrições.
     */
    public function test_a_mesma_pessoa_pode_estar_em_duas_campanhas(): void
    {
        $contato = Contact::factory()->create();

        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => KeywordCampaign::factory()->create()->id,
            'contact_id' => $contato->id,
        ]);
        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => KeywordCampaign::factory()->create()->id,
            'contact_id' => $contato->id,
        ]);

        $this->assertSame(2, KeywordCampaignParticipation::where('contact_id', $contato->id)->count());
    }

    /**
     * A prova de origem é obrigatória: sem ela a participação não é
     * reconstruível, e o comando de reprocessamento perde o sentido.
     */
    public function test_participacao_sem_mensagem_de_origem_e_recusada(): void
    {
        $this->expectException(QueryException::class);

        KeywordCampaignParticipation::create([
            'keyword_campaign_id' => KeywordCampaign::factory()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'conversation_message_id' => null,
            'matched_keyword' => 'sorteio',
        ]);
    }

    /**
     * As bordas da vigência são inclusivas.
     *
     * Quem escreve no segundo exato em que a campanha abre está dentro. O
     * contrário produziria a reclamação impossível de responder de quem mandou
     * "na hora certa" e não entrou.
     */
    public function test_escopo_de_vigencia_nas_bordas(): void
    {
        $campanha = KeywordCampaign::factory()->create([
            'starts_at' => Carbon::parse('2026-09-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-09-30 20:00:00'),
        ]);

        $casos = [
            '2026-08-31 23:59:59' => false,
            '2026-09-01 08:00:00' => true,
            '2026-09-15 12:00:00' => true,
            '2026-09-30 20:00:00' => true,
            '2026-09-30 20:00:01' => false,
        ];

        foreach ($casos as $agora => $esperado) {
            Carbon::setTestNow($agora);

            $this->assertSame(
                $esperado,
                KeywordCampaign::query()->vigente()->whereKey($campanha->id)->exists(),
                "Vigência errada em {$agora}.",
            );
            $this->assertSame($esperado, $campanha->fresh()->estaVigente(), "estaVigente() errado em {$agora}.");
        }

        Carbon::setTestNow();
    }

    /**
     * Campanha em rascunho não recebe ninguém, mesmo dentro do período. É o que
     * permite montar a campanha inteira antes de ligar.
     */
    public function test_rascunho_nao_e_vigente_mesmo_dentro_do_periodo(): void
    {
        KeywordCampaign::factory()->rascunho()->create();

        $this->assertSame(0, KeywordCampaign::query()->vigente()->count());
    }

    /**
     * O limite conta inscrição válida. Invalidada não ocupa vaga de ninguém.
     */
    public function test_limite_de_participantes_ignora_invalidada(): void
    {
        $campanha = KeywordCampaign::factory()->create(['participant_limit' => 2]);

        KeywordCampaignParticipation::factory()->count(2)->create(['keyword_campaign_id' => $campanha->id]);
        $this->assertTrue($campanha->fresh()->atingiuLimite());

        KeywordCampaignParticipation::first()->update(['status' => KeywordParticipationStatus::Invalidada]);
        $this->assertFalse($campanha->fresh()->atingiuLimite());
    }

    public function test_campanha_sem_limite_nunca_atinge_o_limite(): void
    {
        $campanha = KeywordCampaign::factory()->create(['participant_limit' => null]);

        KeywordCampaignParticipation::factory()->count(3)->create(['keyword_campaign_id' => $campanha->id]);

        $this->assertFalse($campanha->fresh()->atingiuLimite());
    }

    /**
     * A fila de conferência é o que trava o congelamento. Quem já foi
     * invalidado não precisa ser conferido para sair de novo.
     */
    public function test_pendentes_de_conferencia_ignora_invalidada_e_ja_conferida(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        KeywordCampaignParticipation::factory()->count(2)->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->alunoConfirmado()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->invalidada()->create(['keyword_campaign_id' => $campanha->id]);

        $this->assertSame(2, $campanha->pendentesDeConferencia()->count());
        $this->assertSame(2, KeywordCampaignParticipation::query()->pendentesDeConferencia()->count());
    }

    public function test_elegivel_para_sorteio_exige_aluno_confirmado_e_situacao_valida(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        KeywordCampaignParticipation::factory()->alunoConfirmado()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->alunoConfirmado()->semNome()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->alunoConfirmado()->invalidada()->create(['keyword_campaign_id' => $campanha->id]);

        $this->assertSame(2, KeywordCampaignParticipation::query()->elegivelParaSorteio()->count());
    }

    /**
     * Participação sem nome é válida. É o caso de quem não tem nome de perfil
     * no WhatsApp, e bloquear por isso seria excluir participante por causa de
     * um campo de cadastro.
     */
    public function test_participacao_sem_nome_conta_como_valida(): void
    {
        $participacao = KeywordCampaignParticipation::factory()->semNome()->create();

        $this->assertTrue($participacao->status->contaComoValida());
        $this->assertNull($participacao->displayName());
    }

    /**
     * A correção humana vence, mas o original continua gravado: é o que
     * permite responder de onde veio o nome errado.
     */
    public function test_nome_revisado_vence_o_capturado_sem_apagar_o_original(): void
    {
        $participacao = KeywordCampaignParticipation::factory()->create([
            'captured_name' => 'mari ✨',
            'reviewed_name' => 'Mariana Souza',
        ]);

        $this->assertSame('Mariana Souza', $participacao->displayName());
        $this->assertSame('mari ✨', $participacao->captured_name);
    }

    public function test_relacoes_da_participacao_resolvem(): void
    {
        $participacao = KeywordCampaignParticipation::factory()->create();

        $this->assertInstanceOf(KeywordCampaign::class, $participacao->campaign);
        $this->assertInstanceOf(Contact::class, $participacao->contact);
        $this->assertInstanceOf(ConversationMessage::class, $participacao->message);
    }

    public function test_situacao_e_elegibilidade_voltam_como_enum(): void
    {
        $participacao = KeywordCampaignParticipation::factory()->create();

        $this->assertInstanceOf(KeywordParticipationEligibility::class, $participacao->eligibility);
        $this->assertInstanceOf(KeywordCampaignStatus::class, $participacao->campaign->status);
    }

    /**
     * O seeder precisa distribuir as permissões novas, senão a tela existe e
     * ninguém abre.
     */
    public function test_papeis_recebem_as_permissoes_da_etapa(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $consulta = Role::where('slug', 'consulta')->firstOrFail();
        $operador = Role::where('slug', 'operador')->firstOrFail();
        $administrador = Role::where('slug', 'administrador')->firstOrFail();

        $temPermissao = fn (Role $papel, PermissionSlug $slug): bool => $papel
            ->permissions()
            ->where('slug', $slug->value)
            ->exists();

        $this->assertTrue($temPermissao($consulta, PermissionSlug::KeywordCampaignsView));
        $this->assertTrue($temPermissao($consulta, PermissionSlug::KeywordParticipationsView));
        $this->assertFalse($temPermissao($consulta, PermissionSlug::KeywordParticipationsInvalidate));

        $this->assertTrue($temPermissao($operador, PermissionSlug::KeywordParticipationsInvalidate));
        $this->assertTrue($temPermissao($operador, PermissionSlug::KeywordParticipationsExport));

        // As três ações que decidem quem ganha o prêmio ficam com o
        // administrador: congelar, sortear e ver código de cupom.
        $this->assertFalse($temPermissao($operador, PermissionSlug::KeywordCampaignsManage));
        $this->assertFalse($temPermissao($operador, PermissionSlug::KeywordDrawsExecute));
        $this->assertFalse($temPermissao($operador, PermissionSlug::KeywordCouponsManage));

        $this->assertTrue($temPermissao($administrador, PermissionSlug::KeywordDrawsExecute));
        $this->assertTrue($temPermissao($administrador, PermissionSlug::KeywordCouponsManage));
    }
}
