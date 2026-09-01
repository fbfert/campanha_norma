<?php

namespace App\Services\Analytics;

use App\Models\ConversationInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cruzamento de onde a pessoa está por sobre o que ela falou.
 *
 * É o recorte que a 9E não entregou, e o que mais sofre com a supressão: cruzar
 * dois eixos divide os mesmos registros por muito mais células, e com duzentas
 * respostas boa parte da tabela sai suprimida. Isso está certo. Insistir em
 * mostrar seria furar a regra que a própria 9E escreveu.
 *
 * Como em toda a 9E, nada aqui é deduzido. Localidade vem do que a pessoa
 * declarou na resposta; DDD diz onde a linha foi habilitada, não onde alguém
 * mora, e tratar um pelo outro produziria mapa errado com cara de mapa certo.
 */
class TopicByLocalityService
{
    public function __construct(private readonly SmallGroupSuppressor $suppressor) {}

    /**
     * Localidade declarada por tema principal.
     *
     * @return array<string, mixed>
     */
    public function matrix(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        return $this->cross($from, $to, $flowId, 'locality_normalized');
    }

    /**
     * O mesmo cruzamento, agrupado por região.
     *
     * @return array<string, mixed>
     */
    public function byRegion(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        return $this->cross($from, $to, $flowId, 'region');
    }

    /**
     * @return array{
     *     topics: array<int, string>,
     *     rows: array<int, array<string, mixed>>,
     *     column_totals: array<string, int>,
     *     total: int,
     *     without_locality: int,
     *     minimum: int
     * }
     */
    private function cross(Carbon $from, Carbon $to, ?int $flowId, string $column): array
    {
        $rows = $this->base($from, $to, $flowId)
            ->join('insight_topics', 'insight_topics.id', '=', 'conversation_insights.insight_topic_id')
            ->whereRaw($this->declaredIsPresent($column))
            ->select(
                DB::raw($this->declared($column).' as lugar'),
                'insight_topics.name as tema',
                DB::raw('count(*) as total'),
            )
            // Agrupa pelo apelido, e não pela expressão repetida: com
            // `ONLY_FULL_GROUP_BY` ligado, o MySQL não reconhece as duas
            // ocorrências da mesma expressão como a mesma coisa e recusa a
            // consulta. Os dois bancos resolvem o apelido.
            ->groupBy(DB::raw('lugar'), 'insight_topics.name')
            ->get();

        $temas = $rows->pluck('tema')->unique()->sort()->values()->all();

        $porLugar = [];
        $rotulos = [];
        $totalPorTema = array_fill_keys($temas, 0);
        $total = 0;

        foreach ($rows as $row) {
            /*
             | Duas grafias da mesma cidade são a mesma cidade.
             |
             | "Chapecó" e "chapeco" viriam do banco como duas linhas, e a
             | tabela mostraria a cidade partida ao meio — com as duas metades
             | possivelmente abaixo do mínimo, o que suprimiria as duas e
             | esconderia uma cidade que tem gente suficiente.
             |
             | A dobra acontece aqui, sobre uma chave sem acento e sem caixa, e
             | o rótulo exibido é a grafia que mais apareceu: quem lê vê a
             | cidade escrita como as pessoas escreveram.
             */
            $chave = $this->canonical((string) $row->lugar);

            if ($chave === '') {
                continue;
            }

            $tema = (string) $row->tema;
            $contagem = (int) $row->total;

            $porLugar[$chave][$tema] = ($porLugar[$chave][$tema] ?? 0) + $contagem;
            $rotulos[$chave][(string) $row->lugar] = ($rotulos[$chave][(string) $row->lugar] ?? 0) + $contagem;
            $totalPorTema[$tema] += $contagem;
            $total += $contagem;
        }

        ksort($porLugar);

        $linhas = [];

        foreach ($porLugar as $chave => $contagens) {
            arsort($rotulos[$chave]);
            $lugar = (string) array_key_first($rotulos[$chave]);
            $celulas = [];

            foreach ($temas as $tema) {
                $valor = $contagens[$tema] ?? 0;

                /*
                 | A célula suprimida continua aqui, com a contagem nula e a
                 | marca. Remove-la faria a soma das colunas visíveis não bater
                 | com o total da linha, e quem lesse concluiria que há registro
                 | faltando — o que é pior do que ver "suprimido".
                 |
                 | Zero passa intacto: ausência de resposta não identifica
                 | ninguém, e escondê-la apagaria informação legítima.
                 */
                $celulas[$tema] = [
                    'total' => $this->suppressor->count($valor),
                    'suppressed' => $this->suppressor->isSuppressed($valor),
                ];
            }

            $somaDaLinha = array_sum($contagens);

            $linhas[] = [
                'locality' => $lugar,
                'cells' => $celulas,
                // O total da linha não é suprimido: ele é a mesma agregação
                // simples que a tela de geografia da 9E já mostra, e suprimir
                // aqui esconderia um número que já está disponível ao lado.
                'total' => $somaDaLinha,
            ];
        }

        return [
            'topics' => $temas,
            'rows' => $linhas,
            'column_totals' => $totalPorTema,
            'total' => $total,
            'without_locality' => $this->withoutLocality($from, $to, $flowId, $column),
            'minimum' => $this->suppressor->minimum(),
        ];
    }

    /**
     * Quantos insights não têm localidade declarada.
     *
     * Contados à parte, nunca distribuídos nem somados a "outros": quem não
     * disse onde mora não mora em lugar nenhum da tabela, e empurrá-lo para uma
     * linha genérica inventaria uma localidade que ninguém declarou.
     */
    private function withoutLocality(Carbon $from, Carbon $to, ?int $flowId, string $column): int
    {
        return (int) $this->base($from, $to, $flowId)
            ->whereRaw('not ('.$this->declaredIsPresent($column).')')
            ->count();
    }

    /**
     * A localidade declarada, com reserva na forma crua.
     *
     * A coluna normalizada é a fonte certa, e hoje ela está vazia: a extração
     * da 9B grava `locality_normalized` como nulo mesmo quando a pessoa disse
     * onde mora, e `locality_text` guarda as declarações de verdade. Ler só a
     * normalizada deixaria esta tela permanentemente vazia, e vazio seria lido
     * como "ninguém declarou" — quando na prática centenas declararam.
     *
     * A reserva não infere nada: `locality_text` é a mesma declaração da própria
     * pessoa, sem passar pela normalização que nunca aconteceu. Quando a 9B
     * passar a preencher a coluna certa, esta expressão continua correta e
     * passa a usá-la.
     */
    private function declared(string $column): string
    {
        if ($column !== 'locality_normalized') {
            return "conversation_insights.{$column}";
        }

        return 'coalesce(nullif(conversation_insights.locality_normalized, \'\'), conversation_insights.locality_text)';
    }

    private function declaredIsPresent(string $column): string
    {
        $expressao = $this->declared($column);

        return "{$expressao} is not null and {$expressao} != ''";
    }

    /**
     * A chave que junta grafias da mesma localidade: sem acento, sem caixa e
     * sem espaço sobrando.
     */
    private function canonical(string $valor): string
    {
        $semAcento = Str::ascii($valor); // ortografia:ignorar - a saída é deliberadamente sem acento: é chave de agrupamento, não texto de tela

        return trim(preg_replace('/\s+/', ' ', mb_strtolower($semAcento)) ?? '');
    }

    private function base(Carbon $from, Carbon $to, ?int $flowId = null)
    {
        return ConversationInsight::query()
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId));
    }
}
