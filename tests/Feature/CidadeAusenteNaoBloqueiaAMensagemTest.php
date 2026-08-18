<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Placeholders\MessageRendererService;
use App\Services\Placeholders\PlaceholderCatalogService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contato sem cidade continua recebendo a pergunta.
 *
 * Em 17/08/2026 uma pessoa se inscreveu por palavra-chave, respondeu "Pode" ao
 * pedido de permissão e não recebeu pergunta nenhuma: as duas perguntas do
 * fluxo usam `{cidade}`, e quem entra por campanha nasce sem cidade — a
 * inscrição só tem nome e telefone. O motor recusou o envio e mandou a conversa
 * para atendimento humano.
 *
 * A recusa continua valendo para os campos em que não existe palavra genérica.
 * A cidade é a exceção porque a frase funciona sem o nome dela.
 */
class CidadeAusenteNaoBloqueiaAMensagemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    private function render(string $texto, ?string $cidade): array
    {
        $contato = Contact::factory()->create(['city' => $cidade, 'name' => 'Willien Professor']);

        return app(MessageRendererService::class)->render($texto, $contato);
    }

    public function test_sem_cidade_a_mensagem_sai_com_o_substituto(): void
    {
        $r = $this->render('O que a prof Norma pode fazer para melhorar {cidade}!', null);

        $this->assertSame('O que a prof Norma pode fazer para melhorar sua cidade!', $r['message']);
        $this->assertSame([], $r['missing'], 'Cidade vazia não pode mais bloquear o envio.');
        $this->assertSame([], $r['errors']);
        $this->assertSame(['cidade'], $r['fallbacks']);
    }

    public function test_cidade_vazia_conta_como_ausente(): void
    {
        $r = $this->render('Melhorar {cidade}?', '');

        $this->assertSame('Melhorar sua cidade?', $r['message']);
        $this->assertSame([], $r['missing']);
    }

    public function test_com_cidade_nada_muda(): void
    {
        $r = $this->render('Melhorar {cidade}?', 'Lages');

        $this->assertSame('Melhorar Lages?', $r['message']);
        $this->assertSame([], $r['fallbacks']);
    }

    /**
     * A recusa continua onde ela protege alguém: "Olá você" não é saudação, e
     * "{nome}" literal é pior que não mandar.
     */
    public function test_nome_ausente_continua_bloqueando(): void
    {
        $contato = Contact::factory()->create(['name' => 'Willien', 'city' => null]);
        $contato->forceFill(['first_name' => null])->save();

        $r = app(MessageRendererService::class)->render('Olá {primeiro_nome}, melhorar {cidade}?', $contato);

        $this->assertSame(['primeiro_nome'], $r['missing']);
        $this->assertNotEmpty($r['errors']);
        $this->assertStringContainsString('{primeiro_nome}', $r['message'], 'O campo sem substituto não é trocado.');
    }

    public function test_o_catalogo_so_tem_substituto_para_a_cidade(): void
    {
        $this->assertSame(
            [PlaceholderCatalogService::CITY => 'sua cidade'],
            app(PlaceholderCatalogService::class)->fallbacks(),
        );

        foreach (['nome', 'primeiro_nome', 'telefone', 'email', 'estado', 'pais'] as $campo) {
            $this->assertNull(app(PlaceholderCatalogService::class)->fallback($campo), "{$campo} não deveria ter substituto.");
        }
    }
}
