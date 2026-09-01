<?php

namespace App\Http\Controllers\Admin\ResponseAgenda;

use App\Http\Controllers\Controller;
use App\Models\ConversationFlow;
use App\Models\ConversationInsight;
use App\Models\InsightTopic;
use App\Services\Analytics\ResponseAgendaService;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * A pauta de resposta: quem responder, e com o quê à vista.
 *
 * Este módulo é nominal, e por isso mora fora de `Analytics`. As duas coisas
 * obedecem a regras opostas — o painel suprime grupo pequeno para ninguém ser
 * identificado a partir de um agregado; aqui identificar é o ponto, porque
 * alguém vai responder àquela pessoa. Duas regras contrárias no mesmo módulo é
 * onde o vazamento nasce: basta confundir qual caminho se está editando.
 *
 * Toda rota exige **três** permissões juntas: a da pauta, a de identificação e
 * a de conteúdo. O dossiê expõe nome, cidade e o texto que a pessoa escreveu —
 * três exposições distintas, três permissões.
 *
 * **Nada aqui envia.** A marcação de respondida grava a marca e a auditoria, e
 * só. Ela não manda mensagem, não abre o WhatsApp e não agenda nada.
 */
class ResponseAgendaController extends Controller
{
    public function __construct(
        private readonly ResponseAgendaService $agenda,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAgenda($request);

        [$de, $ate] = $this->period($request);
        $fluxoId = $this->flowId($request);

        $fila = $this->agenda->queue($de, $ate, $fluxoId, [
            'topic_id' => $request->integer('tema') ?: null,
            'city' => $request->string('cidade')->toString() ?: null,
            'state' => in_array($request->string('estado')->toString(), ['pendente', 'respondida'], true)
                ? $request->string('estado')->toString()
                : null,
        ]);

        // Os contadores olham o período inteiro, sem o filtro de estado: quem
        // filtra por pendente ainda precisa saber quantas já foram.
        $completa = $this->agenda->queue($de, $ate, $fluxoId, [
            'topic_id' => $request->integer('tema') ?: null,
            'city' => $request->string('cidade')->toString() ?: null,
        ]);

        return view('admin.pauta.index', [
            'fila' => $fila,
            'total' => count($completa),
            'respondidas' => count(array_filter($completa, fn (array $linha): bool => $linha['answered'])),
            'pendentes' => count(array_filter($completa, fn (array $linha): bool => ! $linha['answered'])),
            'temas' => InsightTopic::query()->orderBy('name')->get(['id', 'name']),
            'cidades' => collect($completa)->pluck('city')->filter()->unique()->sort()->values(),
        ] + $this->context($de, $ate, $fluxoId));
    }

    public function show(Request $request, ConversationInsight $insight): View
    {
        $this->authorizeAgenda($request);

        [$de, $ate] = $this->period($request);

        return view('admin.pauta.show', [
            'dossie' => $this->agenda->dossier($insight),
            'insight' => $insight,
        ] + $this->context($de, $ate, $this->flowId($request)));
    }

    /**
     * Marca à mão que a pessoa já foi respondida.
     *
     * A marcação **não envia nada**: não manda mensagem, não abre conversa com
     * o provedor e não agenda. Ela grava quem marcou e quando, e nada mais.
     *
     * Ela existe como reserva da detecção automática, que só funciona se a
     * resposta sair do mesmo número pareado ao sistema. Quem responde de outro
     * número tem só este botão.
     */
    public function markAnswered(Request $request, ConversationInsight $insight): RedirectResponse
    {
        $this->authorizeAgenda($request);

        $insight->forceFill([
            'answered_at' => now(),
            'answered_by' => $request->user()->id,
        ])->save();

        $this->audit->log(
            'response_agenda.marked_answered',
            'Insight marcado como respondido na pauta de resposta.',
            $insight,
            null,
            ['answered_at' => $insight->answered_at?->toDateTimeString()],
        );

        return back()->with('success', 'Marcada como respondida.');
    }

    /**
     * As três permissões, sempre juntas.
     *
     * Separadas, a da pauta sozinha daria acesso a nome, cidade e texto de
     * quem respondeu — exatamente o que as outras duas existem para separar.
     */
    private function authorizeAgenda(Request $request): void
    {
        $usuario = $request->user();

        abort_unless(
            $usuario->can('response_agenda.view')
            && $usuario->can('analytics.view_identification')
            && $usuario->can('analytics.view_content'),
            403,
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $dias = max(1, (int) $this->settings->get('analytics.default_period_days', 30));

        $de = Carbon::parse($request->string('from')->toString() ?: now()->subDays($dias - 1)->toDateString())->startOfDay();
        $ate = Carbon::parse($request->string('to')->toString() ?: now()->toDateString())->endOfDay();

        return $de->lte($ate) ? [$de, $ate] : [$ate->copy()->startOfDay(), $de->copy()->endOfDay()];
    }

    private function flowId(Request $request): ?int
    {
        return $request->integer('flow') ?: null;
    }

    /** @return array<string, mixed> */
    private function context(Carbon $de, Carbon $ate, ?int $fluxoId): array
    {
        return [
            'from' => $de,
            'to' => $ate,
            'flowId' => $fluxoId,
            'flows' => ConversationFlow::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
