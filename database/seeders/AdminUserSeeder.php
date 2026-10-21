<?php

namespace Database\Seeders;

use App\Models\AccountYear;
use App\Models\Role;
use App\Models\User;
use App\Support\Bootstrapper;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Roles, account years, the first company ("MK") and the first logins.
     *   PRODUCT ADMIN  (signs in at /admin only)  : Mahesh, Mathan          - password SEED_ADMIN_PASSWORD
     *   COMPANY ADMIN  (/login and /admin)         : Mani  (company MK)      - password SEED_USER_PASSWORD
     *   STAFF          (/login only)               : Dhinesh                 - password SEED_USER_PASSWORD
     * Passwords come from .env. Quote them if they contain #. Existing users are never overwritten.
     */
    public function run(): void
    {
        Bootstrapper::roles();
        Bootstrapper::years();
        $company = Bootstrapper::defaultCompany();

        $adminPassword = env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe@123';
        $userPassword  = env('SEED_USER_PASSWORD') ?: 'ChangeMe@123';

        $accounts = [
            // email, name, phone, product admin?, password, role in company MK (null = none)
            ['mahesh@mkelectrical-app.in',  'Admin Mahesh', '9952678113', true,  $adminPassword, null],
            ['mathan@mkelectrical-app.in',  'Admin Mathan', '9585009824', true,  $adminPassword, null],
            ['mani@mkelectrical-app.in',    'Mani',         '7639610150', false, $userPassword,  'company_admin'],
            ['dinesh@mkelectrical-app.in',  'Dinesh',       '7010523982', false, $userPassword,  'staff'],
        ];

        foreach ($accounts as [$email, $name, $phone, $isAdmin, $password, $roleCode]) {
            $user = User::firstOrNew(['email' => $email]);
            $isNew = !$user->exists;
            $user->fill(['name' => $name, 'phone' => $phone, 'is_user' => true, 'is_active' => true]);
            if ($isNew) {
                $user->is_admin = $isAdmin;
                $user->password = $password;
            }
            $user->email_verified_at ??= now();
            $user->save();

            if ($isNew && $roleCode) {
                $user->companies()->syncWithoutDetaching([$company->id => ['role_id' => Role::where('code', $roleCode)->value('id'), 'is_default' => true]]);
                $user->years()->syncWithoutDetaching(AccountYear::pluck('id'));
            }
        }
    }
}
