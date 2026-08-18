<?php

namespace App\Services\KeywordCampaigns;

use App\Models\ConversationMessage;
use App\Services\Text\WholeWordMatcher;

/**
 * Decide se uma mensagem casa com a palavra-chave de uma campanha.
 *
 * Puro: recebe texto e lista, devolve qual palavra casou. Sem escrita, sem
 * envio, sem IA e sem banco no caminho da decisão — ele roda em toda mensagem
 * recebida.
 */
class KeywordMatcherService
{
    public function __construct(private readonly WholeWordMatcher $matcher) {}

    /**
     * Qual palavra da lista casou, ou nulo.
     *
     * Casa com QUALQUER palavra da lista, não com todas: quem divulga três
     * palavras quer que as três funcionem, não que a pessoa escreva as três.
     *
     * @param  iterable<string>  $keywords
     */
    public function match(?string $text, iterable $keywords): ?string
    {
        return $this->matcher->firstMatch($text, $keywords);
    }

    /**
     * O texto de uma mensagem que vale para o casamento.
     *
     * Deliberadamente `body`, e não `readableText()`.
     *
     * `readableText()` devolve a transcrição quando não há texto escrito, e é
     * assim que o atendimento de entrada lê áudio. A campanha não: inscrição é
     * um ato com consequência — entra numa lista, concorre a prêmio — e
     * transcrição automática erra. Uma inscrição criada por engano de
     * transcrição é indistinguível, no banco, de uma de verdade, e quem não se
     * inscreveu não tem como saber que está na lista.
     *
     * Se a divulgação for por rádio, isto é a primeira coisa a reconsiderar.
     */
    public function textoParaCasamento(ConversationMessage $message): ?string
    {
        return $message->body;
    }

    /**
     * A mensagem é só a palavra-chave, e nada mais?
     *
     * É o que separa inscrição de resposta. Numa conversa que já tem pesquisa
     * em andamento, "batata" não é opinião sobre nada — mas "falta saúde no
     * bairro", numa campanha cuja palavra é `saude`, é a resposta da pessoa e
     * precisa chegar ao motor.
     *
     * Aconteceu de verdade em 17/08/2026: "batata" foi gravada como resposta à
     * pergunta sobre o problema mais urgente da cidade, e a pesquisa avançou
     * para a pergunta seguinte com esse dado dentro.
     *
     * @param  iterable<string>  $keywords
     */
    public function mensagemEhSoAPalavra(?string $text, iterable $keywords): bool
    {
        $normalizado = $this->matcher->normalize($text);

        if ($normalizado === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($normalizado === $this->matcher->normalize($keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Palavras da mensagem que quase casaram.
     *
     * Não decide nada. Existe para que a decisão de relaxar o casamento numa
     * etapa futura seja tomada com número real em vez de chute: distância de
     * edição aproxima palavra errada de palavra certa, mas também aproxima
     * duas palavras legítimas e diferentes, e calibrar o limiar sem dado da
     * primeira campanha é adivinhação.
     *
     * Palavra que casou de verdade não é quase-casamento, e por isso sai da
     * lista.
     *
     * @param  iterable<string>  $keywords
     * @return list<array{word: string, keyword: string, distance: int}>
     */
    public function quaseCasamentos(?string $text, iterable $keywords): array
    {
        $palavras = $this->matcher->words($text);

        if ($palavras === []) {
            return [];
        }

        $normalizadas = [];
        foreach ($keywords as $keyword) {
            $normalizada = $this->matcher->normalize($keyword);

            // Palavra-chave composta não entra: a comparação aqui é palavra a
            // palavra, e medir distância entre uma palavra e uma frase produz
            // ruído, não informação.
            if ($normalizada !== '' && ! str_contains($normalizada, ' ')) {
                $normalizadas[$normalizada] = $keyword;
            }
        }

        $achados = [];

        foreach (array_unique($palavras) as $palavra) {
            foreach ($normalizadas as $normalizada => $original) {
                if ($palavra === $normalizada) {
                    continue;
                }

                // `levenshtein` trabalha em bytes, e o texto já veio sem acento
                // e sem caractere fora de ASCII: aqui byte e letra são a mesma
                // coisa.
                if (levenshtein($palavra, (string) $normalizada) === 1) {
                    $achados[] = [
                        'word' => $palavra,
                        'keyword' => $original,
                        'distance' => 1,
                    ];
                }
            }
        }

        return $achados;
    }
}
