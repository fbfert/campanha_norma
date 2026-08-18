<?php

namespace App\Services\InboundAttendance;

use App\Models\ConversationMessage;
use App\Models\InboundAttendanceProfile;
use App\Services\SystemSettingService;
use App\Services\Text\WholeWordMatcher;

/**
 * Escolhe o perfil que vai atender a mensagem, pelo que ela diz.
 *
 * A comparação é a mesma do classificador de permissão: normaliza caixa,
 * acento, pontuação e emoji, e casa por palavra ou frase inteira. Casar por
 * pedaço de palavra é como a palavra `denuncia` acabou dentro da lista de
 * opt-out e passou a remover da base quem só queria fazer uma denúncia —
 * `nao` não pode casar dentro de `naopode`, e `voto` não pode casar dentro de
 * `devoto`.
 *
 * Nenhuma regra casou é o caso comum, não a exceção: ninguém escreve pensando
 * na nossa lista. Por isso o perfil de fallback é obrigatório, e este serviço
 * nunca devolve nulo quando existe um perfil ativo.
 */
class InboundAttendanceRouter
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly WholeWordMatcher $matcher,
    ) {}

    /**
     * Mensagem que não é de gente esperando resposta.
     *
     * Nem toda mensagem recebida é alguém falando com a gente. Operadora avisa
     * saldo, banco manda código, robô de recarga oferece serviço — e o
     * atendimento automático responderia a todos, apresentando uma pesquisa
     * eleitoral a um sistema que não lê. Na primeira execução real havia uma
     * dessas na fila: "Por aqui você pode recarregar um número Vivo".
     *
     * Devolve a expressão que casou, ou `null` quando é gente.
     */
    public function exclusionMatch(ConversationMessage $message): ?string
    {
        $text = $this->normalize($message->readableText());

        if ($text === '') {
            return null;
        }

        foreach ($this->exclusionExpressions() as $expression) {
            $needle = $this->normalize($expression);

            if ($needle !== '' && $this->contains($text, $needle)) {
                return $expression;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function exclusionExpressions(): array
    {
        return collect(preg_split('/[|\r\n]+/', (string) $this->settings->get('inbound_attendance.exclusion_expressions', '')) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{profile: ?InboundAttendanceProfile, matched: ?string}
     */
    public function route(ConversationMessage $message): array
    {
        $profiles = InboundAttendanceProfile::query()
            ->where('status', 'active')
            ->orderBy('match_priority')
            ->orderBy('id')
            ->get();

        if ($profiles->isEmpty()) {
            return ['profile' => null, 'matched' => null];
        }

        $text = $this->normalize($message->readableText());

        if ($text !== '') {
            foreach ($profiles as $profile) {
                foreach ($profile->matchExpressionList() as $expression) {
                    $needle = $this->normalize($expression);

                    if ($needle !== '' && $this->contains($text, $needle)) {
                        return ['profile' => $profile, 'matched' => $expression];
                    }
                }
            }
        }

        // O que sobrou. Mensagem sem texto — mídia, figurinha — também chega
        // aqui, e é justamente quem mais precisa de um destino: quem manda uma
        // foto não escreveu nada que uma expressão pudesse pegar.
        $fallback = $profiles->firstWhere('is_fallback', true);

        return ['profile' => $fallback, 'matched' => null];
    }

    /**
     * Caixa, acento, pontuação e emoji fora; o texto original é preservado.
     *
     * A regra passou a morar em `WholeWordMatcher` na Etapa 10, porque a
     * campanha por palavra-chave precisa exatamente da mesma comparação e duas
     * cópias divergiriam na primeira correção feita em uma só. O método
     * continua aqui porque as telas do atendimento de entrada o chamam.
     */
    public function normalize(string $value): string
    {
        return $this->matcher->normalize($value);
    }

    /**
     * Casa por palavra ou frase inteira, nunca por pedaço de palavra.
     */
    private function contains(string $haystack, string $needle): bool
    {
        return $this->matcher->contains($haystack, $needle);
    }
}
