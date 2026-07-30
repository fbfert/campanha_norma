<?php

namespace App\Services\Analytics;

use App\Models\ConversationInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agregação por cidade e região.
 *
 * Duas fontes, ambas já existentes e ambas legitimas:
 *
 * - o cadastro do contato, preenchido por quem importou a lista;
 * - a localidade que a própria pessoa declarou na resposta, normalizada pela
 *   interpretação.
 *
 * Não existe dedução por DDD nem por qualquer outro indício. O DDD diz onde a
 * linha foi habilitada, não onde a pessoa mora — quem mudou de cidade e manteve
 * o número seria contado no lugar errado, e o mapa resultante teria aparência
 * de certo. Um dado geografico errado com cara de exato e pior que a ausência
 * dele.
 *
 * Não ha cruzamento com atributo sensível. Isso não e uma opção desligada por
 * padrão: não existe método para ligar.
 */
class GeographyMetricsService
{
    public function __construct(private readonly SmallGroupSuppressor $suppressor) {}

    /**
     * Respostas por cidade declarada pela própria pessoa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDeclaredLocality(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 50): array
    {
        $rows = ConversationInsight::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('locality_normalized')
            ->where('locality_normalized', '!=', '')
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId))
            ->select('locality_normalized as locality', 'region', DB::raw('count(*) as total'))
            ->groupBy('locality_normalized', 'region')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'locality' => (string) $row->locality,
                'region' => $row->region === null ? null : (string) $row->region,
                'total' => (int) $row->total,
            ]);

        return $this->suppressor->rows($rows);
    }

    /**
     * Respostas por cidade do cadastro do contato.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byRegisteredCity(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 50): array
    {
        $rows = ConversationInsight::query()
            ->join('contacts', 'contacts.id', '=', 'conversation_insights.contact_id')
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->whereNotNull('contacts.city')
            ->where('contacts.city', '!=', '')
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId))
            ->select('contacts.city', 'contacts.state', DB::raw('count(*) as total'))
            ->groupBy('contacts.city', 'contacts.state')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'city' => (string) $row->city,
                'state' => $row->state === null ? null : (string) $row->state,
                'total' => (int) $row->total,
            ]);

        return $this->suppressor->rows($rows);
    }

    /**
     * Quantas respostas não tem nenhuma origem geografica conhecida.
     *
     * Exposto de propósito. Sem esse número, um mapa com poucas cidades parece
     * um mapa completo de poucas cidades, e não um mapa cheio de buracos.
     */
    public function withoutLocality(Carbon $from, Carbon $to, ?int $flowId = null): int
    {
        return (int) ConversationInsight::query()
            ->leftJoin('contacts', 'contacts.id', '=', 'conversation_insights.contact_id')
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId))
            ->where(function ($query): void {
                $query->whereNull('conversation_insights.locality_normalized')
                    ->orWhere('conversation_insights.locality_normalized', '');
            })
            ->where(function ($query): void {
                $query->whereNull('contacts.city')->orWhere('contacts.city', '');
            })
            ->count();
    }
}
