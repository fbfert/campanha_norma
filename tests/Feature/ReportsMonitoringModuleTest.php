<?php

namespace Tests\Feature;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Enums\MessageSendAttemptStatus;
use App\Enums\ReportExportStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use App\Models\MessageTemplate;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Models\WorkerHeartbeat;
use App\Services\Reports\ErrorClassificationService;
use App\Services\Reports\ReportMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsMonitoringModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
        Storage::fake('local');
    }

    public function test_historico_exige_permissao_e_exibe_snapshots(): void
    {
        $admin = $this->userWithRole('administrador');
        $reader = $this->userWithRole('consulta');
        $recipient = $this->sentRecipient();

        $this->actingAs($reader)->get(route('admin.histories.messages.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.histories.messages.show', $recipient))
            ->assertOk()
            ->assertSee('Conteudo protegido');

        $this->actingAs($admin)->get(route('admin.histories.messages.show', $recipient))
            ->assertOk()
            ->assertSee('Mensagem enviada')
            ->assertSee('Mariana Snapshot');
    }

    public function test_filtros_de_historico_por_lote_status_erro_e_busca(): void
    {
        $admin = $this->userWithRole('administrador');
        $recipient = $this->sentRecipient(['error_code' => null]);
        $failed = $this->sentRecipient(['processing_status' => MessageRecipientProcessingStatus::FailedPermanent, 'error_code' => 'INVALID_PHONE', 'error_message' => 'Telefone invalido']);

        $this->actingAs($admin)->get(route('admin.histories.messages.index', ['message_batch_id' => $recipient->message_batch_id, 'status' => 'sent']))
            ->assertOk()
            ->assertSee('Mariana Snapshot');

        $this->actingAs($admin)->get(route('admin.histories.messages.index', ['error_code' => 'INVALID_PHONE']))
            ->assertOk()
            ->assertSee('INVALID_PHONE');

        $this->actingAs($admin)->get(route('admin.histories.messages.index', ['q' => $failed->contact_phone_snapshot]))
            ->assertOk()
            ->assertSee('INVALID_PHONE');
    }

    public function test_historico_por_contato_preserva_snapshot(): void
    {
        $admin = $this->userWithRole('administrador');
        $recipient = $this->sentRecipient();
        $recipient->contact->update(['name' => 'Nome Atualizado']);

        $this->actingAs($admin)->get(route('admin.contacts.message-history', $recipient->contact))
            ->assertOk()
            ->assertSee('Mariana Snapshot')
            ->assertSee('Enviadas');
    }

    public function test_relatorios_calculam_totais_e_divisao_por_zero(): void
    {
        $metrics = app(ReportMetricsService::class);
        $empty = $metrics->messageTotals(now()->subDay(), now());
        $this->assertNull($empty['success_rate']);

        $this->sentRecipient();
        $totals = $metrics->messageTotals(now()->subDay(), now()->addDay());

        $this->assertSame(1, $totals['sent']);
        $this->assertSame(100.0, $totals['success_rate']);
    }

    public function test_relatorios_e_classificacao_de_erros(): void
    {
        $admin = $this->userWithRole('administrador');
        $this->sentRecipient(['processing_status' => MessageRecipientProcessingStatus::FailedTemporary, 'error_code' => 'SERVICE_UNAVAILABLE']);

        $this->assertSame('temporary', app(ErrorClassificationService::class)->classify('SERVICE_UNAVAILABLE')->value);
        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('Indicadores');
        $this->actingAs($admin)->get(route('admin.reports.errors'))->assertOk()->assertSee('SERVICE_UNAVAILABLE');
        $this->actingAs($admin)->get(route('admin.reports.contacts'))->assertOk()->assertSee('used in batches');
    }

    public function test_exportacao_csv_xlsx_download_expiracao_e_auditoria(): void
    {
        $admin = $this->userWithRole('administrador');
        $this->sentRecipient();

        $this->actingAs($admin)->post(route('admin.reports.export'), [
            'report_type' => 'messages',
            'format' => 'csv',
            'columns' => ['lote', 'nome', 'status'],
        ])->assertRedirect();

        $export = ReportExport::firstOrFail();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        Storage::disk('local')->assertExists($export->file_path);
        $this->assertStringStartsWith('report-exports/', $export->file_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.export_requested']);

        $this->actingAs($admin)->get(route('admin.report-exports.download', $export))->assertOk();

        $export->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->get(route('admin.report-exports.download', $export))->assertNotFound();

        $this->actingAs($admin)->post(route('admin.reports.export'), ['report_type' => 'messages', 'format' => 'xlsx'])->assertRedirect();
        $this->assertDatabaseHas('report_exports', ['format' => 'xlsx', 'status' => 'completed']);
    }

    public function test_monitoramento_e_manutencao(): void
    {
        Http::fake(['127.0.0.1:3100/api/health' => Http::response(['success' => true, 'data' => ['status' => 'healthy']], 200)]);
        $admin = $this->userWithRole('administrador');
        WorkerHeartbeat::factory()->create();
        SchedulerHeartbeat::factory()->create();
        $batch = MessageBatch::factory()->create(['status' => MessageBatchStatus::Completed, 'total_sent' => 99]);
        MessageBatchRecipient::factory()->create(['message_batch_id' => $batch->id, 'processing_status' => MessageRecipientProcessingStatus::Sent]);

        $this->actingAs($admin)->get(route('admin.monitoring.index'))->assertOk()->assertSee('Saude operacional');
        $this->actingAs($admin)->post(route('admin.maintenance.sync-counters'), ['confirm' => '1'])->assertRedirect();

        $this->assertSame(1, $batch->fresh()->total_sent);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.sync_counters']);
    }

    public function test_consulta_nao_executa_manutencao(): void
    {
        $reader = $this->userWithRole('consulta');

        $this->actingAs($reader)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.maintenance.index'))->assertForbidden();
    }

    private function sentRecipient(array $overrides = []): MessageBatchRecipient
    {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $template = MessageTemplate::factory()->create(['created_by' => $user->id]);
        $contact = Contact::factory()->create(['name' => 'Mariana Atual']);
        $batch = MessageBatch::factory()->create([
            'message_template_id' => $template->id,
            'message_template_version' => 1,
            'status' => MessageBatchStatus::Completed,
            'created_by' => $user->id,
            'processing_started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'eligible_total' => 1,
            'total_sent' => 1,
        ]);

        $recipient = MessageBatchRecipient::factory()->create(array_merge([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'contact_name_snapshot' => 'Mariana Snapshot',
            'contact_email_snapshot' => 'mariana@example.com',
            'contact_city_snapshot' => 'Brasilia',
            'contact_state_snapshot' => 'DF',
            'contact_phone_snapshot' => '5549999999999',
            'rendered_message' => 'Mensagem enviada',
            'processing_status' => MessageRecipientProcessingStatus::Sent,
            'attempts' => 1,
            'sent_at' => now(),
            'external_message_id' => 'wamid.test',
        ], $overrides));

        MessageSendAttempt::factory()->create([
            'message_batch_recipient_id' => $recipient->id,
            'attempt_number' => 1,
            'request_id' => $recipient->request_id ?: fake()->uuid(),
            'status' => MessageSendAttemptStatus::Sent,
            'external_message_id' => 'wamid.test',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        return $recipient->refresh();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }
}
