<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paginas de erro proprias.
 *
 * Existem por dois motivos. O visivel: quem erra um endereco ou esbarra numa
 * permissao passa a ver algo que parece o sistema, em portugues, com caminho de
 * volta.
 *
 * O invisivel importa mais. A pagina padrao do Laravel e escrita em utilitarias
 * do Tailwind que nenhuma tela deste sistema usa. Elas so chegavam ao CSS
 * porque o `app.css` mandava varrer `storage/framework/views`, o cache de views
 * compiladas. Isso fazia o CSS gerado depender do estado do cache: um
 * `view:clear` antes do build produzia um arquivo diferente, e a pagina de erro
 * saia sem estilo. Com estas paginas usando as classes do proprio sistema, a
 * varredura do cache deixou de ser necessaria e o build virou deterministico.
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
            ->assertSee('Pagina nao encontrada');
    }

    public function test_a_forbidden_screen_explains_that_it_is_permission(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'consulta')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden()
            ->assertSee('Sem permissao');
    }

    /**
     * A pagina de erro e publica. Mensagem de erro interno costuma carregar
     * caminho de arquivo, nome de tabela ou trecho de consulta.
     */
    public function test_the_error_screen_does_not_leak_internals(): void
    {
        $body = (string) $this->get('/endereco-que-nao-existe')->getContent();

        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
    }

    /**
     * O motivo de existirem: se alguem voltar a escrever utilitarias do
     * Tailwind aqui, a dependencia do cache de views volta junto.
     */
    public function test_the_error_screens_use_the_system_classes_and_not_utilities(): void
    {
        foreach (['errors/_page.blade.php', 'errors/4xx.blade.php', 'errors/5xx.blade.php'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\b(min-h-screen|max-w-\w+|text-gray-\d+|antialiased|sm:[a-z-]+)\b/',
                $source,
                "A pagina {$view} voltou a usar utilitarias do Tailwind."
            );
        }
    }

    /**
     * A view de paginacao foi publicada para dentro de `resources`, que o
     * Tailwind varre. Se ela sumir, a paginacao perde o estilo no proximo build
     * feito com o cache limpo - e so se descobre abrindo a segunda pagina de
     * uma lista.
     */
    public function test_the_pagination_view_lives_inside_resources(): void
    {
        $this->assertFileExists(resource_path('views/vendor/pagination/tailwind.blade.php'));
    }

    /**
     * O `app.css` nao pode voltar a varrer o cache de views nem `vendor`: o
     * primeiro torna o build dependente do estado do cache, e o segundo e
     * ignorado em silencio porque `/vendor` esta no `.gitignore`.
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
