<?php

namespace App\Services\ConversationAutomation;

use App\Enums\PermissionResponseClassification;
use App\Services\SystemSettingService;

/**
 * Classificador de reação.
 *
 * Existe separado de `PermissionResponseClassifier` por um motivo concreto:
 * aquele classificador transforma emoji em separador antes de comparar
 * qualquer coisa, e faz isso de propósito, para que "não 😡 quero" não vire
 * "nãoquero". Uma reação é só o emoji — passar por lá devolveria texto vazio, e
 * texto vazio é `Ambiguous`.
 *
 * O que sai daqui é o mesmo vocabulário do outro: a decisão volta como
 * `PermissionResponseClassification`, para que o motor da 9A trate um 👍 pelo
 * mesmo caminho por onde trata um "sim" escrito.
 *
 * Opt-out não está aqui, e a ausência é deliberada. Descadastro é irreversível
 * do lado de quem o sofre — quem quiser sair continua escrevendo "sair", que
 * `PermissionResponseClassifier` reconhece com prioridade absoluta. Um toque
 * acidental num emoji não pode tirar ninguém da base.
 */
class ReactionClassifier
{
    /**
     * Seletores de variação e modificadores de tom de pele.
     *
     * 👍 e 👍🏽 são a mesma resposta, e ❤ e ❤️ são o mesmo coração — o segundo só
     * carrega o U+FE0F que pede desenho colorido. Sem tirar isso, a lista
     * configurada teria de repetir cada emoji cinco vezes, uma por tom, e ainda
     * erraria na sexta.
     */
    private const MODIFICADORES = [
        "\u{FE0E}", "\u{FE0F}",
        "\u{1F3FB}", "\u{1F3FC}", "\u{1F3FD}", "\u{1F3FE}", "\u{1F3FF}",
    ];

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return array{classification: PermissionResponseClassification, normalized: string, matched: ?string, reason: string}
     */
    public function classify(?string $emoji): array
    {
        $normalizado = $this->normalize((string) $emoji);

        if ($normalizado === '') {
            return $this->result(PermissionResponseClassification::Ambiguous, $normalizado, null, 'reacao_vazia');
        }

        $negativas = $this->reactions('conversation_automation.negative_reactions');
        $positivas = $this->reactions('conversation_automation.positive_reactions');

        /*
         | A negativa é conferida antes da positiva.
         |
         | É a mesma precedência de `PermissionResponseClassifier`, e pelo mesmo
         | motivo: se alguém acrescentar um emoji nas duas listas, o erro tem de
         | cair para o lado de não presumir consentimento.
         */
        if (in_array($normalizado, $negativas, true)) {
            return $this->result(PermissionResponseClassification::PermissionNo, $normalizado, $normalizado, 'reacao_negativa');
        }

        if (in_array($normalizado, $positivas, true)) {
            return $this->result(PermissionResponseClassification::PermissionYes, $normalizado, $normalizado, 'reacao_positiva');
        }

        /*
         | Sequências compostas caem para o emoji base.
         |
         | 🙅‍♀️ é 🙅 mais um ligador invisível mais o símbolo de gênero, e ❤️‍🔥 é
         | um coração mais fogo. Configurar cada combinação é impossível: o
         | WhatsApp acrescenta emoji a cada versão, e a lista nasceria velha.
         | Comparar o primeiro ponto de código resolve a família inteira de uma
         | vez, e continua conferindo a negativa antes da positiva.
         */
        $base = $this->primeiroPontoDeCodigo($normalizado);

        if ($base !== $normalizado && $base !== '') {
            if (in_array($base, $negativas, true)) {
                return $this->result(PermissionResponseClassification::PermissionNo, $normalizado, $base, 'reacao_negativa_composta');
            }

            if (in_array($base, $positivas, true)) {
                return $this->result(PermissionResponseClassification::PermissionYes, $normalizado, $base, 'reacao_positiva_composta');
            }
        }

        return $this->result(PermissionResponseClassification::Ambiguous, $normalizado, null, 'reacao_sem_correspondencia');
    }

    /**
     * A reação é positiva?
     *
     * Atalho para quem só precisa da resposta binária — a campanha por
     * palavra-chave, que não tem estágio nem recusa a registrar.
     */
    public function isPositive(?string $emoji): bool
    {
        return $this->classify($emoji)['classification'] === PermissionResponseClassification::PermissionYes;
    }

    /**
     * A reação é negativa?
     *
     * Negativa quer dizer "não quero", e não "me tire da base": nenhuma reação
     * produz opt-out. Ver o cabeçalho desta classe.
     */
    public function isNegative(?string $emoji): bool
    {
        return $this->classify($emoji)['classification'] === PermissionResponseClassification::PermissionNo;
    }

    /**
     * A reação quer dizer alguma coisa?
     *
     * Serve a quem precisa separar "respondeu" de "reagiu com um emoji
     * qualquer" sem se importar com o que ela respondeu — a campanha, que
     * inscreve nos dois casos e só depois decide se abre a pesquisa.
     */
    public function isAnswer(?string $emoji): bool
    {
        return $this->classify($emoji)['classification'] !== PermissionResponseClassification::Ambiguous;
    }

    /**
     * Tira espaço, seletor de variação e tom de pele. O resto fica intacto:
     * emoji não tem caixa nem acento para normalizar.
     */
    public function normalize(string $emoji): string
    {
        return str_replace(self::MODIFICADORES, '', trim($emoji));
    }

    /**
     * @return array<int, string>
     */
    private function reactions(string $key): array
    {
        $raw = (string) $this->settings->get($key, '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function primeiroPontoDeCodigo(string $normalizado): string
    {
        return mb_substr($normalizado, 0, 1);
    }

    /**
     * @return array{classification: PermissionResponseClassification, normalized: string, matched: ?string, reason: string}
     */
    private function result(
        PermissionResponseClassification $classification,
        string $normalized,
        ?string $matched,
        string $reason,
    ): array {
        return compact('classification', 'normalized', 'matched', 'reason');
    }
}
