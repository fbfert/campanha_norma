<?php

namespace App\Services\KeywordCampaigns;

use App\Services\Text\WholeWordMatcher;

/**
 * A lista de palavras, do que o operador digitou ao que o banco guarda.
 *
 * Normalizar na gravação, e não na comparação, é o que mantém barato o caminho
 * quente: a avaliação roda em toda mensagem recebida e não pode pagar por
 * normalizar a lista de novo a cada uma.
 */
class KeywordListNormalizer
{
    /**
     * Abaixo disto a palavra é curta demais para servir de gatilho.
     *
     * Não é limite: é o ponto em que a tela avisa. Três letras aparecem dentro
     * de conversa comum o tempo todo, e uma inscrição criada porque alguém
     * escreveu "sim" é uma pessoa numa lista onde ela não pediu para estar.
     */
    public const COMPRIMENTO_MINIMO_SEGURO = 4;

    /**
     * Palavras que aparecem em qualquer conversa.
     *
     * A lista é curta de propósito: ela avisa, não bloqueia, e uma lista longa
     * transforma o aviso em ruído que o operador aprende a ignorar.
     *
     * @var list<string>
     */
    private const COMUNS = [
        'oi', 'ola', 'bom', 'boa', 'dia', 'tarde', 'noite', 'sim', 'nao',
        'quero', 'obrigado', 'obrigada', 'por', 'favor', 'tudo', 'bem',
        'ok', 'certo', 'informacao', 'informacoes', 'ajuda', 'preco',
    ];

    public function __construct(private readonly WholeWordMatcher $matcher) {}

    /**
     * Converte o texto do formulário — uma palavra por linha — na lista
     * normalizada que vai para o banco.
     *
     * @return list<string>
     */
    public function normalizar(?string $texto): array
    {
        return collect(preg_split('/[|\r\n]+/', (string) $texto) ?: [])
            ->map(fn (string $item): string => $this->matcher->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * O texto do formulário a partir do que está gravado.
     */
    public function paraFormulario(?array $palavras): string
    {
        return implode("\n", $palavras ?? []);
    }

    /**
     * Avisos sobre a lista, para a tela mostrar.
     *
     * Avisos, e não erros: quem monta a campanha pode ter uma razão que o
     * sistema não conhece. O que não se pode é a pessoa descobrir o problema
     * pela enxurrada de inscrições erradas.
     *
     * @param  list<string>  $palavras
     * @return list<string>
     */
    public function avisos(array $palavras): array
    {
        $avisos = [];

        foreach ($palavras as $palavra) {
            $letras = mb_strlen($palavra);

            if ($letras < self::COMPRIMENTO_MINIMO_SEGURO) {
                $avisos[] = "A palavra \"{$palavra}\" tem {$letras} ".($letras === 1 ? 'letra' : 'letras')
                    .': palavra curta demais aparece dentro de conversa comum e inscreve gente por engano.';

                continue;
            }

            if (in_array($palavra, self::COMUNS, true)) {
                $avisos[] = "A palavra \"{$palavra}\" aparece em qualquer conversa: quem escrever isso por outro motivo entra na lista sem querer.";
            }
        }

        return $avisos;
    }
}
