<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\SendResult;
use App\Enums\KeywordCouponStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Jobs\EntregarCupomDeCampanhaJob;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use App\Models\KeywordCampaignParticipation;
use App\Models\Role;
use App\Models\User;
use App\Services\KeywordCampaigns\CampaignFreezer;
use App\Services\KeywordCampaigns\CouponService;
use App\Services\KeywordCampaigns\DrawService;
use App\Services\KeywordCampaigns\ParticipantExportService;
use App\Services\MessageBatches\RandomSelectionService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Sorteio auditável e entrega do prêmio.
 *
 * O que faz um sorteio ser auditável não é o gerador aleatório: é a lista estar
 * congelada, a semente estar registrada em claro e qualquer pessoa conseguir
 * refazer a conta e chegar ao mesmo resultado.
 */
class SorteioAuditavelECuponsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function usuario(string $papel = 'administrador'): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::where('slug', $papel)->firstOrFail());

        return $user->refresh()->load('roles.permissions');
    }

    /**
     * Campanha com a lista já congelada e cupons no estoque.
     */
    private function campanhaPronta(int $inscritos = 5, int $cupons = 3): KeywordCampaign
    {
        $campanha = KeywordCampaign::factory()->create();

        for ($i = 1; $i <= $inscritos; $i++) {
            KeywordCampaignParticipation::factory()->alunoConfirmado()->create([
                'keyword_campaign_id' => $campanha->id,
                'contact_id' => Contact::factory()->create([
                    'phone_normalized' => '55499999'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                ])->id,
            ]);
        }

        KeywordCampaignCoupon::factory()->count($cupons)->create(['keyword_campaign_id' => $campanha->id]);

        app(CampaignFreezer::class)->congelar($campanha, $this->usuario());

        return $campanha->fresh();
    }

    private function draws(): DrawService
    {
        return app(DrawService::class);
    }

    /**
     * O defeito corrigido: `mt_srand(abs(crc32($seed)))` reduz a semente a 32
     * bits. Para um sorteio cuja única defesa é a semente registrada, a semente
     * registrada precisa ser a semente de verdade.
     */
    public function test_semente_inteira_e_usada_na_derivacao(): void
    {
        $random = app(RandomSelectionService::class);
        $ids = range(1, 40);

        // Duas sementes longas que colidiriam se a derivação fosse por crc32
        // continuam produzindo ordens diferentes aqui.
        $primeira = $random->auditableSample($ids, 10, str_repeat('a', 60).'1');
        $segunda = $random->auditableSample($ids, 10, str_repeat('a', 60).'2');

        $this->assertNotSame($primeira, $segunda);
    }

    public function test_amostra_auditavel_e_deterministica(): void
    {
        $random = app(RandomSelectionService::class);
        $ids = range(1, 50);

        $this->assertSame(
            $random->auditableSample($ids, 5, 'semente-fixa'),
            $random->auditableSample($ids, 5, 'semente-fixa'),
        );

        // A ordem em que os identificadores chegam não muda o resultado: é a
        // semente que ordena, não a entrada.
        $this->assertSame(
            $random->auditableSample($ids, 5, 'semente-fixa'),
            $random->auditableSample(array_reverse($ids), 5, 'semente-fixa'),
        );
    }

    /**
     * O caminho antigo do lote não pode mudar: um lote sorteado ontem com a
     * mesma semente continua dando o mesmo resultado.
     */
    public function test_sorteio_de_lote_mantem_o_comportamento_anterior(): void
    {
        $random = app(RandomSelectionService::class);
        $ids = range(1, 20);

        $esperado = $random->sample($ids, 5, 'semente-do-lote');

        $this->assertSame($esperado, $random->sample($ids, 5, 'semente-do-lote'));
        $this->assertNotSame($esperado, $random->auditableSample($ids, 5, 'semente-do-lote'));
    }

    public function test_sorteio_sem_congelamento_e_recusado(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        KeywordCampaignParticipation::factory()->alunoConfirmado()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignCoupon::factory()->create(['keyword_campaign_id' => $campanha->id]);

        try {
            $this->draws()->sortear($campanha, 1, $this->usuario());
            $this->fail('Deveria recusar sem congelamento.');
        } catch (ValidationException $excecao) {
            $this->assertStringContainsString('precisa estar congelada', $excecao->validator->errors()->first('sorteio'));
        }
    }

    public function test_mesma_semente_e_mesma_lista_produzem_o_mesmo_resultado(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 8, cupons: 8);

        $primeiro = $this->draws()->sortear($campanha, 3, $this->usuario(), 'semente-conhecida');
        $verificacao = $this->draws()->verificar($primeiro);

        $this->assertTrue($verificacao['confere']);
        $this->assertSame(array_map('intval', $primeiro->result), $verificacao['resultado']);

        // Verificado duas vezes, como o plano pede: a reprodutibilidade não
        // pode depender de estado deixado pela execução anterior.
        $this->assertTrue($this->draws()->verificar($primeiro->fresh())['confere']);
    }

    public function test_sorteio_grava_hash_semente_quantidade_e_autor(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 5, cupons: 5);
        $usuario = $this->usuario();

        $draw = $this->draws()->sortear($campanha, 2, $usuario, 'semente-registrada', 'Sorteio ao vivo.');

        $this->assertSame($campanha->frozen_list_hash, $draw->list_hash);
        $this->assertSame('semente-registrada', $draw->seed);
        $this->assertSame(2, $draw->quantity);
        $this->assertCount(2, $draw->result);
        $this->assertSame($usuario->id, $draw->executed_by);
        $this->assertNotNull($draw->executed_at);
        $this->assertSame('Sorteio ao vivo.', $draw->note);
    }

    public function test_semente_em_branco_e_gerada_e_registrada(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);

        $draw = $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->assertNotEmpty($draw->seed);
        $this->assertTrue($this->draws()->verificar($draw)['confere']);
    }

    public function test_sorteio_com_cupons_insuficientes_e_recusado_dizendo_quantos_faltam(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 10, cupons: 2);

        try {
            $this->draws()->sortear($campanha, 5, $this->usuario());
            $this->fail('Deveria recusar por falta de cupom.');
        } catch (ValidationException $excecao) {
            $this->assertStringContainsString('Faltam 3 cupons', $excecao->validator->errors()->first('sorteio'));
        }

        $this->assertSame(0, $campanha->draws()->count());
    }

    public function test_sorteio_maior_que_a_lista_e_recusado(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 2, cupons: 10);

        $this->expectException(ValidationException::class);
        $this->draws()->sortear($campanha, 5, $this->usuario());
    }

    public function test_cada_ganhador_recebe_um_cupom_diferente(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 6, cupons: 6);

        $draw = $this->draws()->sortear($campanha, 3, $this->usuario());

        $atribuidos = KeywordCampaignCoupon::query()
            ->whereNotNull('keyword_campaign_participation_id')
            ->get();

        $this->assertCount(3, $atribuidos);
        $this->assertCount(3, $atribuidos->pluck('keyword_campaign_participation_id')->unique());
        $this->assertCount(3, $atribuidos->pluck('id')->unique());
        $this->assertEqualsCanonicalizing(
            array_map('intval', $draw->result),
            $atribuidos->pluck('keyword_campaign_participation_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    /**
     * A garantia não vem da verificação em PHP: vem da chave única no banco.
     * Reexecutar a atribuição não dá um segundo cupom a quem já tem.
     */
    public function test_reatribuicao_nao_da_segundo_cupom(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 4, cupons: 4);
        $draw = $this->draws()->sortear($campanha, 2, $this->usuario());

        $coupons = app(CouponService::class);
        $coupons->atribuirAosGanhadores($campanha, array_map('intval', $draw->result));
        $coupons->atribuirAosGanhadores($campanha, array_map('intval', $draw->result));

        $this->assertSame(2, KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->count());
    }

    public function test_importacao_de_cupons_e_idempotente(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $coupons = app(CouponService::class);

        $primeira = $coupons->importarCodigos($campanha, ['ABC-1', 'ABC-2'], $this->usuario());
        $segunda = $coupons->importarCodigos($campanha, ['ABC-1', 'ABC-2', 'ABC-3'], $this->usuario());

        $this->assertSame(2, $primeira['importados']);
        $this->assertSame(1, $segunda['importados']);
        $this->assertSame(2, $segunda['repetidos']);
        $this->assertSame(3, $campanha->coupons()->count());
    }

    public function test_o_mesmo_codigo_em_campanhas_diferentes_e_permitido(): void
    {
        $coupons = app(CouponService::class);
        $primeira = KeywordCampaign::factory()->create();
        $segunda = KeywordCampaign::factory()->create();

        $coupons->importarCodigos($primeira, ['ABC-1'], $this->usuario());
        $coupons->importarCodigos($segunda, ['ABC-1'], $this->usuario());

        $this->assertSame(1, $primeira->coupons()->count());
        $this->assertSame(1, $segunda->coupons()->count());
    }

    public function test_csv_de_cupons_e_lido(): void
    {
        // ortografia:ignorar — conteúdo de CSV lido pelo importador.
        $csv = "codigo\nCURSO-AAA\nCURSO-BBB\n"; // ortografia:ignorar — cabeçalho de CSV lido pelo importador.

        $codigos = app(CouponService::class)->lerCodigos(
            UploadedFile::fake()->createWithContent('cupons.csv', $csv),
        );

        $this->assertSame(['CURSO-AAA', 'CURSO-BBB'], $codigos);
    }

    /**
     * `toArray()` alimenta log estruturado, resposta JSON e `dd()`. Esconder o
     * código ali é o que impede o vazamento por um caminho que ninguém revisou.
     */
    public function test_codigo_nao_aparece_na_serializacao_do_modelo(): void
    {
        $cupom = KeywordCampaignCoupon::factory()->create(['code' => 'SEGREDO-123']);

        $this->assertArrayNotHasKey('code', $cupom->toArray());
        $this->assertStringNotContainsString('SEGREDO-123', $cupom->toJson());

        // Quem precisa do código pede explicitamente.
        $this->assertSame('SEGREDO-123', app(CouponService::class)->revelar($cupom));
    }

    /**
     * Nem todo prêmio vem de planilha: três códigos lidos de um e-mail devem
     * poder ser digitados sem obrigar ninguém a criar um arquivo com cupom
     * dentro só para apagá-lo depois.
     */
    public function test_cupons_cadastrados_a_mao_entram_no_estoque(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.coupons.manual', $campanha), [
                'codigos' => "CURSO-AAA\nCURSO-BBB\nCURSO-CCC",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(3, app(CouponService::class)->disponiveis($campanha));
    }

    /**
     * A idempotência é a mesma da importação, e vem da chave única do banco:
     * quem digita duas vezes não ganha dois prêmios.
     */
    public function test_cadastro_a_mao_nao_duplica_codigo_existente(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        app(CouponService::class)->importarCodigos($campanha, ['CURSO-AAA'], $this->usuario());

        app(CouponService::class)->cadastrarAMao($campanha, "CURSO-AAA\nCURSO-BBB", $this->usuario());

        $this->assertSame(2, $campanha->coupons()->count());
    }

    /**
     * Quem copia de uma planilha cola tudo numa linha só. Recusar isso seria
     * transformar um acerto de formatação em erro de tela.
     */
    public function test_cadastro_a_mao_aceita_virgula_e_ponto_e_virgula(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        app(CouponService::class)->cadastrarAMao($campanha, ' CURSO-AAA, CURSO-BBB; CURSO-CCC ', $this->usuario());

        $this->assertSame(3, $campanha->coupons()->count());
        $this->assertSame(
            ['CURSO-AAA', 'CURSO-BBB', 'CURSO-CCC'],
            $campanha->coupons()->orderBy('id')->get()
                ->map(fn (KeywordCampaignCoupon $cupom): string => app(CouponService::class)->revelar($cupom))
                ->all(),
        );
    }

    public function test_cadastro_a_mao_recusa_texto_sem_codigo_nenhum(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.coupons.manual', $campanha), [
                'codigos' => "  \n , ; \n ",
            ])
            ->assertSessionHasErrors('codigos');

        $this->assertSame(0, $campanha->coupons()->count());
    }

    /**
     * Cupom é valor, e a permissão de administrar cupons é o que separa quem
     * pode criá-lo de quem só olha o sorteio.
     */
    public function test_cadastro_a_mao_exige_permissao_de_cupons(): void
    {
        $campanha = KeywordCampaign::factory()->create();
        $usuario = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

        $this->actingAs($usuario)
            ->post(route('admin.keyword-campaigns.draws.coupons.manual', $campanha), [
                'codigos' => 'CURSO-AAA',
            ])
            ->assertForbidden();

        $this->assertSame(0, $campanha->coupons()->count());
    }

    public function test_codigo_cadastrado_a_mao_nao_aparece_na_auditoria(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        app(CouponService::class)->cadastrarAMao($campanha, 'SEGREDO-123', $this->usuario());

        $registros = AuditLog::where('action', 'keyword_campaign.coupons_imported')->get();

        $this->assertNotEmpty($registros);

        foreach ($registros as $registro) {
            $this->assertStringNotContainsString('SEGREDO-123', json_encode($registro->toArray()));
        }

        // A origem fica registrada: o histórico diz de onde o cupom veio sem
        // dizer qual é o cupom.
        $this->assertSame('manual', $registros->first()->new_values['origem'] ?? null);
    }

    public function test_codigo_nao_aparece_na_auditoria_da_importacao(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        app(CouponService::class)->importarCodigos($campanha, ['SEGREDO-123'], $this->usuario());

        $registros = AuditLog::where('action', 'keyword_campaign.coupons_imported')->get();

        $this->assertNotEmpty($registros);

        foreach ($registros as $registro) {
            $this->assertStringNotContainsString('SEGREDO-123', json_encode($registro->toArray()));
        }
    }

    public function test_codigo_nao_aparece_na_exportacao_de_participantes(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $export = app(ParticipantExportService::class)
            ->solicitar($this->usuario(), $campanha);

        $conteudo = Storage::disk('local')->get($export->file_path);

        $this->assertStringNotContainsString('SEGREDO-123', $conteudo);
    }

    /**
     * O histórico da conversa é lido por muito mais gente do que a tela de
     * cupons: o que fica gravado é a referência.
     */
    public function test_entrega_grava_referencia_no_historico_e_nao_o_codigo(): void
    {
        $this->fakeProvedor();

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $cupom = KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->firstOrFail();

        EntregarCupomDeCampanhaJob::dispatchSync($cupom->id);

        $cupom->refresh();
        $this->assertSame(KeywordCouponStatus::Entregue, $cupom->status);
        $this->assertNotNull($cupom->delivered_at);

        $mensagens = ConversationMessage::where('direction', 'outgoing')->get();

        $this->assertNotEmpty($mensagens);

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsString(
                (string) $cupom->getAttributeValue('code'),
                (string) $mensagem->body,
                'O código do cupom não pode ficar gravado no histórico.',
            );
        }

        $this->assertTrue($mensagens->contains(fn ($m): bool => str_contains((string) $m->body, (string) $cupom->reference)));
    }

    public function test_entrega_nao_repete_cupom_ja_entregue(): void
    {
        $this->fakeProvedor();

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $cupom = KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->firstOrFail();

        EntregarCupomDeCampanhaJob::dispatchSync($cupom->id);
        EntregarCupomDeCampanhaJob::dispatchSync($cupom->id);

        $this->assertSame(1, ConversationMessage::where('direction', 'outgoing')->count());
    }

    public function test_tela_de_sorteio_esconde_o_codigo_de_quem_nao_administra_cupons(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario('operador'))
            ->get(route('admin.keyword-campaigns.draws.index', $campanha))
            ->assertOk()
            ->assertDontSee('SEGREDO-123');
    }

    public function test_tela_de_sorteio_mostra_o_codigo_para_quem_administra_cupons(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.keyword-campaigns.draws.index', $campanha))
            ->assertOk()
            ->assertSee('SEGREDO-123');
    }

    public function test_operador_nao_executa_sorteio(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);

        $this->actingAs($this->usuario('operador'))
            ->post(route('admin.keyword-campaigns.draws.store', $campanha), [
                'quantity' => 1,
                'confirmacao' => '1',
            ])
            ->assertForbidden();
    }

    /**
     * Sortear não pode ser um clique acidental.
     */
    public function test_sorteio_sem_confirmacao_explicita_e_recusado(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.draws.store', $campanha), ['quantity' => 1])
            ->assertSessionHasErrors('confirmacao');

        $this->assertSame(0, $campanha->draws()->count());
    }

    public function test_sorteio_pela_tela_funciona(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 4, cupons: 4);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('admin.keyword-campaigns.draws.store', $campanha), [
                'quantity' => 2,
                'seed' => 'semente-da-tela',
                'confirmacao' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $campanha->draws()->count());
        $this->assertSame('semente-da-tela', $campanha->draws()->first()->seed);
    }

    /**
     * Invalidar depois do sorteio não altera o resultado registrado. O sorteio
     * guarda os identificadores, não uma consulta refeita na hora de mostrar.
     */
    public function test_invalidacao_depois_do_sorteio_nao_altera_o_resultado(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 4, cupons: 4);
        $draw = $this->draws()->sortear($campanha, 2, $this->usuario());
        $resultadoOriginal = $draw->result;

        KeywordCampaignParticipation::whereKey($draw->result[0])->update([
            'status' => KeywordParticipationStatus::Invalidada,
        ]);

        $this->assertSame($resultadoOriginal, $draw->fresh()->result);

        // A conta não bate mais, e a tela diz exatamente por quê: a lista
        // mudou, e não o sorteio.
        $verificacao = $this->draws()->verificar($draw->fresh());
        $this->assertFalse($verificacao['lista_confere']);
    }

    public function test_lista_congelada_ignora_quem_nao_e_aluno(): void
    {
        $campanha = KeywordCampaign::factory()->create();

        $aluno = KeywordCampaignParticipation::factory()->alunoConfirmado()->create(['keyword_campaign_id' => $campanha->id]);
        KeywordCampaignParticipation::factory()->create([
            'keyword_campaign_id' => $campanha->id,
            'eligibility' => KeywordParticipationEligibility::NaoAluno,
            'reviewed_at' => now(),
        ]);
        KeywordCampaignCoupon::factory()->create(['keyword_campaign_id' => $campanha->id]);

        app(CampaignFreezer::class)->congelar($campanha, $this->usuario());

        $draw = $this->draws()->sortear($campanha->fresh(), 1, $this->usuario());

        $this->assertSame([$aluno->id], array_map('intval', $draw->result));
    }

    /**
     * Frase de código não serve para prêmio nenhum: o cupom pode ser um curso,
     * um ingresso ou um desconto, e cada um pede uma instrução diferente do que
     * fazer com o código depois de recebê-lo.
     */
    public function test_mensagem_configurada_chega_ao_ganhador_com_o_codigo(): void
    {
        $enviadas = [];
        $this->fakeProvedorCapturando($enviadas);

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->update(['coupon_text' => 'Você ganhou! Use {codigo} em norma.com.br/resgate.']);  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $cupom = KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->firstOrFail();
        EntregarCupomDeCampanhaJob::dispatchSync($cupom->id);

        $this->assertSame(
            ['Você ganhou! Use '.$cupom->getAttributeValue('code').' em norma.com.br/resgate.'],
            $enviadas,
        );
    }

    /**
     * Campanha existente tem o campo nulo, e precisa continuar mandando
     * exatamente o que mandava antes de a mensagem virar configurável.
     */
    public function test_campanha_sem_texto_usa_o_padrao_de_antes(): void
    {
        $enviadas = [];
        $this->fakeProvedorCapturando($enviadas);

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->assertNull($campanha->coupon_text);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $cupom = KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->firstOrFail();
        EntregarCupomDeCampanhaJob::dispatchSync($cupom->id);

        $this->assertStringStartsWith('Parabéns! Você foi sorteado.', $enviadas[0]);
        $this->assertStringContainsString($cupom->getAttributeValue('code'), $enviadas[0]);
    }

    /**
     * A trava que importa: "parabéns, você ganhou" sem o código é um prêmio que
     * não foi entregue, e o ganhador não tem como saber que faltou alguma
     * coisa. O cupom fica marcado como entregue e o erro só aparece quando a
     * pessoa reclama.
     */
    public function test_entrega_recusa_mensagem_sem_o_codigo(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Parabéns, você foi sorteado!',
            ])
            ->assertSessionHasErrors('mensagem');

        $this->assertNull($campanha->fresh()->coupon_text);
        $this->assertSame(0, KeywordCampaignCoupon::whereNotNull('delivered_at')->count());
    }

    public function test_entrega_recusa_placeholder_que_a_mensagem_nao_conhece(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Ganhou {codigo}, aí em {cidade}.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertSessionHasErrors('mensagem');

        $this->assertNull($campanha->fresh()->coupon_text);
    }

    /**
     * Descobrir no meio da fila que um ganhador não tem nome deixaria a escolha
     * entre mandar "Parabéns, !" e não mandar nada — e as duas são ruins depois
     * que metade do lote já saiu.
     */
    public function test_entrega_recusa_nome_quando_um_ganhador_nao_tem_nome(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->draws()->sortear($campanha, 3, $this->usuario());

        KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)
            ->update(['captured_name' => null, 'reviewed_name' => null]);

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Parabéns, {nome}! Seu código é {codigo}.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertSessionHasErrors('mensagem');

        $this->assertNull($campanha->fresh()->coupon_text);
        $this->assertSame(0, KeywordCampaignCoupon::whereNotNull('delivered_at')->count());
    }

    public function test_nome_do_ganhador_entra_na_mensagem(): void
    {
        $enviadas = [];
        $this->fakeProvedorCapturando($enviadas);

        $campanha = $this->campanhaPronta(inscritos: 1, cupons: 1);
        KeywordCampaignParticipation::where('keyword_campaign_id', $campanha->id)
            ->update(['reviewed_name' => 'Maria Ganhadora']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Parabéns, {nome}! Seu código é {codigo}.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith('Parabéns, Maria Ganhadora! Seu código é ', $enviadas[0]);
    }

    /**
     * Gravada na campanha: reenviar depois de uma falha manda o mesmo texto, e
     * não o padrão de fábrica.
     */
    public function test_mensagem_fica_salva_na_campanha(): void
    {
        $this->fakeProvedor();

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Use {codigo} em norma.com.br/resgate.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Use {codigo} em norma.com.br/resgate.', $campanha->fresh()->coupon_text);  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
    }

    /**
     * O molde é gravado; o código, não. Guardar a mensagem em banco não pode
     * virar um caminho novo para o cupom vazar.
     */
    public function test_mensagem_salva_guarda_o_molde_e_nao_o_codigo(): void
    {
        $enviadas = [];
        $this->fakeProvedorCapturando($enviadas);

        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $campanha->coupons()->first()->update(['code' => 'SEGREDO-123']);
        $this->draws()->sortear($campanha, 1, $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Use {codigo} para entrar.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertSessionHasNoErrors();

        $cupom = KeywordCampaignCoupon::whereNotNull('keyword_campaign_participation_id')->firstOrFail();

        $this->assertStringNotContainsString(
            (string) $cupom->getAttributeValue('code'),
            (string) $campanha->fresh()->coupon_text,
        );

        foreach (ConversationMessage::where('direction', 'outgoing')->get() as $mensagem) {
            $this->assertStringNotContainsString(
                (string) $cupom->getAttributeValue('code'),
                (string) $mensagem->body,
            );
        }
    }

    public function test_entrega_exige_permissao_de_cupons(): void
    {
        $campanha = $this->campanhaPronta(inscritos: 3, cupons: 3);
        $usuario = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

        $this->actingAs($usuario)
            ->post(route('admin.keyword-campaigns.draws.deliver', $campanha), [
                'mensagem' => 'Use {codigo}.',  // ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento
            ])
            ->assertForbidden();
    }

    /**
     * Guarda o texto que foi para o provedor.
     *
     * O corpo gravado no histórico é a referência, de propósito — então
     * conferir a mensagem que o ganhador leu exige olhar o que saiu daqui.
     *
     * @param  list<string>  $enviadas
     */
    private function fakeProvedorCapturando(array &$enviadas): void
    {
        $this->mock(WhatsAppProviderManager::class, function ($mock) use (&$enviadas): void {
            $provedor = \Mockery::mock(WhatsAppProvider::class);
            $provedor->shouldReceive('sendMessage')->andReturnUsing(
                function (string $telefone, string $texto) use (&$enviadas): SendResult {
                    $enviadas[] = $texto;

                    return new SendResult('pedido-1', 'sent', 'externo-1', CarbonImmutable::now());
                },
            );
            $mock->shouldReceive('provider')->andReturn($provedor);
        });
    }

    private function fakeProvedor(): void
    {
        $this->mock(WhatsAppProviderManager::class, function ($mock): void {
            $provedor = \Mockery::mock(WhatsAppProvider::class);
            $provedor->shouldReceive('sendMessage')->andReturn(
                new SendResult('pedido-1', 'sent', 'externo-1', CarbonImmutable::now()),
            );
            $mock->shouldReceive('provider')->andReturn($provedor);
        });
    }
}
