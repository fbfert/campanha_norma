<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Páginas de erro próprias.
 *
 * Existem por dois motivos. O visível: quem erra um endereço ou esbarra numa
 * permissão passa a ver algo que parece o sistema, em português, com caminho de
 * volta.
 *
 * O invisível importa mais. A página padrão do Laravel e escrita em utilitarias
 * do Tailwind que nenhuma tela deste sistema usa. Elas so chegavam ao CSS
 * porque o `app.css` mandava varrer `storage/framework/views`, o cache de views
 * compiladas. Isso fazia o CSS gerado depender do estado do cache: um
 * `view:clear` antes do build produzia um arquivo diferente, e a página de erro
 * saia sem estilo. Com estas páginas usando as classes do próprio sistema, a
 * varredura do cache deixou de ser necessária e o build virou determinístico.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_missing_page_shows_the_system_error_screen(): void
    {
        $this->get('/endereco-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada');
    }

    public function test_a_forbidden_screen_explains_that_it_is_permission(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'consulta')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden()
            ->assertSee('Sem permissão');
    }

    /**
     * A página de erro e pública. Mensagem de erro interno costuma carregar
     * caminho de arquivo, nome de tabela ou trecho de consulta.
     */
    public function test_the_error_screen_does_not_leak_internals(): void
    {
        $body = (string) $this->get('/endereco-que-nao-existe')->getContent();

        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
    }

    /**
     * O motivo de existirem: se alguém voltar a escrever utilitarias do
     * Tailwind aqui, a dependência do cache de views volta junto.
     */
    public function test_the_error_screens_use_the_system_classes_and_not_utilities(): void
    {
        foreach (['errors/_page.blade.php', 'errors/4xx.blade.php', 'errors/5xx.blade.php'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\b(min-h-screen|max-w-\w+|text-gray-\d+|antialiased|sm:[a-z-]+)\b/',
                $source,
                "A página {$view} voltou a usar utilitarias do Tailwind."
            );
        }
    }

    /**
     * A view de paginação foi publicada para dentro de `resources`, que o
     * Tailwind varre. Se ela sumir, a paginação perde o estilo no próximo build
     * feito com o cache limpo - e so se descobre abrindo a segunda página de
     * uma lista.
     */
    public function test_the_pagination_view_lives_inside_resources(): void
    {
        $this->assertFileExists(resource_path('views/vendor/pagination/tailwind.blade.php'));
    }

    /**
     * O `app.css` não pode voltar a varrer o cache de views nem `vendor`: o
     * primeiro torna o build dependente do estado do cache, e o segundo e
     * ignorado em silêncio porque `/vendor` esta no `.gitignore`.
     */
    public function test_the_stylesheet_does_not_scan_the_view_cache_or_vendor(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match_all("/@source\s+'([^']+)'/", $css, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $source) {
            $this->assertStringNotContainsString('storage/framework/views', $source);
            $this->assertStringNotContainsString('vendor/', $source);
        }
    }
}
