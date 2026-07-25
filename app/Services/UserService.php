<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $roleIds = $data['roles'] ?? [];
            unset($data['roles']);

            $data['email'] = Str::lower($data['email']);
            $data['must_change_password'] = true;

            $user = User::create($data);
            $user->roles()->sync($roleIds);

            app(AuditLogger::class)->log('user.created', 'Usuario criado.', $user, null, $user->only(['name', 'email', 'status']));

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $old = $user->only(['name', 'email', 'status']);
            $roleIds = $data['roles'] ?? [];
            unset($data['roles']);

            if (isset($data['email'])) {
                $data['email'] = Str::lower($data['email']);
            }

            $user->update($data);
            $user->roles()->sync($roleIds);
            $user->refresh();

            app(AuditLogger::class)->log('user.updated', 'Usuario atualizado.', $user, $old, $user->only(['name', 'email', 'status']));

            return $user;
        });
    }

    public function changeStatus(User $actor, User $user, UserStatus $status): void
    {
        if ($actor->is($user) && $status !== UserStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Voce nao pode bloquear ou inativar sua propria conta.']);
        }

        if ($user->hasRole('administrador') && $status !== UserStatus::Active && $this->activeAdministratorCountExcluding($user) === 0) {
            throw ValidationException::withMessages(['status' => 'Nao e permitido deixar o sistema sem administrador ativo.']);
        }

        $old = $user->only(['status']);
        $user->update(['status' => $status]);

        app(AuditLogger::class)->log('user.status_changed', 'Status do usuario alterado.', $user, $old, ['status' => $status->value]);
    }

    public function resetPassword(User $user): string
    {
        $temporaryPassword = Str::password(16);

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->save();

        app(AuditLogger::class)->log('user.password_reset', 'Senha temporaria gerada para usuario.', $user);

        return $temporaryPassword;
    }

    public function delete(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'Voce nao pode excluir sua propria conta.']);
        }

        if ($user->hasRole('administrador') && $this->activeAdministratorCountExcluding($user) === 0) {
            throw ValidationException::withMessages(['user' => 'Nao e permitido remover o ultimo administrador ativo.']);
        }

        $user->delete();

        app(AuditLogger::class)->log('user.deleted', 'Usuario excluido logicamente.', $user);
    }

    private function activeAdministratorCountExcluding(User $user): int
    {
        return Role::query()
            ->where('slug', 'administrador')
            ->firstOrFail()
            ->users()
            ->where('users.id', '!=', $user->id)
            ->where('users.status', UserStatus::Active->value)
            ->count();
    }
}
