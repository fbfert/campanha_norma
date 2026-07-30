<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\PasswordUpdateRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless($request->user()->can('manage-profile'), 403);

        return view('profile.show', ['user' => $request->user()->load('roles')]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage-profile'), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $old = $request->user()->only(['name']);
        $request->user()->update($data);

        app(AuditLogger::class)->log('profile.updated', 'Perfil atualizado.', $request->user(), $old, $data);

        return back()->with('success', 'Perfil atualizado com sucesso.');
    }

    public function password(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'must_change_password' => false,
        ])->save();

        app(AuditLogger::class)->log('profile.password_changed', 'Senha do próprio perfil alterada.', $request->user());

        return back()->with('success', 'Senha alterada com sucesso.');
    }
}
