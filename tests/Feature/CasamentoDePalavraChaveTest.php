<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Services\KeywordCampaigns\KeywordMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O casamento de palavra-chave, caso a caso.
 *
 * É determinístico e sem tolerância a erro de digitação, e isso é escolha, não
 * limitação: distância de edição aproxima palavra errada de palavra certa, mas
 * também aproxima duas palavras legítimas e diferentes. Alguns dos casos abaixo
 * documentam falso positivo aceito de propósito — estão aqui para que a decisão
 * apareça no teste em vez de ser descoberta em produção.
 */
class CasamentoDePalavraChaveTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): KeywordMatcherService
    {
        return app(KeywordMatcherService::class);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function casos(): array
    {
        return [
            'maiúscula casa' => ['SORTEIO CURSO', 'sorteio'],
            'minúscula casa' => ['sorteio curso', 'sorteio'],
            'palavra sozinha casa' => ['sorteio', 'sorteio'],
            'pontuação não atrapalha' => ['Sorteio!', 'sorteio'],
            'acento indevido casa' => ['sortéio', 'sorteio'],
            'no meio da frase casa' => ['quero participar do sorteio, por favor', 'sorteio'],
            'espaço extra não atrapalha' => ['   sorteio   ', 'sorteio'],
            'emoji junto não atrapalha' => ['sorteio 🎉', 'sorteio'],
            'quebra de linha não atrapalha' => ["quero\nsorteio\nagora", 'sorteio'],

            /*
             | Falso positivo aceito conscientemente.
             |
             | É palavra inteira ali, e o casamento não interpreta intenção. A
             | alternativa seria classificar sentido com IA, que erraria de um
             | jeito bem mais difícil de auditar num processo cuja única defesa
             | é ser auditável. Quem escreveu isso sai da lista pela invalidação
             | com motivo, feita por um humano.
             */
            'negativa também casa' => ['não quero saber de sorteio nenhum', 'sorteio'],

            'plural é outra palavra' => ['sorteios', null],
            'prefixo colado não casa' => ['assorteio', null],
            'palavra menor não casa' => ['sorte', null],
            'palavra maior não casa' => ['sorteiozinho', null],
            'hífen separa, e o pedaço não casa' => ['sorte-grande', null],
            'texto vazio não casa' => ['', null],
            'só espaço não casa' => ['     ', null],
            'só emoji não casa' => ['🎉🎉', null],
            'só pontuação não casa' => ['!!!???', null],
            'outra coisa não casa' => ['bom dia, tudo bem?', null],
        ];
    }

    #[DataProvider('casos')]
    public function test_tabela_de_casos(string $texto, ?string $esperado): void
    {
        $this->assertSame($esperado, $this->matcher()->match($texto, ['sorteio']));
    }

    public function test_texto_nulo_nao_casa(): void
    {
        $this->assertNull($this->matcher()->match(null, ['sorteio']));
    }

    public function test_lista_de_palavras_vazia_nao_casa(): void
    {
        $this->assertNull($this->matcher()->match('sorteio', []));
    }

    /**
     * Palavra vazia na lista não pode virar um casamento com tudo. Uma agulha
     * vazia num `preg_match` casaria com qualquer texto.
     */
    public function test_palavra_vazia_na_lista_nao_casa_com_tudo(): void
    {
        $this->assertNull($this->matcher()->match('qualquer coisa', ['', '   ']));
    }

    /**
     * Basta uma palavra da lista, não todas.
     */
    public function test_casa_com_qualquer_palavra_da_lista(): void
    {
        $palavras = ['sorteio', 'curso gratis', 'quero'];

        $this->assertSame('sorteio', $this->matcher()->match('quero o sorteio', $palavras));
        $this->assertSame('curso gratis', $this->matcher()->match('me manda o curso grátis', $palavras));
    }

    /**
     * A palavra devolvida é a que foi cadastrada, não a normalizada: é ela que
     * vai para a participação e para a tela.
     */
    public function test_devolve_a_palavra_como_foi_cadastrada(): void
    {
        $this->assertSame('Sorteio', $this->matcher()->match('quero o SORTEIO', ['Sorteio']));
    }

    /**
     * A ordem da lista decide o empate, e a primeira vence. Sem isso a palavra
     * gravada na participação mudaria entre execuções.
     */
    public function test_primeira_palavra_da_lista_vence_o_empate(): void
    {
        $this->assertSame('sorteio', $this->matcher()->match('sorteio curso', ['sorteio', 'curso']));
        $this->assertSame('curso', $this->matcher()->match('sorteio curso', ['curso', 'sorteio']));
    }

    /**
     * Frase inteira casa, e pedaço dela não.
     */
    public function test_frase_casa_inteira(): void
    {
        $this->assertSame('quero o curso', $this->matcher()->match('oi, quero o curso hoje', ['quero o curso']));
        $this->assertNull($this->matcher()->match('quero o cursinho', ['quero o curso']));
    }

    /**
     * O casamento lê o texto escrito, e não a transcrição do áudio.
     *
     * `readableText()` devolveria a transcrição aqui, e é assim que o
     * atendimento de entrada trata áudio. A campanha não trata: inscrição
     * criada por engano de transcrição é indistinguível, no banco, de uma de
     * verdade.
     */
    public function test_audio_transcrito_nao_entra_no_casamento(): void
    {
        $mensagem = ConversationMessage::factory()->create([
            'message_type' => 'ptt',
            'body' => null,
        ]);

        $this->assertNull($this->matcher()->textoParaCasamento($mensagem));
        $this->assertNull($this->matcher()->match(
            $this->matcher()->textoParaCasamento($mensagem),
            ['sorteio'],
        ));
    }

    public function test_texto_escrito_entra_no_casamento(): void
    {
        $mensagem = ConversationMessage::factory()->create(['body' => 'quero o sorteio']);

        $this->assertSame('quero o sorteio', $this->matcher()->textoParaCasamento($mensagem));
    }

    /**
     * O quase-casamento é insumo de leitura humana, não decisão.
     */
    public function test_quase_casamento_encontra_distancia_um(): void
    {
        $achados = $this->matcher()->quaseCasamentos('quero o sortei agora', ['sorteio']);

        $this->assertCount(1, $achados);
        $this->assertSame('sortei', $achados[0]['word']);
        $this->assertSame('sorteio', $achados[0]['keyword']);
    }

    public function test_quase_casamento_ignora_quem_casou_de_verdade(): void
    {
        $this->assertSame([], $this->matcher()->quaseCasamentos('quero o sorteio', ['sorteio']));
    }

    public function test_quase_casamento_ignora_distancia_maior_que_um(): void
    {
        $this->assertSame([], $this->matcher()->quaseCasamentos('quero o sort agora', ['sorteio']));
    }

    /**
     * O plural é o quase-casamento mais comum, e é exatamente o número que vai
     * decidir se vale relaxar o casamento numa etapa futura.
     */
    public function test_plural_aparece_como_quase_casamento(): void
    {
        $achados = $this->matcher()->quaseCasamentos('vi os sorteios', ['sorteio']);

        $this->assertSame('sorteios', $achados[0]['word']);
    }

    /**
     * Palavra-chave composta fica de fora: medir distância entre uma palavra e
     * uma frase produz ruído, não informação.
     */
    public function test_quase_casamento_ignora_palavra_chave_composta(): void
    {
        $this->assertSame([], $this->matcher()->quaseCasamentos('quero o curs', ['quero o curso']));
    }

    public function test_quase_casamento_de_texto_vazio(): void
    {
        $this->assertSame([], $this->matcher()->quaseCasamentos('', ['sorteio']));
        $this->assertSame([], $this->matcher()->quaseCasamentos(null, ['sorteio']));
    }
}
