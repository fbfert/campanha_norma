<?php

namespace App\Services\KeywordCampaigns;

use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;

/**
 * A mensagem que vai ao ganhador junto com o cupom.
 *
 * O molde fica na campanha, com `{codigo}` no lugar do código. O código entra
 * só no momento do envio, na variável que vai para o provedor — nunca no
 * banco, nunca no histórico, nunca no log. É o mesmo desenho que o resto do
 * módulo já usa para o cupom, e é o que permite guardar a mensagem sem guardar
 * o prêmio junto.
 */
class CouponMessage
{
    /**
     * O texto que saía fixo do job antes de a mensagem ser configurável.
     *
     * Continua sendo o padrão para que campanha existente, cujo campo é nulo,
     * mande exatamente o que mandava antes.
     */
    public const PADRAO = 'Parabéns! Você foi sorteado. Seu código de acesso é: {codigo}';  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento

    public const CODIGO = 'codigo';

    public const NOME = 'nome';

    /**
     * Só estes, e de propósito.
     *
     * O catálogo geral de placeholders oferece cidade, estado, e-mail e país,
     * e quem se inscreve por palavra-chave nasce sem nenhum deles: a campanha
     * só tem nome e telefone. Oferecer um campo que sempre chega vazio é
     * oferecer uma frase quebrada para o ganhador.
     *
     * @var list<string>
     */
    public const ACEITOS = [self::CODIGO, self::NOME];

    public function texto(KeywordCampaign $campaign): string
    {
        $texto = trim((string) ($campaign->coupon_text ?? ''));

        return $texto !== '' ? $texto : self::PADRAO;
    }

    /**
     * Os erros que impedem o texto de ser salvo.
     *
     * `{codigo}` é obrigatório, e essa é a trava que importa: uma mensagem de
     * prêmio sem o código é um "parabéns, você ganhou" que não entrega nada, e
     * o ganhador não tem como saber que faltou alguma coisa. O cupom fica
     * marcado como entregue e o erro só aparece quando a pessoa reclama.
     *
     * @return list<string>
     */
    public function erros(string $texto): array
    {
        $erros = [];
        $encontrados = $this->placeholders($texto);

        if (! in_array(self::CODIGO, $encontrados, true)) {
            $erros[] = 'A mensagem precisa conter {codigo}, senão o ganhador recebe os parabéns sem o prêmio.';  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
        }

        foreach ($encontrados as $nome) {
            if (! in_array($nome, self::ACEITOS, true)) {
                $erros[] = "A mensagem do cupom não conhece {{$nome}}. Aqui valem apenas {codigo} e {nome}.";  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            }
        }

        if (preg_match('/\{[^}]*$/u', $texto) || str_contains($texto, '{{') || str_contains($texto, '}}')) {
            $erros[] = 'Há uma chave aberta e não fechada na mensagem.';
        }

        return array_values(array_unique($erros));
    }

    /**
     * Quem seria saudado pelo nome e não tem nome nenhum.
     *
     * Chamado antes de enfileirar, e não durante o envio: descobrir no meio da
     * fila que um ganhador não tem nome deixaria a escolha entre mandar
     * "Parabéns, !" e não mandar nada, e as duas são ruins depois que metade
     * do lote já saiu. Antes de enfileirar ainda dá para trocar o texto.
     *
     * @param  iterable<KeywordCampaignParticipation>  $ganhadores
     * @return list<string>
     */
    public function ganhadoresSemNome(string $texto, iterable $ganhadores): array
    {
        if (! in_array(self::NOME, $this->placeholders($texto), true)) {
            return [];
        }

        $semNome = [];

        foreach ($ganhadores as $ganhador) {
            if (trim((string) $ganhador->displayName()) === '') {
                $semNome[] = (string) ($ganhador->contact?->phone_normalized ?? "inscrição {$ganhador->id}");
            }
        }

        return $semNome;
    }

    /**
     * O texto pronto para o provedor, com o código dentro.
     *
     * O retorno desta função não pode ser gravado em lugar nenhum.
     */
    public function montar(string $texto, string $codigo, ?string $nome = null): string
    {
        return str_replace(
            ['{'.self::CODIGO.'}', '{'.self::NOME.'}'],
            [$codigo, trim((string) $nome)],
            $texto,
        );
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $texto): array
    {
        preg_match_all('/\{([^{}]*)\}/u', $texto, $achados);

        return array_values(array_unique(array_map(
            static fn (string $nome): string => trim($nome),
            $achados[1],
        )));
    }
}
