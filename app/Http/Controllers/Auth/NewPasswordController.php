<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')->toString()),
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                app(AuditLogger::class)->log('auth.password_reset', 'Senha redefinida pelo fluxo de recuperação.', $user);
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Não foi possível redefinir a senha.']);
        }

        return redirect()->route('login')->with('status', 'Senha redefinida com sucesso.');
    }
}
