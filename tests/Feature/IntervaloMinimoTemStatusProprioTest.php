<?php

namespace Tests\Feature;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus as Status;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\SendingSetting;
use App\Services\MessageProcessing\RecipientProcessingService;
use App\Services\MessageProcessing\SendingRateLimiterService;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Intervalo mínimo tem status e mensagem próprios.
 *
 * São quatro travas de ritmo, e o intervalo mínimo se disfarçava de limite por
 * minuto: um lote com folga de 4 por minuto parava depois da primeira mensagem
 * dizendo "aguardando limite por minuto". Quem lia ia conferir o limite por
 * minuto, encontrava folga, e não tinha como descobrir sozinho que o problema
 * estava em outro campo.
 */
class IntervaloMinimoTemStatusProprioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        Queue::fake();
        Cache::flush();
    }

    public function test_o_intervalo_minimo_nao_se_disfarca_de_limite_por_minuto(): void
    {
        $settings = SendingSetting::factory()->create([
            'max_per_minute' => 4,
            'max_per_hour' => 100,
            'max_per_day' => 100,
            'minimum_interval_seconds' => 60,
        ]);

        $agora = CarbonImmutable::parse('2026-07-31 23:56:00', $settings->timezone);
        $limiter = app(SendingRateLimiterService::class);

        $limiter->consume($settings, $agora);

        $resultado = $limiter->check($settings, $agora->addSeconds(10));

        $this->assertFalse($resultado['allowed']);
        $this->assertSame(
            'waiting_minimum_interval',
            $resultado['blocked_by'],
            'Com 1 de 4 mensagens no minuto, quem segura e o intervalo, não o limite por minuto.'
        );
    }

    public function test_o_limite_por_minuto_continua_reportando_a_si_mesmo(): void
    {
        $settings = SendingSetting::factory()->create([
            'max_per_minute' => 2,
            'max_per_hour' => 100,
            'max_per_day' => 100,
            'minimum_interval_seconds' => 0,
        ]);

        $agora = CarbonImmutable::parse('2026-07-31 23:56:00', $settings->timezone);
        $limiter = app(SendingRateLimiterService::class);

        $limiter->consume($settings, $agora);
        $limiter->consume($settings, $agora);

        $this->assertSame('waiting_minute_limit', $limiter->check($settings, $agora)['blocked_by']);
    }

    public function test_o_destinatario_recebe_o_status_e_a_mensagem_que_indicam_o_campo(): void
    {
        $settings = SendingSetting::query()->first() ?? SendingSetting::factory()->create();
        $settings->update([
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'max_per_minute' => 4,
            'minimum_interval_seconds' => 90,
        ]);

        app(SendingRateLimiterService::class)->consume($settings->fresh());

        $recipient = $this->destinatario();

        app(RecipientProcessingService::class)->process($recipient, $recipient->batch->processing_version);

        $recipient->refresh();

        $this->assertSame(Status::WaitingMinimumInterval, $recipient->processing_status);
        $this->assertSame('WAITING_MINIMUM_INTERVAL', $recipient->error_code);
        $this->assertStringContainsString('90s', $recipient->error_message);
    }

    /**
     * Status de espera que não entra na consulta do despachante nunca mais e
     * escolhido: o destinatário fica parado para sempre, sem erro visível.
     */
    public function test_todo_status_de_espera_e_reconhecido_como_ativo(): void
    {
        foreach ([Status::WaitingSchedule, Status::WaitingMinuteLimit, Status::WaitingMinimumInterval, Status::WaitingHourLimit, Status::WaitingDayLimit] as $status) {
            $this->assertTrue($status->isActive(), "O status {$status->value} precisa contar como ativo.");
        }
    }

    /**
     * Toda trava de ritmo tem rótulo próprio. Dois rótulos iguais devolvem o
     * problema que este trabalho corrigiu.
     */
    public function test_cada_trava_tem_rotulo_distinto(): void
    {
        $rotulos = array_map(
            fn (Status $status): string => $status->label(),
            [Status::WaitingMinuteLimit, Status::WaitingMinimumInterval, Status::WaitingHourLimit, Status::WaitingDayLimit],
        );

        $this->assertSame($rotulos, array_unique($rotulos));
    }

    private function destinatario(): MessageBatchRecipient
    {
        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
        ]);

        $batch = MessageBatch::factory()->create([
            'status' => MessageBatchStatus::Processing,
            'eligible_total' => 1,
            'selection_total' => 1,
            'prepared_at' => now(),
            'processing_version' => 1,
        ]);

        $contact = Contact::factory()->create();

        return MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'eligibility_status' => 'eligible',
            'processing_status' => 'pending',
            'contact_phone_snapshot' => $contact->phone,
            'rendered_message' => 'Oi.',
            'request_id' => 'teste-intervalo',
        ]);
    }
}
