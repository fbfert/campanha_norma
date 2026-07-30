<?php

namespace Tests\Feature;

use App\Enums\ReportExportStatus;
use App\Models\AuditLog;
use App\Models\ConversationDailyMetric;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\User;
use App\Services\Analytics\DailyMetricBuilder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subetapa 9E: exportacao, anonimizacao e retencao.
 *
 * O criterio de aceitacao aqui e o arquivo. Nao basta a tela esconder: o que
 * sai do sistema em planilha e o que efetivamente circula por e-mail depois.
 */
class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    private ConversationFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        Storage::fake('local');
        $this->flow = ConversationFlow::factory()->create();
    }

    private function userWith(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function contents(ReportExport $export): string
    {
        return (string) file_get_contents(Storage::disk('local')->path((string) $export->file_path));
    }

    // --- Escopo agregado ------------------------------------------------------

    public function test_an_operator_exports_aggregates(): void
    {
        ConversationInsight::factory()->count(6)->create(['conversation_flow_id' => $this->flow->id]);

        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.analytics.export'), [
                'type' => 'topics', 'scope' => 'aggregate', 'format' => 'csv',
            ])
            ->assertRedirect();

        $export = ReportExport::query()->latest('id')->firstOrFail();

        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame('aggregate', $export->scope);
    }

    /**
     * Criterio de aceitacao literal: exportacao agregada nao contem
     * identificacao.
     */
    public function test_the_aggregated_file_carries_no_identification(): void
    {
        $insight = ConversationInsight::factory()->count(6)->create(['conversation_flow_id' => $this->flow->id])->first();
        $contact = $insight->contact;
        $contact->update(['name' => 'Joana Identificavel', 'phone' => '554999998888']);

        $this->actingAs($this->userWith('operador'))->post(route('admin.analytics.export'), [
            'type' => 'topics', 'scope' => 'aggregate', 'format' => 'csv',
        ]);

        $contents = $this->contents(ReportExport::query()->latest('id')->firstOrFail());

        $this->assertStringNotContainsString('Joana Identificavel', $contents);
        $this->assertStringNotContainsString('554999998888', $contents);
        $this->assertStringNotContainsString((string) $contact->id, $contents);
    }

    // --- Escopo detalhado -----------------------------------------------------

    public function test_a_detailed_export_is_refused_without_the_elevated_permission(): void
    {
        $this->actingAs($this->userWith('operador'))
            ->post(route('admin.analytics.export'), [
                'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
                'purpose' => 'Analisar demandas por regiao para a equipe.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_a_detailed_export_requires_a_written_purpose(): void
    {
        $this->actingAs($this->userWith('administrador'))
            ->post(route('admin.analytics.export'), [
                'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
            ])
            ->assertSessionHasErrors('purpose');
    }

    public function test_a_detailed_export_records_who_asked_and_why(): void
    {
        ConversationInsight::factory()->create(['conversation_flow_id' => $this->flow->id]);
        $admin = $this->userWith('administrador');

        $this->actingAs($admin)->post(route('admin.analytics.export'), [
            'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
            'purpose' => 'Relatorio interno de demandas para a equipe tecnica.',
        ])->assertRedirect();

        $export = ReportExport::query()->latest('id')->firstOrFail();

        $this->assertSame('detailed', $export->scope);
        $this->assertSame($admin->id, $export->user_id);
        $this->assertStringContainsString('equipe tecnica', (string) $export->purpose);
        $this->assertNotNull($export->expires_at);
        $this->assertTrue((bool) $export->anonymized);

        $this->assertDatabaseHas('audit_logs', ['action' => 'analytics.export_requested']);
    }

    public function test_the_detailed_file_is_anonymized(): void
    {
        $insight = ConversationInsight::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'identified_problem' => 'Falta de remedio no posto',
        ]);
        $insight->contact->update(['name' => 'Carlos Identificavel', 'phone' => '554991112222']);

        $this->actingAs($this->userWith('administrador'))->post(route('admin.analytics.export'), [
            'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
            'purpose' => 'Conferencia interna das demandas coletadas.',
        ]);

        $export = ReportExport::query()->latest('id')->firstOrFail();
        $contents = $this->contents($export);

        // O conteudo da demanda precisa estar la: e para isso que a exportacao existe.
        $this->assertStringContainsString('Falta de remedio no posto', $contents);
        // A identificacao nao.
        $this->assertStringNotContainsString('Carlos Identificavel', $contents);
        $this->assertStringNotContainsString('554991112222', $contents);
        $this->assertStringContainsString('pseudonimo', $contents);
    }

    /**
     * Duas exportacoes do mesmo periodo nao podem ser cruzadas para
     * reidentificar alguem. E o que o sal por exportacao garante.
     */
    public function test_two_detailed_exports_do_not_share_pseudonyms(): void
    {
        ConversationInsight::factory()->create(['conversation_flow_id' => $this->flow->id]);
        $admin = $this->userWith('administrador');

        $payload = [
            'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
            'purpose' => 'Conferencia interna das demandas coletadas.',
        ];

        $this->actingAs($admin)->post(route('admin.analytics.export'), $payload);
        $first = ReportExport::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.analytics.export'), $payload);
        $second = ReportExport::query()->latest('id')->firstOrFail();

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->pseudonym_salt, $second->pseudonym_salt);
        $this->assertNotSame(
            explode(',', explode("\n", $this->contents($first))[1] ?? '')[0] ?? 'a',
            explode(',', explode("\n", $this->contents($second))[1] ?? '')[0] ?? 'b',
        );
    }

    /**
     * A defesa que so aparece quando alguem abre o arquivo: uma resposta de
     * cidadao que comeca com `=` viraria formula na planilha.
     */
    public function test_a_formula_in_the_answer_is_neutralized_in_the_file(): void
    {
        ConversationInsight::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'identified_problem' => '=cmd|\' /C calc\'!A0',
        ]);

        $this->actingAs($this->userWith('administrador'))->post(route('admin.analytics.export'), [
            'type' => 'demands', 'scope' => 'detailed', 'format' => 'csv',
            'purpose' => 'Conferencia interna das demandas coletadas.',
        ]);

        $contents = $this->contents(ReportExport::query()->latest('id')->firstOrFail());

        $this->assertStringContainsString("'=cmd", $contents);
    }

    // --- Retencao e direitos --------------------------------------------------

    public function test_the_anonymization_command_refuses_to_run_without_a_scope(): void
    {
        $this->artisan('analytics:anonymize')->assertFailed();
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $insight = ConversationInsight::factory()->create(['conversation_flow_id' => $this->flow->id]);

        $this->artisan('analytics:anonymize', ['--contact' => $insight->contact_id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNotNull($insight->fresh()->identified_problem);
    }

    public function test_anonymizing_a_contact_clears_content_and_keeps_the_row(): void
    {
        $insight = ConversationInsight::factory()->create(['conversation_flow_id' => $this->flow->id]);

        ConversationMessage::factory()->create([
            'conversation_id' => $insight->conversation_id,
            'contact_id' => $insight->contact_id,
            'body' => 'Mensagem original do cidadao',
        ]);

        $this->artisan('analytics:anonymize', ['--contact' => $insight->contact_id])->assertSuccessful();

        $fresh = $insight->fresh();

        $this->assertNotNull($fresh, 'A linha do insight precisa sobreviver: apagar quebraria a integridade referencial.');
        $this->assertNull($fresh->identified_problem);
        $this->assertNull($fresh->summary);
        $this->assertSame(0, ConversationMessage::query()->whereNotNull('body')->where('contact_id', $insight->contact_id)->count());
    }

    public function test_the_anonymization_is_recorded(): void
    {
        $insight = ConversationInsight::factory()->create(['conversation_flow_id' => $this->flow->id]);

        $this->artisan('analytics:anonymize', ['--contact' => $insight->contact_id])->assertSuccessful();

        $entry = AuditLog::query()->where('action', 'analytics.content_anonymized')->firstOrFail();

        $this->assertStringContainsString('insights', (string) json_encode($entry->new_values));
    }

    /**
     * Depois de anonimizar, os agregados daquele dia precisam refletir o novo
     * estado. Relatorio que continua contando o que foi apagado e uma copia do
     * dado que se pediu para remover.
     */
    public function test_aggregates_are_recomputed_after_anonymization(): void
    {
        $state = ConversationFlowState::factory()->create([
            'conversation_flow_id' => $this->flow->id,
            'started_at' => now()->subHour(),
        ]);

        $insight = ConversationInsight::factory()->create([
            'conversation_id' => $state->conversation_id,
            'conversation_flow_id' => $this->flow->id,
        ]);

        app(DailyMetricBuilder::class)->rebuildDay(now());
        $before = ConversationDailyMetric::query()->where('flow_key', $this->flow->id)->value('rebuilt_at');

        $this->travel(2)->seconds();
        $this->artisan('analytics:anonymize', ['--contact' => $insight->contact_id])->assertSuccessful();

        $after = ConversationDailyMetric::query()->where('flow_key', $this->flow->id)->value('rebuilt_at');

        $this->assertNotSame((string) $before, (string) $after);
    }
}
