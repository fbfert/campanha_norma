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
        $values = $request->validated()['system'];
        $flattened = collect($values)->mapWithKeys(fn ($value, $key) => ["system.{$key}" => $value])->all();

        $old = $settings->updateMany($flattened);

        app(AuditLogger::class)->log('settings.updated', 'Configuracoes gerais alteradas.', null, $old, $flattened);

        return back()->with('success', 'Configuracoes atualizadas com sucesso.');
    }
}
