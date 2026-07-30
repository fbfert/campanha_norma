<?php

namespace Tests\Support;

/**
 * Leitor do dicionário de acentuação e extrator de texto humano.
 *
 * O ponto delicado não é achar palavra sem acento: é não confundir prosa com
 * identificador. Numa etiqueta de tela, a forma sem acento está errada; a mesma
 * sequência como chave de configuração, slug ou trecho de rota está certa e não
 * pode ser tocada. Por isso tudo que parece código é mascarado antes da
 * conferência.
 */
final class Ortografia
{
    public const MARCA_IGNORAR = 'ortografia:ignorar';

    private const DICIONARIO = 'resources/ortografia/acentuacao-pt-br.json';

    /** Diretorios cujo texto e escrito por gente e lido por gente. */
    private const DIRETORIOS = ['app', 'config', 'database', 'docs', 'lang', 'resources', 'routes', 'tests'];

    /** Atributos HTML cujo valor e texto, não identificador. */
    private const ATRIBUTOS_DE_TEXTO = ['title', 'placeholder', 'aria-label', 'alt', 'label', 'summary'];

    /** @var array{correcoes: array<string,string>, permitidas: list<string>, sufixos_suspeitos: list<string>}|null */ // ortografia:ignorar - chaves do JSON
    private static ?array $dicionario = null;

    /**
     * @return array{correcoes: array<string,string>, permitidas: list<string>, sufixos_suspeitos: list<string>} // ortografia:ignorar - chaves do JSON
     */
    public static function dicionario(): array
    {
        if (self::$dicionario === null) {
            $bruto = file_get_contents(base_path(self::DICIONARIO));
            self::$dicionario = json_decode((string) $bruto, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$dicionario;
    }

    /**
     * Arquivos sujeitos a regra.
     *
     * @return list<string> caminhos relativos a raiz do projeto
     */
    public static function arquivos(): array
    {
        $encontrados = [];

        // Documentação da raiz: README e as instruções carregadas por agentes.
        foreach (glob(base_path('*.md')) ?: [] as $solto) {
            $encontrados[] = basename($solto);
        }

        foreach (self::DIRETORIOS as $diretorio) {
            $raiz = base_path($diretorio);
            if (! is_dir($raiz)) {
                continue;
            }

            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $arquivo) {
                /** @var \SplFileInfo $arquivo */
                $caminho = $arquivo->getPathname();

                if (! $arquivo->isFile() || ! in_array($arquivo->getExtension(), ['php', 'md'], true)) {
                    continue;
                }

                // O próprio dicionario guarda as formas erradas de propósito.
                if (str_contains($caminho, 'ortografia')) {
                    continue;
                }

                $encontrados[] = ltrim(str_replace(base_path(), '', $caminho), DIRECTORY_SEPARATOR);
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    /**
     * Devolve o conteúdo com tudo que não e prosa substituído por espaço, para
     * que a conferência enxergue apenas texto escrito para pessoas.
     */
    public static function textoHumano(string $conteudo, string $caminho): string
    {
        // Linha marcada sai inteira: e uma exceção declarada por quem escreveu.
        $conteudo = implode("\n", array_map(
            fn (string $linha): string => str_contains($linha, self::MARCA_IGNORAR) ? '' : $linha,
            explode("\n", $conteudo)
        ));

        return str_ends_with($caminho, '.md')
            ? self::mascararMarkdown($conteudo)
            : self::mascararPhp($conteudo, str_ends_with($caminho, '.blade.php'));
    }

    private static function mascararMarkdown(string $texto): string
    {
        $padroes = [
            '/^```.*?^```/ms',   // bloco de código
            '/`[^`\n]*`/',       // código inline
            '/\]\([^)]*\)/',     // alvo de link
            '/https?:\/\/\S+/',  // URL solta
        ];

        return preg_replace($padroes, ' ', $texto) ?? $texto;
    }

    private static function mascararPhp(string $texto, bool $blade): string
    {
        if ($blade) {
            $texto = self::mascararTags($texto);
        }

        $padroes = [
            '/`[^`\n]*`/',                                                                 // código entre crases no comentário
            '/^\s*(namespace|use|declare)\s+[^;]+;/m',                                     // imports
            '/\b(case|const|function|class|enum|trait|interface|extends|implements)\s+[A-Za-z_]\w*/', // declarações
            '/\$[A-Za-z_]\w*/',                                                            // variáveis
            '/->\s*[A-Za-z_]\w*/',                                                         // membros
            '/::\s*[A-Za-z_]\w*/',                                                         // constantes de classe
        ];

        $texto = preg_replace($padroes, ' ', $texto) ?? $texto;

        // Strings que são chave, slug, rota ou caminho.
        return preg_replace_callback(
            '/([\'"])((?:\\\\.|(?!\1).)*)\1/',
            fn (array $c): string => self::pareceIdentificador($c[2]) ? ' ' : $c[0],
            $texto
        ) ?? $texto;
    }

    /** Fora dos atributos de texto, o conteúdo de uma tag e estrutura. */
    private static function mascararTags(string $texto): string
    {
        return preg_replace_callback('/<[^>]*>/', function (array $c): string {
            $guardados = [];

            preg_replace_callback(
                '/([\w:@\-.]+)\s*=\s*([\'"])((?:\\\\.|(?!\2).)*)\2/',
                function (array $a) use (&$guardados): string {
                    if (in_array(strtolower($a[1]), self::ATRIBUTOS_DE_TEXTO, true) && ! self::pareceIdentificador($a[3])) {
                        $guardados[] = $a[3];
                    }

                    return '';
                },
                $c[0]
            );

            return ' '.implode(' ', $guardados).' ';
        }, $texto) ?? $texto;
    }

    private static function pareceIdentificador(string $valor): bool
    {
        if ($valor === '' || str_contains($valor, '/') || str_contains($valor, '::')) {
            return true;
        }

        // tudo minusculo, sem espaço: chave, slug ou nome de rota
        if (preg_match('/^[a-z0-9_.\-:#@\[\]{}]+$/', $valor) === 1) {
            return true;
        }

        return (bool) preg_match('/^(#|\.|http|mailto:|\{\{|\$)/', $valor);
    }

    /**
     * Palavras escritas sem o acento que deveriam ter.
     *
     * @return list<array{linha: int, palavra: string, correta: string, motivo: string}>
     */
    public static function violacoes(string $caminhoRelativo): array
    {
        $dic = self::dicionario();
        $conteudo = (string) file_get_contents(base_path($caminhoRelativo));
        $humano = self::textoHumano($conteudo, $caminhoRelativo);

        $permitidas = array_flip($dic['permitidas']);
        $sufixos = $dic['sufixos_suspeitos'];
        $achados = [];

        foreach (explode("\n", $humano) as $indice => $linha) {
            preg_match_all('/[A-Za-zÀ-ÿ]{3,}/u', $linha, $casos);

            foreach ($casos[0] as $palavra) {
                $minuscula = mb_strtolower($palavra, 'UTF-8');

                // Já tem acento: nada a conferir.
                if (preg_match('/[À-ÿ]/u', $palavra) === 1) {
                    continue;
                }

                if (isset($dic['correcoes'][$minuscula])) {
                    $achados[] = [
                        'linha' => $indice + 1,
                        'palavra' => $palavra,
                        'correta' => $dic['correcoes'][$minuscula],
                        'motivo' => 'palavra conhecida sem acento',
                    ];

                    continue;
                }

                if (isset($permitidas[$minuscula])) {
                    continue;
                }

                foreach ($sufixos as $sufixo) {
                    if (str_ends_with($minuscula, $sufixo) && mb_strlen($minuscula) > mb_strlen($sufixo)) {
                        $achados[] = [
                            'linha' => $indice + 1,
                            'palavra' => $palavra,
                            'correta' => '(desconhecida)',
                            'motivo' => "termina em -{$sufixo}, que quase sempre pede acento",
                        ];

                        break;
                    }
                }
            }
        }

        return $achados;
    }
}
