<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Breadcrumbs;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Padrões de interface.
 *
 * As regras estão escritas em `docs/padroes-de-interface.md`. Este arquivo e o
 * que faz elas valerem — regra que só existe em documento é combinado, e
 * combinado se perde.
 *
 * Nenhuma destas verificações e hipotética: todas nasceram de um erro que
 * aconteceu neste sistema e que ninguém percebeu na hora.
 */
class PadraoDeInterfaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function views(): array
    {
        $arquivos = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterador as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.blade.php')) {
                $caminho = $arquivo->getPathname();

                // A paginação vem do Laravel escrita em utilitárias do Tailwind.
                // Ela e cópia de arquivo de terceiro, e não tela deste sistema.
                if (! str_contains($caminho, '/vendor/pagination/')) {
                    $arquivos[] = $caminho;
                }
            }
        }

        sort($arquivos);

        return $arquivos;
    }

    private function relativo(string $caminho): string
    {
        return str_replace(base_path().'/', '', $caminho);
    }

    // =====================================================================
    // Caminho de migalhas
    // =====================================================================

    /**
     * Toda trilha declarada por uma tela precisa existir no mapa.
     *
     * Sem esta verificação, treze telas ficaram com a trilha muda — entre elas
     * os sete relatórios da Etapa 9E. A trilha continuava aparecendo, apenas
     * sem link, que e o tipo de falha que ninguém abre um chamado para relatar.
     */
    public function test_toda_trilha_usada_por_uma_tela_existe_no_mapa(): void
    {
        $mapa = array_map($this->semAcento(...), array_keys(Breadcrumbs::todas()));
        $faltando = [];

        foreach ($this->trilhasDeclaradas() as $arquivo => $trilhas) {
            foreach ($trilhas as $trilha) {
                if (! in_array($this->semAcento($trilha), $mapa, true)) {
                    $faltando[] = $this->relativo($arquivo).'  =>  "'.$trilha.'"';
                }
            }
        }

        $this->assertSame([], $faltando, "Trilhas sem entrada em App\\Support\\Breadcrumbs:\n".implode("\n", $faltando));
    }

    /**
     * Rota inexistente no mapa derrubaria a página inteira, porque as migalhas
     * aparecem em todas elas.
     */
    public function test_toda_rota_citada_no_mapa_existe(): void
    {
        foreach (Breadcrumbs::todas() as $trilha => $rotas) {
            foreach ($rotas as $rota) {
                if ($rota !== null) {
                    $this->assertNotNull(
                        Route::getRoutes()->getByName($rota),
                        "A trilha \"{$trilha}\" aponta para a rota '{$rota}', que não existe."
                    );
                }
            }
        }
    }

    /**
     * O mapa e posicional: a terceira posição e o terceiro segmento. Um mapa
     * mais curto que a trilha deixa os últimos segmentos sem destino sem avisar.
     */
    public function test_o_mapa_tem_uma_posicao_para_cada_segmento(): void
    {
        foreach (Breadcrumbs::todas() as $trilha => $rotas) {
            $this->assertCount(
                count(explode('/', $trilha)),
                $rotas,
                "A trilha \"{$trilha}\" tem ".count(explode('/', $trilha)).' segmentos e '.count($rotas).' posições no mapa.'
            );
        }
    }

    /**
     * A página atual nunca tem link. Link para onde já se esta e ruído.
     */
    public function test_o_ultimo_segmento_nunca_tem_link(): void
    {
        foreach (Breadcrumbs::todas() as $trilha => $rotas) {
            $this->assertNull(
                end($rotas),
                "A trilha \"{$trilha}\" liga o último segmento, que e a própria página."
            );
        }
    }

    /**
     * Trilha sem entrada não pode ficar muda: o primeiro segmento continua
     * levando ao início. E o link que mais importa, e faz uma tela nova nascer
     * utilizável antes de alguém escrever a entrada dela.
     */
    public function test_trilha_desconhecida_ainda_leva_ao_inicio(): void
    {
        $migalhas = Breadcrumbs::for('Inicio / Tela / Que / Ninguem / Mapeou');

        $this->assertSame('dashboard', $migalhas[0]['rota']);
        $this->assertNull($migalhas[4]['rota']);
    }

    /**
     * Esta e a interação que já quebrou de verdade: uma revisão de ortografia
     * acentuou `Início` nas telas e não nas chaves do mapa, e duas telas
     * perderam o link em silêncio.
     */
    public function test_a_busca_no_mapa_ignora_acento(): void
    {
        $comAcento = Breadcrumbs::for('Início / Contatos');
        $semAcento = Breadcrumbs::for('Inicio / Contatos');

        $this->assertSame($semAcento[0]['rota'], $comAcento[0]['rota']);
        $this->assertSame('dashboard', $comAcento[0]['rota']);
    }

    public function test_a_trilha_aparece_com_link_na_tela(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'administrador')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.contacts.index'))
            ->assertOk()
            ->assertSee('href="'.route('dashboard').'"', false);
    }

    // =====================================================================
    // Visual
    // =====================================================================

    /**
     * Cor não se escreve na tela.
     *
     * A exceção são os campos em que a cor e o próprio dado — o seletor de cor
     * de etiqueta e de tema — onde o hexadecimal e valor inicial ou exemplo.
     */
    public function test_nenhuma_tela_escreve_cor_a_mao(): void
    {
        $violacoes = [];

        foreach ($this->views() as $arquivo) {
            foreach (file($arquivo, FILE_IGNORE_NEW_LINES) ?: [] as $numero => $linha) {
                if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $linha) !== 1) {
                    continue;
                }

                // Campo em que a cor e o próprio dado — o seletor de cor de
                // etiqueta e de tema. Ali o hexadecimal e valor inicial ou
                // exemplo, e não estilo da interface.
                if (preg_match('/name="color"|placeholder="#/', $linha) === 1) {
                    continue;
                }

                $violacoes[] = $this->relativo($arquivo).':'.($numero + 1).'  '.trim($linha);
            }
        }

        $this->assertSame([], $violacoes, "Cor escrita direto na tela. Use um token de resources/css/app.css:\n".implode("\n", $violacoes));
    }

    /**
     * Toda cor do sistema nasce em `:root`.
     *
     * Antes eram vinte e poucos hexadecimais espalhados pela folha, e trocar
     * uma cor era procurar todas as ocorrências e torcer para não esquecer
     * nenhuma.
     */
    public function test_toda_cor_do_css_esta_declarada_no_root(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match('/:root\s*\{.*?\n\}/s', $css, $root, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($root, 'O bloco :root sumiu do app.css.');

        $inicio = $root[0][1];
        $fim = $inicio + strlen($root[0][0]);
        $foraDoRoot = substr($css, 0, $inicio).substr($css, $fim);

        preg_match_all('/^.*#[0-9a-fA-F]{3,8}\b.*$/m', $foraDoRoot, $achados);

        $this->assertSame(
            [],
            $achados[0],
            "Cor fora do :root em app.css. Declare um token:\n".implode("\n", array_map('trim', $achados[0]))
        );
    }

    /**
     * Ícone que não existe no sprite não desenha nada — e um `<use>` apontando
     * para o vazio não gera erro em lugar nenhum.
     */
    public function test_todo_icone_usado_existe_no_sprite(): void
    {
        $sprite = (string) file_get_contents(resource_path('views/components/layouts/partials/icons.blade.php'));
        $faltando = [];

        foreach ($this->views() as $arquivo) {
            preg_match_all('/<x-icon\s+name="([a-z-]+)"/', (string) file_get_contents($arquivo), $usos);

            foreach (array_unique($usos[1]) as $nome) {
                if (! str_contains($sprite, '<g id="i-'.$nome.'">')) {
                    $faltando[] = $this->relativo($arquivo).'  =>  '.$nome;
                }
            }
        }

        $this->assertSame([], $faltando, "Ícone usado que não existe no sprite:\n".implode("\n", $faltando));
    }

    /**
     * Estilo mora na folha, e não dentro da tela. Um `<style>` numa view escapa
     * dos tokens e do build, e some do alcance de qualquer ajuste global.
     */
    public function test_nenhuma_tela_traz_bloco_de_estilo_proprio(): void
    {
        $violacoes = [];

        foreach ($this->views() as $arquivo) {
            if (str_contains((string) file_get_contents($arquivo), '<style')) {
                $violacoes[] = $this->relativo($arquivo);
            }
        }

        $this->assertSame([], $violacoes, "Bloco <style> dentro de uma tela:\n".implode("\n", $violacoes));
    }

    /**
     * O aviso de erro de formulário aparece uma vez só.
     *
     * O layout já mostra os erros por `components/flash`. Seis telas repetiam o
     * bloco por conta própria, e quem errava um campo lia a mesma frase duas
     * vezes — foi assim que a falha de envio de PDF chegou relatada em
     * duplicata.
     */
    public function test_o_aviso_de_erro_aparece_uma_vez_so(): void
    {
        $violacoes = [];

        foreach ($this->views() as $arquivo) {
            if (str_ends_with($arquivo, 'components/flash.blade.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($arquivo), '$errors->any()')) {
                $violacoes[] = $this->relativo($arquivo);
            }
        }

        $this->assertSame(
            [],
            $violacoes,
            "Tela repetindo a lista de erros que o layout já mostra:\n".implode("\n", $violacoes)
        );
    }

    /**
     * O sistema roda em servidor próprio e precisa abrir com a internet ruim.
     * Nenhuma tela pode depender de arquivo de fora.
     */
    public function test_nenhuma_tela_busca_arquivo_externo(): void
    {
        $violacoes = [];

        foreach ($this->views() as $arquivo) {
            $conteudo = (string) file_get_contents($arquivo);

            foreach (['cdn.', 'https://fonts.', 'unpkg.com', 'jsdelivr.net'] as $sinal) {
                if (str_contains($conteudo, $sinal)) {
                    $violacoes[] = $this->relativo($arquivo).'  =>  '.$sinal;
                }
            }
        }

        $this->assertSame([], $violacoes, "Tela dependendo de arquivo externo:\n".implode("\n", $violacoes));
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

    /**
     * Trilhas declaradas por cada tela.
     *
     * Algumas telas escolhem a trilha por condição (`$isCampaign ? 'A' : 'B'`).
     * Nesse caso valem os dois lados: os dois aparecem em produção.
     *
     * @return array<string, list<string>>
     */
    private function trilhasDeclaradas(): array
    {
        $encontradas = [];

        foreach ($this->views() as $arquivo) {
            // O próprio layout não declara trilha: ele as recebe.
            if (str_ends_with($arquivo, 'layouts/app.blade.php')) {
                continue;
            }

            preg_match_all('/breadcrumbs="([^"]*)"/', (string) file_get_contents($arquivo), $achados);

            foreach ($achados[1] as $valor) {
                $trilhas = str_contains($valor, "'")
                    ? (preg_match_all("/'([^']+)'/", $valor, $literais) ? $literais[1] : [])
                    : [$valor];

                foreach ($trilhas as $trilha) {
                    if (trim($trilha) !== '') {
                        $encontradas[$arquivo][] = trim($trilha);
                    }
                }
            }
        }

        return $encontradas;
    }

    private function semAcento(string $valor): string
    {
        return Str::lower(Str::ascii(trim($valor)));
    }
}
