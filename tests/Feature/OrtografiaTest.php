<?php

namespace Tests\Feature;

use Tests\Support\Ortografia;
use Tests\TestCase;

/**
 * A acentuação do sistema é verificada, não combinada.
 *
 * Uma convenção que ninguém confere apodrece: basta um arquivo novo escrito às
 * pressas para o sistema voltar a misturar as duas grafias da mesma palavra.
 * Este teste falha quando isso acontece, e diz exatamente onde.
 *
 * Ver docs/ortografia.md.
 */
class OrtografiaTest extends TestCase
{
    public function test_o_texto_do_sistema_esta_acentuado(): void
    {
        $problemas = [];

        foreach (Ortografia::arquivos() as $arquivo) {
            foreach (Ortografia::violacoes($arquivo) as $v) {
                $problemas[] = sprintf(
                    '%s:%d  %s -> %s  (%s)',
                    $arquivo,
                    $v['linha'],
                    $v['palavra'],
                    $v['correta'],
                    $v['motivo']
                );
            }
        }

        $this->assertSame([], $problemas, sprintf(
            "%d palavra(s) sem a acentuação correta.\n\n%s\n\n".
            "Como resolver:\n".
            "  - se a palavra precisa de acento, escreva com acento;\n".
            "  - se ela esta certa sem acento, acrescente em 'permitidas' no dicionario;\n".
            "  - se é um caso deliberado (ex.: saída de função que remove acento),\n".
            "    marque a linha com '%s' e explique o porquê.\n".
            'Dicionario: resources/ortografia/acentuacao-pt-br.json',
            count($problemas),
            implode("\n", array_slice($problemas, 0, 40)),
            Ortografia::MARCA_IGNORAR
        ));
    }

    /**
     * Guarda contra erro de digitação no próprio dicionário: tirar o acento da
     * forma correta tem de devolver exatamente a forma errada. Foi assim que a
     * primeira versão gerou "urência", por cortar cinco letras em vez de seis.
     */
    public function test_o_dicionario_e_internamente_consistente(): void
    {
        $quebrados = [];

        foreach (Ortografia::dicionario()['correcoes'] as $errada => $certa) {
            $semAcento = strtr(
                (string) iconv('UTF-8', 'ASCII//TRANSLIT', $certa),
                ['\'' => '', '`' => '', '^' => '', '~' => '', '"' => '']
            );

            if (strtolower($semAcento) !== $errada) {
                $quebrados[] = "{$errada} -> {$certa} (sem acento vira '{$semAcento}')";
            }
        }

        $this->assertSame([], $quebrados, "Entradas inconsistentes no dicionario:\n".implode("\n", $quebrados));
    }

    public function test_o_dicionario_nao_corrige_e_permite_a_mesma_palavra(): void
    {
        $dic = Ortografia::dicionario();
        $conflito = array_intersect(array_keys($dic['correcoes']), $dic['permitidas']);

        $this->assertSame([], array_values($conflito), 'Palavra listada como erro e como permitida ao mesmo tempo.');
    }
}
