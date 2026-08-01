<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\Knowledge\DocumentIngestionService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Limite de tamanho do envio de documento.
 *
 * A configuração dizia 20 MB e o PHP do site aceitava 2 MB. Quem tentava enviar
 * um PDF de 5 MB via a tela prometer 20 MB e recebia "Falha ao enviar o arquivo
 * file." — mensagem que o PHP produz ao recusar o envio antes de a aplicação
 * existir, e que não diz nada sobre tamanho.
 *
 * O servidor foi corrigido. Estes testes cuidam da classe do problema: a tela
 * nunca pode prometer mais do que o servidor cumpre.
 */
class LimiteDeEnvioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    private function servico(): DocumentIngestionService
    {
        Cache::flush();

        return app(DocumentIngestionService::class);
    }

    private function configurar(int $mb): void
    {
        SystemSetting::query()
            ->where('key', 'knowledge.max_file_size_mb')
            ->update(['value' => (string) $mb]);
    }

    /**
     * O caso que aconteceu: configuração generosa, servidor apertado. Vale o
     * servidor, porque e ele quem recusa.
     */
    public function test_vale_o_limite_do_servidor_quando_ele_e_menor(): void
    {
        $this->configurar(500);

        $limite = $this->servico()->maxFileSizeKb();
        $doPhp = $this->emKb((string) ini_get('upload_max_filesize'));

        $this->assertLessThanOrEqual(
            $doPhp,
            $limite,
            'A tela não pode prometer mais do que o PHP aceita receber.'
        );
    }

    /**
     * E o contrário também: servidor folgado não autoriza mais do que a
     * configuração permite.
     */
    public function test_vale_a_configuracao_quando_ela_e_menor(): void
    {
        $this->configurar(1);

        $this->assertSame(1024, $this->servico()->maxFileSizeKb());
    }

    /**
     * `20M` são vinte megabytes, e não vinte kilobytes. Ler o número cru e
     * ignorar o sufixo transformaria o limite em quase nada.
     */
    public function test_o_sufixo_da_diretiva_do_php_e_respeitado(): void
    {
        $this->configurar(500);

        $limite = $this->servico()->maxFileSizeKb();

        // Qualquer servidor razoável aceita mais de um megabyte; se o sufixo
        // fosse ignorado, `20M` viraria 20 kB e o limite ficaria em 20.
        $this->assertGreaterThan(1024, $limite);
    }

    public function test_o_limite_nunca_e_zero_ou_negativo(): void
    {
        $this->configurar(0);

        $this->assertGreaterThan(0, $this->servico()->maxFileSizeKb());
    }

    private function emKb(string $valor): int
    {
        $numero = (int) $valor;

        return match (strtoupper(substr(trim($valor), -1))) {
            'G' => $numero * 1024 * 1024,
            'M' => $numero * 1024,
            'K' => $numero,
            default => intdiv($numero, 1024),
        };
    }
}
