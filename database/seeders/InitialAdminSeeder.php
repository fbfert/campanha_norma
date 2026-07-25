<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower((string) env('ADMIN_EMAIL', 'admin@example.com'));
        $password = env('ADMIN_PASSWORD');
        $generated = false;

        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrador'),
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
                'must_change_password' => true,
            ]
        );

        $admin->roles()->syncWithoutDetaching(Role::query()->where('slug', 'administrador')->pluck('id'));

        if ($generated && $this->command) {
            $this->command->warn('Senha temporaria do administrador inicial: '.$password);
        }
    }
}
