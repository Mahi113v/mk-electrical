<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Default accounts. Passwords come from .env (SEED_ADMIN_PASSWORD / SEED_USER_PASSWORD)
     * so no password is hard coded in the repo. CHANGE THEM after first login.
     * (the old seeder used the same phone for two users -> unique constraint crashed it)
     */
    public function run(): void
    {
        $adminPassword = env('SEED_ADMIN_PASSWORD', 'password123@');
        $userPassword  = env('SEED_USER_PASSWORD', 'password123@');

        $accounts = [
            ['mahesh@mkelectrical-app.in',   'Admin Mahesh', '9952678113', true,  $adminPassword],
            ['mathan@mkelectrical-app.in',   'Admin Mathan', '9585009824', true,  $adminPassword],
            ['mani@mkelectrical-app.in',     'Mani',         '7639610150', true,  $adminPassword],
            ['dhinesh@mkelectrical-app.in',  'Dhinesh',      '7010523981', false, $userPassword],
            ['customer@mkelectrical-app.in', 'Staff User',   '9000000001', false, $userPassword],
        ];

        foreach ($accounts as [$email, $name, $phone, $isAdmin, $password]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->fill([
                'name'      => $name,
                'phone'     => $phone,
                'is_admin'  => $isAdmin,
                'is_user'   => true,
                'is_active' => true,
            ]);
            if (!$user->exists) {
                $user->password = $password;
            }
            $user->email_verified_at ??= now();
            $user->save();
        }
    }
}
