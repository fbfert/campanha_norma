<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordUpdateRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ForcedPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.force-password');
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'must_change_password' => false,
        ])->save();

        app(AuditLogger::class)->log('auth.password_changed', 'Senha temporaria alterada.', $request->user());

        return redirect()->route('dashboard')->with('success', 'Senha alterada com sucesso.');
    }
}
