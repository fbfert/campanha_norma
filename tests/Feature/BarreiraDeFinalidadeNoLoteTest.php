<?php

namespace Tests\Feature;

use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Services\MessageBatches\ContactSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Quem se inscreveu numa campanha não entra em lote por engano.
 *
 * As duas coisas são consentimento e não são o mesmo consentimento: participar
 * de um sorteio não é autorizar disparo. Tratar uma pela outra é usar um opt-in
 * específico como se fosse geral.
 *
 * A barreira mora no serviço de seleção, e não na tela, porque barreira que
 * depende de a tela lembrar de aplicar é barreira que um dia falha — basta uma
 * tela nova.
 */
class BarreiraDeFinalidadeNoLoteTest extends TestCase
{
    use RefreshDatabase;

    private function selecao(): ContactSelectionService
    {
        return app(ContactSelectionService::class);
    }

    private function contato(ContactSource $origem, string $nome): Contact
    {
        return Contact::factory()->create([
            'name' => $nome,
            'source' => $origem,
            'status' => ContactStatus::Active,
        ]);
    }

    public function test_lote_por_filtro_nao_seleciona_contato_de_origem_gatilho(): void
    {
        $this->contato(ContactSource::Importacao, 'Da importação');
        $this->contato(ContactSource::Gatilho, 'Do sorteio');

        $selecionados = $this->selecao()->select(['selection_type' => 'filtered', 'filters' => []]);

        $this->assertSame(['Da importação'], $selecionados->pluck('name')->all());
    }

    public function test_marcacao_explicita_inclui_o_contato_de_gatilho(): void
    {
        $this->contato(ContactSource::Importacao, 'Da importação');
        $this->contato(ContactSource::Gatilho, 'Do sorteio');

        $selecionados = $this->selecao()->select([
            'selection_type' => 'filtered',
            'filters' => [],
            'include_trigger_contacts' => true,
        ]);

        $this->assertCount(2, $selecionados);
    }

    /**
     * A barreira exclui uma origem só. Toda origem que não seja gatilho segue
     * elegível, e o teste percorre a lista inteira para que uma origem nova não
     * caia na barreira por engano.
     */
    public function test_apenas_a_origem_gatilho_e_barrada(): void
    {
        foreach (ContactSource::cases() as $origem) {
            if ($origem === ContactSource::Gatilho) {
                continue;
            }

            $this->contato($origem, "Origem {$origem->value}");
        }

        $esperado = count(ContactSource::cases()) - 1;

        $this->assertCount(
            $esperado,
            $this->selecao()->select(['selection_type' => 'filtered', 'filters' => []]),
        );
    }

    /**
     * A origem `recebido`, do atendimento de entrada, não é barrada aqui: ela
     * já carrega `not_informed` no consentimento, e a decisão de incluí-la num
     * lote é tomada na tela do contato, com registro de quem decidiu.
     */
    public function test_origem_recebido_nao_e_barrada_por_esta_regra(): void
    {
        $this->contato(ContactSource::Recebido, 'Escreveu primeiro');

        $selecionados = $this->selecao()->select(['selection_type' => 'filtered', 'filters' => []]);

        $this->assertSame(['Escreveu primeiro'], $selecionados->pluck('name')->all());
    }

    /**
     * O sorteio aleatório passa pelo mesmo filtro, e passa ANTES do sorteio:
     * amostrar de um conjunto que inclui os barrados e filtrar depois devolveria
     * um lote menor do que o operador pediu, sem dizer por quê.
     */
    public function test_amostra_aleatoria_respeita_a_barreira(): void
    {
        Contact::factory()->count(3)->create(['source' => ContactSource::Importacao]);
        Contact::factory()->count(5)->create(['source' => ContactSource::Gatilho]);

        $selecionados = $this->selecao()->select([
            'selection_type' => 'random_sample',
            'filters' => [],
            'random_quantity' => 3,
            'random_seed' => 'semente-fixa',
        ]);

        $this->assertCount(3, $selecionados);
        $this->assertTrue($selecionados->every(fn (Contact $c): bool => $c->source === ContactSource::Importacao));
    }

    /**
     * Amostra maior do que o conjunto permitido é recusada, e não completada
     * com quem a barreira tirou.
     */
    public function test_amostra_maior_que_o_permitido_e_recusada(): void
    {
        Contact::factory()->count(2)->create(['source' => ContactSource::Importacao]);
        Contact::factory()->count(10)->create(['source' => ContactSource::Gatilho]);

        $this->expectException(ValidationException::class);

        $this->selecao()->select([
            'selection_type' => 'random_sample',
            'filters' => [],
            'random_quantity' => 5,
        ]);
    }

    /**
     * Na seleção manual a barreira recusa em vez de filtrar em silêncio.
     *
     * Tirar sem avisar um contato que o operador clicou produz um lote menor do
     * que ele montou, e o que ele conclui é que o sistema perdeu gente.
     */
    public function test_selecao_manual_com_contato_de_gatilho_e_recusada_com_o_motivo(): void
    {
        $permitido = $this->contato(ContactSource::Importacao, 'Da importação');
        $barrado = $this->contato(ContactSource::Gatilho, 'Do sorteio');

        try {
            $this->selecao()->select([
                'selection_type' => 'manual',
                'contact_ids' => [$permitido->id, $barrado->id],
            ]);

            $this->fail('A seleção manual deveria ter sido recusada.');
        } catch (ValidationException $excecao) {
            $mensagem = $excecao->validator->errors()->first('contact_ids');

            $this->assertStringContainsString('1 contato veio', $mensagem);
            $this->assertStringContainsString('consentiram em participar da campanha', $mensagem);
        }
    }

    public function test_selecao_manual_com_marcacao_explicita_e_aceita(): void
    {
        $permitido = $this->contato(ContactSource::Importacao, 'Da importação');
        $barrado = $this->contato(ContactSource::Gatilho, 'Do sorteio');

        $selecionados = $this->selecao()->select([
            'selection_type' => 'manual',
            'contact_ids' => [$permitido->id, $barrado->id],
            'include_trigger_contacts' => true,
        ]);

        $this->assertCount(2, $selecionados);
    }

    public function test_selecao_manual_sem_nenhum_contato_de_gatilho_continua_funcionando(): void
    {
        $contato = $this->contato(ContactSource::Manual, 'Digitado à mão');

        $selecionados = $this->selecao()->select([
            'selection_type' => 'manual',
            'contact_ids' => [$contato->id],
        ]);

        $this->assertSame(['Digitado à mão'], $selecionados->pluck('name')->all());
    }
}
