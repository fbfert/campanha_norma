<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsUpdateRequest;
use App\Services\AuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request, SystemSettingService $settings): View
    {
        abort_unless($request->user()->can('view-settings'), 403);

        return view('admin.settings.edit', ['settings' => $settings->all()]);
    }

    public function update(SettingsUpdateRequest $request, SystemSettingService $settings): RedirectResponse
    {
        /*
         | A tela salva mais de um grupo desde que a Limpeza chegou.
         |
         | Antes daqui só passava `system`, e o prefixo era escrito a mão. Um
         | segundo grupo com o prefixo fixo gravaria `system.cleanup_trash_days`
         | — chave que ninguém lê — e a configuração pareceria salva sem nunca
         | ter efeito. Percorrer os grupos validados resolve isso de uma vez, e
         | o próximo grupo não precisa mexer aqui.
         */
        $flattened = collect($request->validated())
            ->flatMap(fn (array $values, string $group) => collect($values)
                ->mapWithKeys(fn ($value, $key) => ["{$group}.{$key}" => $value]))
            ->all();

        $old = $settings->updateMany($flattened);

        app(AuditLogger::class)->log('settings.updated', 'Configurações gerais alteradas.', null, $old, $flattened);

        return back()->with('success', 'Configurações atualizadas com sucesso.');
    }
}
