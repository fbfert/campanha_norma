<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Models\ConversationDailyMetric;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\Role;
use App\Models\User;
use App\Services\Analytics\DailyMetricBuilder;
use App\Services\Analytics\ParticipationMetricsService;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Subetapa 9E: fórmulas, permissões e materialização.
 *
 * O critério de aceitação mais importante da etapa não e um número certo, e um
 * número que não aparece: perfil de consulta não ve texto nem telefone, célula
 * pequena e suprimida e taxa sem denominador vira traço.
 */
class AnalyticsReportsTest extends TestCase
{
    use RefreshDatabase;

    private ConversationFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        // Relógio fixo no meio do dia. Os cenários criam estados com
        // `now()->subHours(2)` e reconstroem o dia de `now()`: rodando entre
        // meia-noite e duas da manha, as duas datas caem em dias diferentes e a
        // materialização não encontra nada. O defeito e do teste, não do
        // código, e so aparece para quem trabalha de madrugada.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $this->flow = ConversationFlow::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function state(ConversationFlowStage $stage, int $count = 1, array $extra = []): void
    {
        ConversationFlowState::factory()->count($count)->create([
            'conversation_flow_id' => $this->flow->id,
            'current_stage' => $stage,
            'started_at' => now()->subHours(2),
        ] + $extra);
    }

    // --- Fórmulas -------------------------------------------------------------

    public function test_participation_totals_count_each_stage_once(): void
    {
        $this->state(ConversationFlowStage::Completed, 4);
        $this->state(ConversationFlowStage::PermissionDenied, 3);
        $this->state(ConversationFlowStage::OptedOut, 2);
        $this->state(ConversationFlowStage::WaitingHuman, 1);

        $totals = app(ParticipationMetricsService::class)
            ->totals(now()->subDay(), now(), $this->flow->id);

        $this->assertSame(10, $totals['approached']);
        $this->assertSame(4, $totals['permission_granted']);
        $this->assertSame(4, $totals['answers_received']);
        $this->assertSame(4, $totals['completed']);
        $this->assertSame(3, $totals['permission_denied']);
        $this->assertSame(2, $totals['opted_out']);
        $this->assertSame(1, $totals['waiting_human']);
    }

    /**
     * A série diária derrubava a tela de análise inteira com erro 500.
     *
     * `current_stage` chega convertido em enum pelo cast do modelo, mesmo vindo
     * de um `selectRaw`, e a conversão direta para string lança. O `map` so roda
     * quando ha ao menos uma linha, então o defeito ficou invisível enquanto a
     * tabela estava vazia e apareceu no dia em que a primeira campanha real
     * gerou estados de fluxo.
     */
    public function test_a_serie_diaria_devolve_o_estagio_como_texto(): void
    {
        $this->state(ConversationFlowStage::Completed, 2);
        $this->state(ConversationFlowStage::WaitingAnswer, 1);

        $serie = app(ParticipationMetricsService::class)
            ->byDay(now()->subDay(), now(), $this->flow->id);

        $this->assertNotEmpty($serie);

        foreach ($serie as $linha) {
            $this->assertIsString($linha['stage']);
        }

        $this->assertEqualsCanonicalizing(
            ['completed', 'waiting_answer'],
            array_column($serie, 'stage')
        );
    }

    /**
     * Denominador da taxa de permissão e quem respondeu ao pedido. Incluir
     * quem ainda não respondeu como recusa produziria uma taxa que so cai com
     * o tempo, sem que nada tenha piorado.
     */
    public function test_the_permission_rate_excludes_who_has_not_answered_yet(): void
    {
        $this->state(ConversationFlowStage::Completed, 4);
        $this->state(ConversationFlowStage::PermissionDenied, 1);
        $this->state(ConversationFlowStage::WaitingPermission, 20);

        $rates = app(ParticipationMetricsService::class)
            ->overview(now()->subDay(), now(), $this->flow->id)['rates'];

        $this->assertSame(80.0, $rates['permission']['value']);
        $this->assertSame(4, $rates['permission']['numerator']);
        $this->assertSame(5, $rates['permission']['denominator']);
    }

    public function test_a_rate_without_a_denominator_is_null_not_zero(): void
    {
        $overview = app(ParticipationMetricsService::class)
            ->overview(now()->subDay(), now(), $this->flow->id);

        $this->assertNull($overview['rates']['permission']['value']);
        $this->assertSame(0, $overview['rates']['permission']['denominator']);
        $this->assertNull($overview['average_turns']);
    }

    /**
     * Conversa sem resposta nenhuma fica fora do denominador. Conta-la como
     * tempo zero puxaria a média para baixo; como tempo infinito, para cima.
     */
    public function test_conversations_without_a_reply_stay_out_of_the_average(): void
    {
        $answered = ConversationFlowState::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'started_at' => now()->subMinutes(10),
        ]);

        ConversationMessage::factory()->create([
            'conversation_id' => $answered->conversation_id,
            'direction' => ConversationMessageDirection::Incoming,
            'created_at' => now()->subMinutes(5),
        ]);

        ConversationFlowState::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'started_at' => now()->subMinutes(10),
        ]);

        $result = app(ParticipationMetricsService::class)
            ->firstReply(now()->subDay(), now(), $this->flow->id);

        $this->assertSame(1, $result['samples']);
        $this->assertEqualsWithDelta(300, $result['average'], 60);
    }

    // --- Materialização -------------------------------------------------------

    public function test_rebuilding_the_same_day_twice_does_not_duplicate(): void
    {
        $this->state(ConversationFlowStage::Completed, 3);

        $builder = app(DailyMetricBuilder::class);
        $builder->rebuildDay(now());
        $first = ConversationDailyMetric::query()->count();

        $builder->rebuildDay(now());

        $this->assertSame($first, ConversationDailyMetric::query()->count());
        $this->assertSame(3, (int) ConversationDailyMetric::query()
            ->where('flow_key', $this->flow->id)
            ->value('completed'));
    }

    public function test_rebuilding_replaces_values_after_a_correction(): void
    {
        $this->state(ConversationFlowStage::Completed, 3);
        app(DailyMetricBuilder::class)->rebuildDay(now());

        ConversationFlowState::query()->limit(1)->delete();
        app(DailyMetricBuilder::class)->rebuildDay(now());

        $this->assertSame(2, (int) ConversationDailyMetric::query()
            ->where('flow_key', $this->flow->id)
            ->value('completed'));
    }

    public function test_the_rebuild_command_runs_for_a_date(): void
    {
        $this->state(ConversationFlowStage::Completed, 2);

        $this->artisan('analytics:rebuild-metrics', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertDatabaseCount('conversation_daily_metrics', 2);
    }

    // --- Permissões -----------------------------------------------------------

    public static function screens(): array
    {
        return [
            ['admin.analytics.dashboard'],
            ['admin.analytics.topics'],
            ['admin.analytics.geography'],
            ['admin.analytics.demands'],
            ['admin.analytics.ai-quality'],
            ['admin.analytics.questions'],
        ];
    }

    #[DataProvider('screens')]
    public function test_a_query_profile_can_open_the_aggregate_screens(string $route): void
    {
        $this->actingAs($this->userWith('consulta'))->get(route($route))->assertOk();
    }

    public function test_a_query_profile_cannot_open_governance(): void
    {
        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.analytics.governance'))
            ->assertForbidden();
    }

    /**
     * A separação que a etapa existe para garantir: número sim, texto não.
     */
    public function test_a_query_profile_never_sees_message_content(): void
    {
        ConversationInsight::factory()->reviewed()->create([
            'conversation_flow_id' => $this->flow->id,
            'identified_problem' => 'SEGREDO DO CIDADÃO',
        ]);

        $this->actingAs($this->userWith('consulta'))
            ->get(route('admin.analytics.demands'))
            ->assertOk()
            ->assertDontSee('SEGREDO DO CIDADÃO')
            ->assertSee('exigem a permissão de ver conteúdo');
    }

    public function test_an_operator_sees_content_examples(): void
    {
        ConversationInsight::factory()->reviewed()->create([
            'conversation_flow_id' => $this->flow->id,
            'identified_problem' => 'Buraco na rua principal',
        ]);

        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.analytics.demands'))
            ->assertOk()
            ->assertSee('Buraco na rua principal');
    }

    public function test_costs_are_hidden_without_the_cost_permission(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->get(route('admin.analytics.ai-quality'))
            ->assertOk()
            ->assertSee('exigem a permissão de ver custos');
    }

    public function test_an_administrator_opens_governance(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.analytics.governance'))
            ->assertOk()
            ->assertSee('Interruptores');
    }

    // --- Supressão na tela ----------------------------------------------------

    public function test_a_small_locality_is_suppressed_on_screen(): void
    {
        app(SystemSettingService::class)->updateMany(['analytics.minimum_cell_size' => '5']);

        ConversationInsight::factory()->count(2)->withLocality('Vila Pequena')->create([
            'conversation_flow_id' => $this->flow->id,
        ]);

        $this->actingAs($this->userWith('administrador'))
            ->get(route('admin.analytics.geography'))
            ->assertOk()
            ->assertSee('Vila Pequena')
            ->assertSee('suprimido');
    }

    // --- Estado vazio ---------------------------------------------------------

    /**
     * Com tudo desligado e nada coletado, as telas precisam abrir e dizer que
     * não ha dado. Um relatório que quebra quando o sistema esta parado não
     * serve justamente no dia em que alguém quer saber por que esta parado.
     */
    #[DataProvider('screens')]
    public function test_every_screen_opens_with_no_data_at_all(string $route): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->get(route($route))
            ->assertOk();
    }

    // --- Filtros --------------------------------------------------------------

    public function test_the_period_filter_changes_the_result(): void
    {
        ConversationFlowState::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'current_stage' => ConversationFlowStage::Completed,
            'started_at' => now()->subDays(90),
        ]);

        $totals = app(ParticipationMetricsService::class)
            ->totals(now()->subDays(7), now(), $this->flow->id);

        $this->assertSame(0, $totals['approached']);

        $wider = app(ParticipationMetricsService::class)
            ->totals(now()->subDays(120), now(), $this->flow->id);

        $this->assertSame(1, $wider['approached']);
    }
}
