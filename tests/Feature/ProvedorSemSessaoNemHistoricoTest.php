<?php

namespace Tests\Feature;

use App\Contracts\PairsBySession;
use App\Contracts\ReadsConversationHistory;
use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\SendResult;
use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Models\ConversationSyncRun;
use App\Services\Conversations\ConversationSyncService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use App\Services\WhatsApp\WhatsAppWebProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um provedor pode não parear sessão nem ler histórico.
 *
 * QR Code, reconectar e limpar sessão só existem no WhatsApp Web. Ler conversas
 * antigas também: é isso que permite a sincronização recuperar mensagem que o
 * webhook perdeu, e foi assim que os áudios da conversa 421 entraram depois de
 * a validação recusá-los.
 *
 * A API oficial da Meta não tem nenhum dos dois. Enquanto o contrato exigia
 * esses métodos de todo provedor, o próximo seria obrigado a fingir que os tem
 * — devolvendo lista vazia, o que faria a sincronização parecer bem-sucedida e
 * sempre encontrar zero conversa. Perder um recurso sem ninguém notar é pior do
 * que perdê-lo com barulho.
 */
class ProvedorSemSessaoNemHistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_provedor_do_whatsapp_web_tem_os_tres_contratos(): void
    {
        $provider = app(WhatsAppWebProvider::class);

        $this->assertInstanceOf(WhatsAppProvider::class, $provider);
        $this->assertInstanceOf(PairsBySession::class, $provider);
        $this->assertInstanceOf(ReadsConversationHistory::class, $provider);
    }

    /**
     * O essencial é só isto: dizer se está de pé e mandar mensagem. Um provedor
     * que faça apenas isso precisa ser aceito, senão a troca não acontece.
     */
    public function test_provedor_minimo_e_aceito_pelo_contrato(): void
    {
        $this->assertInstanceOf(WhatsAppProvider::class, $this->provedorMinimo());
    }

    /**
     * A sincronização recusa com nome próprio, e não com erro de tipo. Quem lê
     * a tela precisa entender que não há o que sincronizar naquele provedor —
     * não que algo quebrou.
     */
    public function test_sincronizacao_recusa_provedor_sem_historico(): void
    {
        $this->trocarProvedorPeloMinimo();

        $run = ConversationSyncRun::create(['status' => 'pending']);
        app(ConversationSyncService::class)->run($run);

        $this->assertSame('HISTORY_NOT_SUPPORTED', $run->fresh()->error_code);
        $this->assertStringContainsString('não há o que sincronizar', (string) $run->fresh()->error_message);
    }

    public function test_a_tela_de_conexao_recusa_provedor_sem_pareamento(): void
    {
        $this->trocarProvedorPeloMinimo();

        $this->expectException(WhatsAppServiceException::class);

        app(\App\Services\WhatsApp\WhatsAppConnectionService::class)
            ->requestQr(\App\Models\User::factory()->create(), \Illuminate\Http\Request::create('/'));
    }

    private function trocarProvedorPeloMinimo(): void
    {
        $provedor = $this->provedorMinimo();

        $this->mock(WhatsAppProviderManager::class, function ($mock) use ($provedor): void {
            $mock->shouldReceive('provider')->andReturn($provedor);
        });
    }

    private function provedorMinimo(): WhatsAppProvider
    {
        return new class implements WhatsAppProvider
        {
            public function getStatus(): ConnectionStatus
            {
                return new ConnectionStatus(WhatsAppConnectionStatus::Connected);
            }

            public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
            {
                return new SendResult(requestId: $requestId, status: 'sent');
            }

            public function sendMessage(string $phone, string $message, string $requestId): SendResult
            {
                return new SendResult(requestId: $requestId, status: 'sent');
            }
        };
    }
}
