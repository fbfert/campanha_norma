<?php

namespace Tests\Feature;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\InboundAttendanceOutcome;
use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\InboundAttendanceAttempt;
use App\Models\InboundAttendanceProfile;
use App\Models\User;
use App\Services\InboundAttendance\InboundAttendanceQueue;
use App\Services\InboundAttendance\InboundAttendanceService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quem escreve primeiro é atendido.
 *
 * Até aqui todo fluxo nascia de um lote: o sistema falava, a pessoa respondia,
 * e o motor sabia o que fazer porque tinha aberto a conversa. Quem escrevia por
 * conta própria caía num motor sem estado, que saía calado — atendimento humano
 * quando havia gente olhando, e silêncio quando não havia.
 *
 * O que estes testes cobram é o conjunto de travas. O atendimento automático
 * fala em nome de alguém, com quem nunca falou com a gente, sem ninguém ler
 * antes: cada trava aqui é uma forma conhecida de isso dar errado.
 */
class QuemEscrevePrimeiroEAtendidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Http::fake([
            '127.0.0.1:3100/api/status' => Http::response(['success' => true, 'data' => ['status' => 'connected']], 200),
            '127.0.0.1:3100/api/*' => fn () => Http::response(['success' => true, 'data' => [
                'request_id' => (string) Str::uuid(),
                'status' => 'sent',
                'external_message_id' => 'wamid.'.Str::random(16),
                'sent_at' => now()->toIso8601String(),
            ]], 200),
        ]);

        app(SystemSettingService::class)->updateMany([
            'conversation_automation.enabled' => '1',
            'conversation_automation.auto_send_enabled' => '1',
            'conversation_automation.window_start' => '00:00',
            'conversation_automation.window_end' => '23:59',
            'inbound_attendance.enabled' => '1',
        ]);
    }

    public function test_numero_novo_vira_contato_e_recebe_a_abertura(): void
    {
        $perfil = $this->perfil();
        $mensagem = $this->mensagemRecebida('Oi, queria saber sobre a candidata');

        $resultado = app(InboundAttendanceService::class)->handle($mensagem);

        $this->assertSame(InboundAttendanceOutcome::Started, $resultado['outcome']);

        $conversa = $mensagem->conversation->refresh();
        $contato = $conversa->contact;

        $this->assertNotNull($contato, 'O contato precisa nascer no momento em que a conversa automática começa.');
        $this->assertSame('recebido', $contato->source->value);
        $this->assertSame('5549988887777', $contato->phone_normalized);

        // Consentimento não é presumido por a pessoa ter escrito: ela autorizou
        // uma resposta, não autorizou entrar em campanha.
        $this->assertSame(\App\Enums\ConsentStatus::NotInformed, $contato->consent_status);

        $estado = ConversationFlowState::where('conversation_id', $conversa->id)->first();
        $this->assertNotNull($estado);
        $this->assertSame(ConversationFlowStage::WaitingPermission, $estado->current_stage);
        $this->assertSame($perfil->id, $estado->inbound_attendance_profile_id);

        $abertura = ConversationMessage::where('conversation_id', $conversa->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->first();

        $this->assertNotNull($abertura);
        $this->assertSame(ConversationMessageOrigin::Automation, $abertura->origin);
        $this->assertStringContainsString('pesquisa', $abertura->body);
    }

    public function test_o_conteudo_da_mensagem_escolhe_o_perfil(): void
    {
        $saude = $this->perfil(['name' => 'Saúde', 'is_fallback' => false, 'match_expressions' => 'posto|hospital|remédio', 'match_priority' => 10]);
        $geral = $this->perfil(['name' => 'Geral', 'is_fallback' => true, 'match_expressions' => null, 'match_priority' => 900]);

        $resultado = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('o posto da minha rua fechou'));

        $this->assertSame($saude->id, $resultado['profile']->id);

        // Ninguém escreve pensando na nossa lista, e quem escreve algo fora
        // dela é quem mais precisa de resposta.
        $outra = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('bom dia, tudo bem?', '5549977776666'));

        $this->assertSame($geral->id, $outra['profile']->id);
    }

    public function test_expressao_nao_casa_dentro_de_outra_palavra(): void
    {
        $this->perfil(['name' => 'Voto', 'is_fallback' => false, 'match_expressions' => 'voto', 'match_priority' => 10]);
        $geral = $this->perfil(['name' => 'Geral', 'is_fallback' => true, 'match_expressions' => null, 'match_priority' => 900]);

        // A palavra `denuncia` dentro da lista de opt-out já removeu da base
        // quem só queria fazer uma denúncia. Casar por pedaço de palavra é o
        // mesmo defeito com outro nome.
        $resultado = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('sou devoto de nossa senhora'));

        $this->assertSame($geral->id, $resultado['profile']->id);
    }

    public function test_perfil_novo_nao_sai_sozinho_e_se_solta_depois_da_homologacao(): void
    {
        $perfil = $this->perfil(['homologation_threshold' => 2]);

        $automatico = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('oi'));

        $this->assertSame(InboundAttendanceOutcome::Blocked, $automatico['outcome']);
        $this->assertSame('aguardando_homologacao', $automatico['reason']);
        $this->assertNull(ConversationFlowState::first(), 'Perfil em homologação não pode abrir conversa sozinho.');

        $operador = User::factory()->create();

        // Duas conversas aprovadas por gente: é o que a homologação pede.
        app(InboundAttendanceService::class)->handle($this->mensagemRecebida('oi de novo', '5549911112222'), $operador);
        app(InboundAttendanceService::class)->handle($this->mensagemRecebida('oi mais uma vez', '5549933334444'), $operador);

        $perfil->refresh();
        $this->assertNotNull($perfil->homologated_at);
        $this->assertFalse($perfil->needsHumanApproval());

        $depois = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('agora sim', '5549955556666'));
        $this->assertSame(InboundAttendanceOutcome::Started, $depois['outcome']);
    }

    public function test_teto_diario_manda_para_a_fila_e_o_clique_passa(): void
    {
        $perfil = $this->perfil(['daily_start_limit' => 1, 'homologation_threshold' => 0]);

        $primeira = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('primeira'));
        $this->assertSame(InboundAttendanceOutcome::Started, $primeira['outcome']);

        $segunda = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('segunda', '5549911112222'));
        $this->assertSame('teto_diario_do_perfil', $segunda['reason']);

        // O teto contém o que sai sozinho. Barrar quem está olhando a conversa
        // e decidiu iniciá-la seria inverter o propósito da trava.
        $terceira = app(InboundAttendanceService::class)->handle(
            $this->mensagemRecebida('terceira', '5549933334444'),
            User::factory()->create(),
        );

        $this->assertSame(InboundAttendanceOutcome::Started, $terceira['outcome']);
        $this->assertSame(1, app(\App\Services\InboundAttendance\InboundAttendanceGuard::class)->startedToday($perfil->id));
    }

    public function test_fora_da_janela_do_perfil_nada_sai(): void
    {
        $this->perfil([
            'homologation_threshold' => 0,
            'window_start' => now()->addHours(2)->format('H:i'),
            'window_end' => now()->addHours(4)->format('H:i'),
        ]);

        $resultado = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('boa madrugada'));

        $this->assertSame('fora_da_janela_de_horario', $resultado['reason']);
        $this->assertNull(ConversationFlowState::first());
    }

    public function test_a_chave_geral_desliga_so_o_atendimento_de_entrada(): void
    {
        $this->perfil(['homologation_threshold' => 0]);
        app(SystemSettingService::class)->updateMany(['inbound_attendance.enabled' => '0']);

        $resultado = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('alguém aí?'));

        $this->assertSame('atendimento_desligado', $resultado['reason']);

        // A automação da pesquisa continua ligada: a chave é separada de
        // propósito, e desligar uma não pode desligar a outra.
        $this->assertTrue((bool) app(SystemSettingService::class)->get('conversation_automation.enabled'));
    }

    public function test_conversa_que_ja_tem_fluxo_nao_recebe_segunda_abertura(): void
    {
        $perfil = $this->perfil(['homologation_threshold' => 0]);
        $mensagem = $this->mensagemRecebida('respondendo ao lote');

        ConversationFlowState::create([
            'conversation_id' => $mensagem->conversation_id,
            'conversation_flow_id' => $perfil->conversation_flow_id,
            'current_stage' => ConversationFlowStage::WaitingPermission,
            'started_at' => now(),
            'expires_at' => now()->addHours(48),
        ]);

        $resultado = app(InboundAttendanceService::class)->handle($mensagem);

        $this->assertSame('conversa_ja_tem_fluxo', $resultado['reason']);
        $this->assertSame(0, ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    public function test_sem_resposta_confiavel_a_pesquisa_nao_e_puxada(): void
    {
        // Modo padrão: responder o que a pessoa escreveu e só então apresentar
        // a pesquisa. Sem IA configurada não há resposta, e apresentar a
        // pesquisa por cima da pergunta dela seria responder outra coisa.
        $this->perfil(['opening_mode' => InboundOpeningMode::AiThenSurvey, 'homologation_threshold' => 0]);

        $resultado = app(InboundAttendanceService::class)->handle($this->mensagemRecebida('vocês atendem em Chapecó?'));

        $this->assertSame('resposta_ia_indisponivel', $resultado['reason']);
        $this->assertNull(ConversationFlowState::first(), 'Estado sem mensagem faria a conversa parecer atendida.');
        $this->assertSame(0, ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    public function test_a_fila_mostra_o_que_a_automacao_nao_resolveu(): void
    {
        $this->perfil(['homologation_threshold' => 0, 'daily_start_limit' => 0]);
        $recusada = $this->mensagemRecebida('oi', '5549911112222');
        app(SystemSettingService::class)->updateMany(['inbound_attendance.enabled' => '0']);
        app(InboundAttendanceService::class)->handle($recusada);

        $fila = app(InboundAttendanceQueue::class);

        $this->assertTrue(
            $fila->pending()->whereKey($recusada->conversation_id)->exists(),
            'Conversa recusada por uma trava precisa aparecer na fila, com o motivo.',
        );

        $this->assertSame('atendimento_desligado', InboundAttendanceAttempt::latest('id')->first()->reason);

        // Conversa respondida sai da fila: a última palavra passou a ser nossa.
        ConversationMessage::create([
            'conversation_id' => $recusada->conversation_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'origin' => ConversationMessageOrigin::Manual,
            'message_type' => 'text',
            'body' => 'oi, tudo bem?',
            'status' => \App\Enums\ConversationMessageStatus::Sent,
            'request_id' => (string) Str::uuid(),
        ]);

        $this->assertFalse($fila->pending()->whereKey($recusada->conversation_id)->exists());
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('respostasQueNaoAbremConversa')]
    public function test_so_resposta_que_responde_abre_conversa(array $atributos, bool $esperado, string $porque): void
    {
        $sugestao = new \App\Models\ConversationReplySuggestion(array_merge([
            'action' => \App\Enums\ReplySuggestionAction::SuggestReply,
            'generated_text' => 'A Prof. Norma atua na área de educação em Santa Catarina.',
            'confidence' => 0.96,
            'requires_human_review' => false,
        ], $atributos));

        $this->assertSame(
            $esperado,
            app(InboundAttendanceService::class)->openingAnswerUsable($sugestao),
            $porque,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public static function respostasQueNaoAbremConversa(): array
    {
        return [
            'resposta de verdade' => [[], true, 'Resposta confiante e que responde é o único caso que abre conversa.'],

            // Saiu assim, para uma pessoa de verdade: "Agradeço por participar
            // da pesquisa (...)" seguido de "Posso te fazer uma pergunta
            // rápida?". A mensagem encerra e abre no mesmo fôlego.
            'encerramento' => [
                ['action' => \App\Enums\ReplySuggestionAction::ThankAndComplete],
                false,
                'Encerramento colado na apresentação produz uma mensagem que se contradiz.',
            ],

            // O mesmo defeito pelo avesso: perguntar o que a pessoa quis dizer
            // e, na mesma mensagem, mudar de assunto para a pesquisa.
            'pedido de esclarecimento' => [
                ['action' => \App\Enums\ReplySuggestionAction::RequestClarification],
                false,
                'Pedir esclarecimento e mudar de assunto na mesma mensagem não responde ninguém.',
            ],

            'marcada para revisão' => [
                ['requires_human_review' => true],
                false,
                'Se o próprio sistema pediu revisão, o texto não sai sem ninguém ler.',
            ],
            'confiança baixa' => [
                ['confidence' => 0.70],
                false,
                'É a primeira coisa que essa pessoa vai ler da gente.',
            ],
            'sem texto' => [
                ['generated_text' => ''],
                false,
                'Texto vazio colado na apresentação é só a apresentação.',
            ],
        ];
    }

    public function test_mensagem_antiga_nao_abre_conversa_nem_no_clique(): void
    {
        $this->perfil(['homologation_threshold' => 0]);

        // A conversa 321 foi iniciada em 12/08 respondendo a um "Certo,
        // obrigada" de 15/07. Do lado da pessoa, uma conversa encerrada em
        // julho voltou sozinha em agosto.
        $antiga = $this->mensagemRecebida('Certo, obrigada');
        $antiga->forceFill(['received_at' => now()->subDays(28)])->save();

        $automatico = app(InboundAttendanceService::class)->handle($antiga);
        $this->assertSame('mensagem_antiga', $automatico['reason']);

        // "Marcar todas" não olha data nenhuma, então a trava vale no clique.
        $clique = app(InboundAttendanceService::class)->handle($antiga, User::factory()->create());
        $this->assertSame('mensagem_antiga', $clique['reason']);

        $this->assertNull(ConversationFlowState::first());
        $this->assertSame(0, ConversationMessage::where('direction', ConversationMessageDirection::Outgoing)->count());
    }

    public function test_mensagem_de_robo_nao_abre_atendimento_nem_ocupa_a_fila(): void
    {
        $this->perfil(['homologation_threshold' => 0]);

        $robo = $this->mensagemRecebida('📲 Por aqui você pode recarregar um número Vivo.');
        $resultado = app(InboundAttendanceService::class)->handle($robo);

        $this->assertSame(InboundAttendanceOutcome::Skipped, $resultado['outcome']);
        $this->assertSame('mensagem_ignorada', $resultado['reason']);
        $this->assertNull(ConversationFlowState::first());

        // Uma fila que mistura gente esperando com aviso de operadora ensina a
        // ignorar a fila, que é o oposto do que ela existe para fazer.
        $fila = app(InboundAttendanceQueue::class);
        $this->assertFalse($fila->pending()->whereKey($robo->conversation_id)->exists());

        // E não some em silêncio: a expressão que casou fica registrada.
        $ignorada = $fila->skippedToday()->first();
        $this->assertNotNull($ignorada);
        $this->assertSame('recarregar um número', $ignorada->metadata['expressao']);
    }

    public function test_a_exclusao_pega_frase_inteira_e_nao_palavra_solta(): void
    {
        $this->perfil(['homologation_threshold' => 0]);

        // Quem escreve sobre o preço da recarga é justamente quem se quer
        // atender. `recarga` na lista de exclusão engoliria essa pessoa.
        $pessoa = $this->mensagemRecebida('o preço da recarga aumentou muito aqui na cidade');
        $resultado = app(InboundAttendanceService::class)->handle($pessoa);

        $this->assertSame(InboundAttendanceOutcome::Started, $resultado['outcome']);
    }

    public function test_a_exclusao_limpa_o_que_ja_estava_parado_na_fila(): void
    {
        $this->perfil(['homologation_threshold' => 0]);
        $fila = app(InboundAttendanceQueue::class);

        // Conversa parada de antes: chegou quando a lista ainda não existia.
        $robo = $this->mensagemRecebida('📲 Por aqui você pode recarregar um número Vivo.');
        $robo->conversation->forceFill(['last_incoming_message_at' => now()->subHour()])->save();
        $pessoa = $this->mensagemRecebida('quero falar sobre a saúde na cidade', '5549911112222');
        $pessoa->conversation->forceFill(['last_incoming_message_at' => now()->subHour()])->save();

        $this->assertSame(2, $fila->pending()->count());

        // Sem isto, a pessoa acrescenta a frase, olha a fila e vê a mesma linha
        // no mesmo lugar.
        $removidas = app(InboundAttendanceService::class)->applyExclusionsToPending();

        $this->assertSame(1, $removidas);
        $this->assertSame(1, $fila->pending()->count());
        $this->assertTrue($fila->pending()->whereKey($pessoa->conversation_id)->exists());
    }

    public function test_o_clique_atende_mesmo_o_que_a_exclusao_descartou(): void
    {
        $this->perfil(['homologation_threshold' => 0]);
        $robo = $this->mensagemRecebida('📲 Por aqui você pode recarregar um número Vivo.');

        app(InboundAttendanceService::class)->handle($robo);

        // A exclusão existe para poupar atenção, não para contrariar quem já
        // prestou atenção: quem clica está olhando a conversa.
        $resultado = app(InboundAttendanceService::class)->handle($robo, User::factory()->create());

        $this->assertSame(InboundAttendanceOutcome::Started, $resultado['outcome']);
    }

    public function test_as_telas_abrem_e_o_contador_aparece(): void
    {
        $this->perfil(['homologation_threshold' => 0]);
        $administrador = $this->administrador();

        $recusada = $this->mensagemRecebida('oi', '5549911112222');
        app(SystemSettingService::class)->updateMany(['inbound_attendance.enabled' => '0']);
        app(InboundAttendanceService::class)->handle($recusada);

        $this->actingAs($administrador)
            ->get(route('admin.inbound-attendance.index'))
            ->assertOk()
            ->assertSee('Aguardando resposta')
            ->assertSee('Atendimento de entrada desligado');

        // O contador vai no topo de toda tela, e não só na fila.
        $this->actingAs($administrador)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('mensagem aguarda resposta');

        $this->actingAs($administrador)->get(route('admin.inbound-attendance.profiles.index'))->assertOk();
        $this->actingAs($administrador)->get(route('admin.inbound-attendance.profiles.create'))->assertOk();
    }

    public function test_o_formulario_recusa_perfil_ativo_sem_destino_para_o_que_sobrou(): void
    {
        $flow = ConversationFlow::factory()->create(['status' => ConversationFlowStatus::Active]);

        // Perfil ativo, com expressões, e ninguém marcado para atender o resto:
        // quem escrever fora da lista fica sem resposta e ninguém descobre.
        $this->actingAs($this->administrador())
            ->post(route('admin.inbound-attendance.profiles.store'), [
                'name' => 'Saúde',
                'status' => InboundAttendanceProfileStatus::Active->value,
                'match_expressions' => 'posto',
                'match_priority' => 10,
                'conversation_flow_id' => $flow->id,
                'opening_mode' => InboundOpeningMode::SurveyOnly->value,
                'presentation_text' => 'Olá!',
                'daily_start_limit' => 50,
                'homologation_threshold' => 5,
            ])
            ->assertSessionHasErrors('is_fallback');

        $this->assertSame(0, InboundAttendanceProfile::count());
    }

    public function test_iniciar_pela_fila_exige_permissao(): void
    {
        $this->perfil(['homologation_threshold' => 0]);
        $mensagem = $this->mensagemRecebida('oi');

        $semPermissao = User::factory()->create();

        $this->actingAs($semPermissao)
            ->post(route('admin.inbound-attendance.start'), ['conversation_ids' => [$mensagem->conversation_id]])
            ->assertForbidden();

        $this->assertNull(ConversationFlowState::first());
    }

    private function administrador(): User
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $usuario = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $usuario->roles()->attach(\App\Models\Role::query()->where('slug', 'administrador')->firstOrFail());

        return $usuario->refresh()->load('roles.permissions');
    }

    private function perfil(array $atributos = []): InboundAttendanceProfile
    {
        $flow = ConversationFlow::factory()->create([
            'status' => ConversationFlowStatus::Active,
            'validity_hours' => 48,
        ]);

        return InboundAttendanceProfile::create(array_merge([
            'name' => 'Atendimento geral',
            'status' => InboundAttendanceProfileStatus::Active,
            'is_fallback' => true,
            'match_expressions' => null,
            'match_priority' => 100,
            'conversation_flow_id' => $flow->id,
            'opening_mode' => InboundOpeningMode::SurveyOnly,
            'presentation_text' => 'Olá! Estamos fazendo uma pesquisa rápida. Posso te fazer uma pergunta?',
            'daily_start_limit' => 50,
            'homologation_threshold' => 0,
        ], $atributos));
    }

    private function mensagemRecebida(string $texto, string $telefone = '5549988887777'): ConversationMessage
    {
        $conversa = Conversation::factory()->create([
            'contact_id' => null,
            'last_incoming_message_at' => now(),
        ]);

        return ConversationMessage::create([
            'conversation_id' => $conversa->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'message_type' => 'text',
            'body' => $texto,
            'status' => \App\Enums\ConversationMessageStatus::Received,
            'sender_phone_snapshot' => $telefone,
            'sender_name_snapshot' => 'Maria da Silva',
            'received_at' => now(),
        ]);
    }
}
