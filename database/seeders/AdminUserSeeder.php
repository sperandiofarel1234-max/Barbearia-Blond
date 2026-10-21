<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Cria/atualiza o administrador a partir de ADMIN_NAME, ADMIN_EMAIL e ADMIN_PASSWORD (.env).
 * Nenhuma credencial fica versionada no repositório.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD ausentes no .env: administrador não criado.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => env('ADMIN_NAME', 'Barbeiro'),
            'password' => $password, // cast "hashed"
            'is_admin' => true,
        ])->save();
    }
}
