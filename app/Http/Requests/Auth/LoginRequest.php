<?php

namespace App\Http\Requests\Auth;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => Str::lower($this->string('email')->toString()),
            'password' => $this->string('password')->toString(),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            app(AuditLogger::class)->log('auth.login_failed', 'Falha de login para '.$credentials['email'].'.');

            throw ValidationException::withMessages([
                'email' => 'As credenciais informadas nao conferem.',
            ]);
        }

        $user = Auth::user();

        if (! $user || ! $user->isActive()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());
            app(AuditLogger::class)->log('auth.login_denied', 'Login negado por status do usuario.', $user);

            throw ValidationException::withMessages([
                'email' => 'Este usuario nao esta autorizado a acessar o sistema.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
