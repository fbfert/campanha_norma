<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\KeywordCouponStatus;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignCoupon;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Os cupons do prêmio: importação e atribuição.
 *
 * Cupom é valor. Nada aqui devolve código em claro para quem não pediu
 * explicitamente, e nenhum registro de auditoria carrega um.
 */
class CouponService
{
    /**
     * O cabeçalho é identificador, lido pelo código: sem acento.
     *
     * @var list<string>
     */
    private const CABECALHOS_DE_CODIGO = ['codigo', 'code', 'cupom', 'coupon'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function disponiveis(KeywordCampaign $campaign): int
    {
        return $campaign->coupons()->disponivel()->count();
    }

    /**
     * @return array{importados: int, repetidos: int}
     */
    public function importar(KeywordCampaign $campaign, UploadedFile $arquivo, ?User $usuario = null): array
    {
        return $this->importarCodigos($campaign, $this->lerCodigos($arquivo), $usuario);
    }

    /**
     * Cupons digitados à mão, um por linha.
     *
     * Mesmo caminho da importação por arquivo, e de propósito: a idempotência,
     * o corte de 120 caracteres e a auditoria sem código em claro valem igual
     * para quem digita e para quem sobe planilha. O que muda é só a origem
     * registrada, para o histórico dizer de onde o cupom veio.
     *
     * @return array{importados: int, repetidos: int}
     */
    public function cadastrarAMao(KeywordCampaign $campaign, string $texto, ?User $usuario = null): array
    {
        return $this->importarCodigos($campaign, $this->separarLinhas($texto), $usuario, 'manual');
    }

    /**
     * Uma linha, um código. Vírgula e ponto e vírgula também separam: quem
     * copia de uma planilha cola tudo numa linha só, e recusar isso seria
     * transformar um acerto de formatação em erro de tela.
     *
     * @return list<string>
     */
    public function separarLinhas(string $texto): array
    {
        $pedacos = preg_split('/[\r\n,;]+/', $texto) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $pedaco): string => trim($pedaco), $pedacos),
            static fn (string $pedaco): bool => $pedaco !== '',
        ));
    }

    /**
     * Idempotente pela chave única do banco, não por consulta prévia.
     *
     * Reimportar o mesmo arquivo não duplica nada, e duas importações
     * simultâneas do mesmo lote também não — a verificação feita antes do
     * insert perderia essa corrida.
     *
     * @param  list<string>  $codigos
     * @return array{importados: int, repetidos: int}
     */
    public function importarCodigos(KeywordCampaign $campaign, array $codigos, ?User $usuario = null, string $origem = 'arquivo'): array
    {
        $importados = 0;
        $repetidos = 0;

        foreach ($codigos as $codigo) {
            $codigo = trim($codigo);

            if ($codigo === '') {
                continue;
            }

            try {
                KeywordCampaignCoupon::create([
                    'keyword_campaign_id' => $campaign->id,
                    'code' => Str::limit($codigo, 120, ''),
                    'status' => KeywordCouponStatus::Disponivel,
                    'reference' => $this->novaReferencia(),
                    'imported_by' => $usuario?->id,
                ]);

                $importados++;
            } catch (QueryException $excecao) {
                if (! $this->violouUnicidade($excecao)) {
                    throw $excecao;
                }

                $repetidos++;
            }
        }

        // Nenhum código no registro: a contagem diz o que aconteceu sem
        // entregar o que foi importado.
        $this->audit->log(
            'keyword_campaign.coupons_imported',
            "{$importados} ".($importados === 1 ? 'cupom importado' : 'cupons importados')." na campanha \"{$campaign->name}\".",
            $campaign,
            null,
            ['importados' => $importados, 'repetidos' => $repetidos, 'origem' => $origem],
            $usuario,
        );

        return ['importados' => $importados, 'repetidos' => $repetidos];
    }

    /**
     * Um cupom por ganhador, na ordem do sorteio.
     *
     * A garantia de que dois ganhadores não recebem o mesmo código não vem
     * desta função: vem da chave única em `keyword_campaign_participation_id` e
     * do `lockForUpdate`. Verificação em PHP perde a corrida entre dois
     * processos, e aqui perder a corrida significa dar o mesmo prêmio duas
     * vezes.
     *
     * @param  list<int>  $participationIds
     * @return int quantos cupons foram atribuídos
     */
    public function atribuirAosGanhadores(KeywordCampaign $campaign, array $participationIds): int
    {
        if ($participationIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($campaign, $participationIds): int {
            $atribuidos = 0;

            foreach ($participationIds as $participationId) {
                // Já tem cupom: reexecutar a atribuição não dá um segundo.
                $existente = KeywordCampaignCoupon::query()
                    ->where('keyword_campaign_id', $campaign->id)
                    ->where('keyword_campaign_participation_id', $participationId)
                    ->exists();

                if ($existente) {
                    continue;
                }

                $cupom = KeywordCampaignCoupon::query()
                    ->where('keyword_campaign_id', $campaign->id)
                    ->disponivel()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $cupom) {
                    break;
                }

                $cupom->forceFill([
                    'keyword_campaign_participation_id' => $participationId,
                    'status' => KeywordCouponStatus::Atribuido,
                    'assigned_at' => now(),
                ])->save();

                $atribuidos++;
            }

            return $atribuidos;
        });
    }

    /**
     * O código em claro, para quem tem a permissão de administrar cupons.
     *
     * Método próprio, e explícito, porque o modelo esconde `code` de toda
     * serialização: pedir o código precisa ser uma decisão de quem escreve o
     * código, não um efeito colateral de um `toArray()`.
     */
    public function revelar(KeywordCampaignCoupon $cupom): string
    {
        return (string) $cupom->getAttributeValue('code');
    }

    public function marcarEntregue(KeywordCampaignCoupon $cupom): void
    {
        $cupom->forceFill([
            'status' => KeywordCouponStatus::Entregue,
            'delivered_at' => now(),
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function lerCodigos(UploadedFile $arquivo): array
    {
        $reader = str_ends_with(Str::lower((string) $arquivo->getClientOriginalName()), '.xlsx')
            ? new XlsxReader
            : new CsvReader;

        $reader->open((string) $arquivo->getRealPath());

        $codigos = [];
        $coluna = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $indice => $linha) {
                $valores = array_map(fn ($valor): string => trim((string) $valor), $linha->toArray());

                if ($indice === 1) {
                    $coluna = $this->colunaDoCodigo($valores);

                    if ($coluna !== null) {
                        continue;
                    }

                    $coluna = 0;
                }

                $valor = $valores[$coluna] ?? '';

                if ($valor !== '') {
                    $codigos[] = $valor;
                }
            }

            break;
        }

        $reader->close();

        return $codigos;
    }

    /**
     * Referência curta que substitui o código no histórico e no log.
     */
    private function novaReferencia(): string
    {
        return 'cupom-'.Str::lower(Str::random(10));
    }

    /**
     * @param  list<string>  $cabecalho
     */
    private function colunaDoCodigo(array $cabecalho): ?int
    {
        foreach ($cabecalho as $indice => $valor) {
            if (in_array(Str::slug($valor, '_'), self::CABECALHOS_DE_CODIGO, true)) {
                return $indice;
            }
        }

        return null;
    }

    private function violouUnicidade(QueryException $excecao): bool
    {
        return $excecao->getCode() === '23000'
            || str_contains(Str::lower($excecao->getMessage()), 'unique');
    }
}
