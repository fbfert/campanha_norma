<?php

namespace App\Services\Text;

use Illuminate\Support\Str;

/**
 * Casamento por palavra ou frase inteira sobre texto normalizado.
 *
 * Esta regra já existia em `InboundAttendanceRouter`, e a razão dela está
 * registrada num defeito real: a palavra `denuncia` dentro da lista de opt-out
 * removia da base quem só queria fazer uma denúncia. Casar por pedaço de
 * palavra é o mesmo defeito com outro nome — `voto` não pode casar dentro de
 * `devoto`, e `sorte` não pode casar dentro de `sorteio`.
 *
 * Está aqui, e não duplicada em cada serviço que precisa dela, porque duas
 * cópias da mesma regra divergem na primeira correção feita em uma só. Não
 * depende de configuração nem de banco: roda em toda mensagem recebida.
 */
class WholeWordMatcher
{
    /**
     * Caixa, acento, pontuação e emoji fora. O texto original é preservado pelo
     * chamador — o que sai daqui serve para comparar, não para mostrar.
     */
    public function normalize(?string $value): string
    {
        $value = Str::lower(Str::ascii(trim((string) $value)));
        $value = preg_replace('/[^a-z0-9\s]/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * A agulha aparece no palheiro como palavra ou frase inteira?
     *
     * Os dois lados precisam já vir normalizados.
     */
    public function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|\s)'.preg_quote($needle, '/').'(?:$|\s)/', $haystack);
    }

    /**
     * A primeira expressão da lista que casar com o texto.
     *
     * Devolve a expressão na forma em que foi passada, e não a normalizada:
     * quem chama grava o que o operador cadastrou, não o resultado interno da
     * comparação.
     *
     * @param  iterable<string>  $needles
     */
    public function firstMatch(?string $text, iterable $needles): ?string
    {
        $haystack = $this->normalize($text);

        if ($haystack === '') {
            return null;
        }

        foreach ($needles as $needle) {
            if ($this->contains($haystack, $this->normalize($needle))) {
                return $needle;
            }
        }

        return null;
    }

    /**
     * As palavras do texto, já normalizadas.
     *
     * @return list<string>
     */
    public function words(?string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized)));
    }
}
