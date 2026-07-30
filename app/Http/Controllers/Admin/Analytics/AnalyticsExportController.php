<?php

namespace App\Http\Controllers\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedido de exportação analítica.
 *
 * O escopo decide a permissão exigida. Agregado precisa de
 * `analytics.export_aggregates`; detalhado precisa de
 * `analytics.export_detailed` mais uma finalidade escrita.
 *
 * A finalidade e obrigatória por escrito e não e conferida por ninguém no ato.
 * O que ela faz e deixar registrado quem pediu, quando e para que — de modo que
 * uma exportação de conteúdo tenha dono.
 */
class AnalyticsExportController extends Controller
{
    public function store(Request $request, AnalyticsExportService $exports): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['topics', 'demands'])],
            'scope' => ['required', Rule::in([AnalyticsExportService::SCOPE_AGGREGATE, AnalyticsExportService::SCOPE_DETAILED])],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'flow' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'min:10', 'max:500', 'required_if:scope,'.AnalyticsExportService::SCOPE_DETAILED],
        ], [
            'purpose.required_if' => 'A exportação detalhada exige finalidade escrita.',
            'purpose.min' => 'Descreva a finalidade em pelo menos dez caracteres.',
        ]);

        $permission = $data['scope'] === AnalyticsExportService::SCOPE_DETAILED
            ? 'analytics.export_detailed'
            : 'analytics.export_aggregates';

        abort_unless($request->user()->can($permission), 403);

        $export = $exports->request(
            $request->user(),
            $data['type'],
            $data['scope'],
            $data['format'],
            array_filter([
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'flow' => $data['flow'] ?? null,
            ]),
            $data['purpose'] ?? null,
        );

        return redirect()
            ->route('admin.report-exports.show', $export)
            ->with('success', 'Exportação solicitada. O arquivo expira em '.$export->expires_at?->format('d/m/Y H:i').'.');
    }
}
