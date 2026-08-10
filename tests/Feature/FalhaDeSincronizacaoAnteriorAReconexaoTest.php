<?php

namespace Tests\Feature;

use App\Data\WhatsApp\ConnectionStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\ConversationSyncRun;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Conversations\SyncFailureNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A tela separa "está falhando" de "falhou antes de reconectar".
 *
 * Quando a sessão do WhatsApp cai, a sincronização falha a cada 15 minutos até
 * alguém reconectar. Entre a reconexão e a próxima execução, a tela continua
 * exibindo a última falha em vermelho — "conecte o WhatsApp antes de
 * sincronizar" — enquanto a tela de conexão diz "Conectado".
 *
 * As duas estavam certas, e era exatamente por isso que confundia: quem lia
 * concluía que o sistema estava quebrado naquele momento, quando o problema já
 * tinha passado. Aconteceu de verdade em 10/08/2026: sete falhas entre 10:45 e
 * 12:15, reconexão às 12:21, e a tela ainda mostrando o erro das 12:15.
 */
class FalhaDeSincronizacaoAnteriorAReconexaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_falha_anterior_a_reconexao_e_marcada_como_superada(): void
    {
        $execucao = $this->falhaDeConexao(terminadaEm: '2026-08-10 12:15:07');
        $this->conexao(status: WhatsAppConnectionStatus::Connected, conectadaEm: '2026-08-10 12:21:25');

        $aviso = app(SyncFailureNotice::class)->for($execucao);

        $this->assertTrue($aviso['superada']);
        $this->assertSame('2026-08-10 12:21:25', $aviso['reconectado_em']->format('Y-m-d H:i:s'));
    }

    /**
     * Reconexão anterior à falha não supera nada: aí a sincronização falhou
     * mesmo com o WhatsApp conectado, e o problema é outro.
     */
    public function test_falha_posterior_a_reconexao_continua_sendo_falha(): void
    {
        $execucao = $this->falhaDeConexao(terminadaEm: '2026-08-10 12:15:07');
        $this->conexao(status: WhatsAppConnectionStatus::Connected, conectadaEm: '2026-08-10 09:00:00');

        $this->assertFalse(app(SyncFailureNotice::class)->for($execucao)['superada']);
    }

    public function test_com_o_whatsapp_desconectado_a_falha_continua_valendo(): void
    {
        $execucao = $this->falhaDeConexao(terminadaEm: '2026-08-10 12:15:07');
        $this->conexao(status: WhatsAppConnectionStatus::Disconnected, conectadaEm: '2026-08-10 12:21:25');

        $this->assertFalse(app(SyncFailureNotice::class)->for($execucao)['superada']);
    }

    /**
     * Falha de outra natureza não é resolvida por reconectar, e apresentá-la
     * como superada esconderia um problema real.
     */
    public function test_falha_que_nao_e_de_conexao_nao_recebe_aviso(): void
    {
        $execucao = ConversationSyncRun::create([
            'status' => 'failed',
            'error_code' => 'WHATSAPP_GET_CHATS_FAILED',
            'error_message' => 'A consulta padrão dos chats falhou.',
            'finished_at' => '2026-08-10 12:15:07',
            'options' => [],
        ]);

        $this->conexao(status: WhatsAppConnectionStatus::Connected, conectadaEm: '2026-08-10 12:21:25');

        $this->assertNull(app(SyncFailureNotice::class)->for($execucao));
    }

    public function test_a_tela_troca_o_alarme_por_explicacao(): void
    {
        $this->falhaDeConexao(terminadaEm: '2026-08-10 12:15:07');
        $this->conexao(status: WhatsAppConnectionStatus::Connected, conectadaEm: '2026-08-10 12:21:25');

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Esta falha é anterior à reconexão do WhatsApp')
            ->assertDontSee('Conecte o WhatsApp antes de sincronizar as conversas.');
    }

    public function test_a_tela_ainda_alarma_quando_a_falha_e_atual(): void
    {
        $this->falhaDeConexao(terminadaEm: '2026-08-10 12:15:07');
        $this->conexao(status: WhatsAppConnectionStatus::Disconnected, conectadaEm: '2026-08-10 09:00:00');

        $this->actingAs($this->userWithRole('administrador'))
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Conecte o WhatsApp antes de sincronizar as conversas.');
    }

    /**
     * O instante do serviço vem em UTC e precisa virar hora local antes de ser
     * gravado.
     *
     * O Eloquent grava a hora no fuso que o objeto carrega, e a leitura de volta
     * interpreta a coluna como hora local. Sem a conversão, uma conexão das
     * 12:21 ficava gravada como 15:21 — três horas no futuro — e a comparação
     * com a execução da sincronização passava a comparar escalas diferentes.
     */
    public function test_o_horario_do_servico_vira_hora_local(): void
    {
        $status = ConnectionStatus::fromArray([
            'status' => 'connected',
            'connected_at' => '2026-08-10T15:21:25.533000Z',
            'last_activity_at' => '2026-08-10T15:21:25.533000Z',
        ]);

        $this->assertSame('2026-08-10 12:21:25', $status->connectedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-10 12:21:25', $status->lastActivityAt->format('Y-m-d H:i:s'));
    }

    private function falhaDeConexao(string $terminadaEm): ConversationSyncRun
    {
        return ConversationSyncRun::create([
            'status' => 'failed',
            'error_code' => 'WHATSAPP_NOT_CONNECTED',
            'error_message' => 'Conecte o WhatsApp antes de sincronizar as conversas.',
            'started_at' => $terminadaEm,
            'finished_at' => $terminadaEm,
            'options' => [],
        ]);
    }

    private function conexao(WhatsAppConnectionStatus $status, string $conectadaEm): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'status' => $status,
            'phone_number' => '554991888242',
            'connected_at' => Carbon::parse($conectadaEm),
            'last_activity_at' => Carbon::parse($conectadaEm),
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user->refresh()->load('roles');
    }
}
