<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.admin_email');
        $password = config('app.admin_password');
        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string']]
        );

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Configurez ADMIN_EMAIL et ADMIN_PASSWORD dans le fichier .env pour créer le compte administrateur.'
            );
        }

        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->name = 'Administrateur';
        }

        $user->forceFill([
            'password' => $password,
            'is_admin' => true,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.version'),
        ])->save();
    }
}
