<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Enums\PermissionResponseClassification;
use App\Jobs\EvaluateConversationFlowJob;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationAutomation\ConversationAutomationGuard;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\ConversationAutomation\ConversationQuestionSelector;
use App\Services\SystemSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationAutomationModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);
        Config::set('queue.default', 'database');

        $this->enableAutomation();
    }

    private function enableAutomation(bool $autoSend = true): void
    {
        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => $autoSend ? '1' : '0',
            // Janela aberta para o teste não depender da hora da execução.
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
        ]);
    }

    private function makeScenario(int $questions = 3): array
    {
        $contact = Contact::factory()->create([
            'status' => ContactStatus::Active,
            'do_not_contact' => false,
            'phone_normalized' => '5549999990001',
        ]);

        $flow = ConversationFlow::factory()->create();
        ConversationFlowQuestion::factory()->count($questions)->create(['conversation_flow_id' => $flow->id]);

        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        $state = ConversationFlowState::factory()->create([
            'conversation_id' => $conversation->id,
            'conversation_flow_id' => $flow->id,
            'current_stage' => ConversationFlowStage::WaitingPermission,
        ]);

        return [$contact, $flow, $conversation, $state];
    }

    private function incoming(Conversation $conversation, string $body): ConversationMessage
    {
        return ConversationMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'status' => ConversationMessageStatus::Received,
            'message_type' => 'text',
            'body' => $body,
        ]);
    }

    // ---------------------------------------------------------------
    // Cenário 1
    // ---------------------------------------------------------------

    public function test_cenario_1_permissao_concedida_seleciona_uma_pergunta_e_cria_uma_mensagem(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        $message = $this->incoming($conversation, 'Sim, pode perguntar');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->current_stage);
        $this->assertNotNull($state->selected_question_id);
        $this->assertNotNull($state->selected_question_snapshot);

        $this->assertSame(1, $state->questionUsages()->count());

        $outgoing = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('origin', ConversationMessageOrigin::Automation)
            ->get();

        $this->assertCount(1, $outgoing);
        $this->assertSame(ConversationMessageStatus::Pending, $outgoing->first()->status);
        $this->assertStringContainsString($state->selected_question_snapshot, $outgoing->first()->body);

        Queue::assertPushed(SendAutomatedConversationReplyJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Cenário 2
    // ---------------------------------------------------------------

    public function test_cenario_2_execucao_repetida_nao_cria_segunda_pergunta_nem_segunda_mensagem(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        $message = $this->incoming($conversation, 'sim');

        $service = app(ConversationFlowService::class);
        $service->handleIncomingMessage($message);
        $service->handleIncomingMessage($message);

        $state->refresh();

        $this->assertSame(1, $state->questionUsages()->count());
        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversation->id)
            ->where('origin', ConversationMessageOrigin::Automation)->count());

        Queue::assertPushed(SendAutomatedConversationReplyJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Cenário 3
    // ---------------------------------------------------------------

    public function test_cenario_3_opt_out_marca_nao_contatar_interrompe_lotes_e_nao_envia(): void
    {
        Queue::fake();
        [$contact, , $conversation, $state] = $this->makeScenario();

        $batch = MessageBatch::factory()->create();
        $recipient = MessageBatchRecipient::factory()->create([
            'message_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'processing_status' => MessageRecipientProcessingStatus::Pending,
            'contact_phone_snapshot' => $contact->phone_normalized,
        ]);

        $message = $this->incoming($conversation, 'não quero receber mensagens');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        $state->refresh();
        $contact->refresh();
        $recipient->refresh();

        $this->assertSame(ConversationFlowStage::OptedOut, $state->current_stage);
        $this->assertTrue($contact->do_not_contact);
        $this->assertSame(MessageRecipientProcessingStatus::Skipped, $recipient->processing_status);
        $this->assertSame('CONTACT_REPLIED', $recipient->error_code);

        $this->assertSame(0, ConversationMessage::where('conversation_id', $conversation->id)
            ->where('origin', ConversationMessageOrigin::Automation)->count());

        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    // ---------------------------------------------------------------
    // Cenário 4
    // ---------------------------------------------------------------

    public function test_cenario_4_texto_ambiguo_nao_envia_pergunta_e_sinaliza_humano(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        $message = $this->incoming($conversation, 'quem e você e por que esta me mandando mensagem sobre isso agora');

        app(ConversationFlowService::class)->handleIncomingMessage($message);

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->current_stage);
        $this->assertTrue($state->needs_human_review);
        $this->assertSame(0, $state->questionUsages()->count());
        $this->assertSame(0, ConversationMessage::where('conversation_id', $conversation->id)
            ->where('origin', ConversationMessageOrigin::Automation)->count());

        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    // ---------------------------------------------------------------
    // Cenário 5
    // ---------------------------------------------------------------

    public function test_cenario_5_automacao_global_desligada_nao_cria_resposta(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        app(SystemSettingService::class)->updateMany(['conversation_automation.enabled' => '0']);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->current_stage);
        $this->assertSame(0, ConversationMessage::where('origin', ConversationMessageOrigin::Automation)->count());
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    public function test_cenario_5_fluxo_pausado_nao_cria_resposta(): void
    {
        Queue::fake();
        [, $flow, $conversation, $state] = $this->makeScenario();

        $flow->update(['status' => ConversationFlowStatus::Paused]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingPermission, $state->current_stage);
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    public function test_cenario_5_conversa_pausada_nao_cria_resposta(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        $state->update(['is_paused' => true]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $state->refresh();

        $this->assertSame(0, $state->questionUsages()->count());
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    // ---------------------------------------------------------------
    // Cenário 6
    // ---------------------------------------------------------------

    public function test_cenario_6_sem_pergunta_disponivel_vai_para_humano_com_evento(): void
    {
        Queue::fake();
        [, $flow, $conversation, $state] = $this->makeScenario(questions: 1);

        // Torna a única pergunta inativa: não ha candidata elegível.
        ConversationFlowQuestion::where('conversation_flow_id', $flow->id)->update(['is_active' => false]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $state->refresh();

        $this->assertSame(ConversationFlowStage::WaitingHuman, $state->current_stage);
        $this->assertSame('sem_pergunta_disponivel', $state->end_reason);
        $this->assertSame(0, ConversationMessage::where('origin', ConversationMessageOrigin::Automation)->count());

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversation->id,
            'event_type' => 'automation_no_question_available',
        ]);
    }

    public function test_cenario_6_sem_pergunta_disponivel_pode_concluir_por_configuracao(): void
    {
        Queue::fake();
        [, $flow, $conversation, $state] = $this->makeScenario(questions: 1);

        ConversationFlowQuestion::where('conversation_flow_id', $flow->id)->update(['is_active' => false]);
        app(SystemSettingService::class)->updateMany(['conversation_automation.no_question_behavior' => 'completed']);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
    }

    // ---------------------------------------------------------------
    // Regras adicionais
    // ---------------------------------------------------------------

    public function test_recusa_nao_marca_nao_contatar_por_padrao(): void
    {
        Queue::fake();
        [$contact, , $conversation, $state] = $this->makeScenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'não obrigado'));

        $this->assertSame(ConversationFlowStage::PermissionDenied, $state->refresh()->current_stage);
        $this->assertFalse($contact->refresh()->do_not_contact);
    }

    public function test_recusa_marca_nao_contatar_quando_configurado(): void
    {
        Queue::fake();
        [$contact, , $conversation] = $this->makeScenario();

        app(SystemSettingService::class)->updateMany(['conversation_automation.mark_do_not_contact_on_refusal' => '1']);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'nao'));

        $this->assertTrue($contact->refresh()->do_not_contact);
    }

    public function test_mensagem_fora_de_ordem_nao_reinicia_fluxo_encerrado(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        $state->update(['current_stage' => ConversationFlowStage::Completed]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $this->assertSame(ConversationFlowStage::Completed, $state->refresh()->current_stage);
        $this->assertSame(0, $state->questionUsages()->count());
    }

    public function test_mesma_pergunta_nao_e_sorteada_duas_vezes_na_mesma_conversa(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario(questions: 2);

        $selector = app(ConversationQuestionSelector::class);

        $first = $selector->select($state);
        $second = $selector->select($state);
        $third = $selector->select($state);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($third, 'Não deve sortear além do número de perguntas ativas.');
        $this->assertNotSame($first->conversation_flow_question_id, $second->conversation_flow_question_id);
    }

    public function test_envio_automatico_desabilitado_nao_cria_mensagem(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        app(SystemSettingService::class)->updateMany(['conversation_automation.auto_send_enabled' => '0']);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $this->assertSame(0, ConversationMessage::where('origin', ConversationMessageOrigin::Automation)->count());
        $this->assertSame(0, $state->refresh()->questionUsages()->count());
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    public function test_contato_nao_contatar_bloqueia_envio_automatico(): void
    {
        Queue::fake();
        [$contact, , $conversation] = $this->makeScenario();

        $contact->update(['do_not_contact' => true]);

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $this->assertSame(0, ConversationMessage::where('origin', ConversationMessageOrigin::Automation)->count());
        Queue::assertNotPushed(SendAutomatedConversationReplyJob::class);
    }

    public function test_ativacao_por_campanha_e_idempotente(): void
    {
        [$contact, $flow, $conversation] = $this->makeScenario();
        ConversationFlowState::where('conversation_id', $conversation->id)->delete();

        $service = app(ConversationFlowService::class);

        $first = $service->activateForConversation($conversation, $flow);
        $second = $service->activateForConversation($conversation, $flow);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ConversationFlowState::where('conversation_id', $conversation->id)->count());
        $this->assertSame(ConversationFlowStage::WaitingPermission, $first->refresh()->current_stage);
    }

    public function test_ativacao_ignora_contato_inelegivel(): void
    {
        [$contact, $flow, $conversation] = $this->makeScenario();
        ConversationFlowState::where('conversation_id', $conversation->id)->delete();
        $contact->update(['do_not_contact' => true]);

        $state = app(ConversationFlowService::class)->activateForConversation($conversation->refresh(), $flow);

        $this->assertNull($state);
        $this->assertSame(0, ConversationFlowState::where('conversation_id', $conversation->id)->count());
    }

    public function test_transicoes_sao_registradas_no_historico(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $this->assertDatabaseHas('conversation_flow_transitions', [
            'conversation_id' => $conversation->id,
            'to_stage' => ConversationFlowStage::PermissionGranted->value,
            'decision' => PermissionResponseClassification::PermissionYes->value,
        ]);

        $this->assertDatabaseHas('conversation_flow_transitions', [
            'conversation_id' => $conversation->id,
            'to_stage' => ConversationFlowStage::WaitingAnswer->value,
        ]);
    }

    public function test_resposta_a_pergunta_agradece_e_conclui(): void
    {
        Queue::fake();
        [, , $conversation, $state] = $this->makeScenario();

        app(ConversationFlowService::class)->handleIncomingMessage($this->incoming($conversation, 'sim'));

        $state->refresh();
        $this->assertSame(ConversationFlowStage::WaitingAnswer, $state->current_stage);

        $answer = $this->incoming($conversation, 'Acho que ela deveria priorizar educação e saúde na região oeste do estado.');
        app(ConversationFlowService::class)->handleIncomingMessage($answer);

        $state->refresh();
        $this->assertSame(ConversationFlowStage::Completed, $state->current_stage);
        $this->assertSame('resposta_recebida', $state->end_reason);
    }

    // ---------------------------------------------------------------
    // Job / integração
    // ---------------------------------------------------------------

    public function test_job_de_avaliacao_usa_fila_propria_e_nao_a_de_entrada(): void
    {
        Queue::fake();
        [, , $conversation] = $this->makeScenario();

        EvaluateConversationFlowJob::dispatch($this->incoming($conversation, 'sim')->id);

        Queue::assertPushedOn('conversation-automation', EvaluateConversationFlowJob::class);
    }

    public function test_guard_respeita_janela_de_horario(): void
    {
        $guard = app(ConversationAutomationGuard::class);

        app(SystemSettingService::class)->updateMany([
            'conversation_automation.window_start' => '08:00',
            'conversation_automation.window_end' => '20:00',
        ]);

        $this->assertTrue($guard->withinWindow(now()->setTime(10, 0)));
        $this->assertFalse($guard->withinWindow(now()->setTime(23, 30)));
    }

    // ---------------------------------------------------------------
    // Rotas e permissões
    // ---------------------------------------------------------------

    public function test_rotas_administrativas_exigem_permissao(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'consulta')->first());

        // Consulta pode ver, mas não gerenciar fluxos.
        $this->actingAs($user)->get(route('admin.conversation-automation.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.conversation-flows.create'))->assertForbidden();
    }

    public function test_administrador_gerencia_fluxos_e_perguntas(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'administrador')->first());

        $this->actingAs($admin)->post(route('admin.conversation-flows.store'), [
            'name' => 'Pesquisa Norma',
            'status' => 'draft',
            'max_main_questions' => 1,
            'max_followups' => 0,
            'validity_hours' => 48,
        ])->assertRedirect();

        $flow = ConversationFlow::where('name', 'Pesquisa Norma')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.conversation-flows.questions.store', $flow), [
            'internal_title' => 'Pergunta principal',
            'text' => 'O que a Professora Norma pode fazer para melhorar nosso Estado?',
            'weight' => 5,
            'display_order' => 1,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('conversation_flow_questions', [
            'conversation_flow_id' => $flow->id,
            'internal_title' => 'Pergunta principal',
            'weight' => 5,
        ]);
    }

    public function test_pergunta_usada_e_excluida_apenas_logicamente(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'administrador')->first());

        [, $flow] = $this->makeScenario(questions: 1);
        $question = ConversationFlowQuestion::where('conversation_flow_id', $flow->id)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.conversation-flows.questions.destroy', [$flow, $question]))
            ->assertRedirect();

        $this->assertSoftDeleted('conversation_flow_questions', ['id' => $question->id]);
    }

    public function test_operador_controla_automacao_da_conversa(): void
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::where('slug', 'operador')->first());

        [, , , $state] = $this->makeScenario();

        $this->actingAs($operator)->post(route('admin.conversation-automation.pause', $state))->assertRedirect();
        $this->assertTrue($state->refresh()->is_paused);

        $this->actingAs($operator)->post(route('admin.conversation-automation.resume', $state))->assertRedirect();
        $this->assertFalse($state->refresh()->is_paused);
    }
}
