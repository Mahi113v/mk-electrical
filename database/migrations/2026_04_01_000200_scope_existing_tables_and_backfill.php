<?php

use App\Models\AccountYear;
use App\Support\Bootstrapper;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id / account_year_id to orders + work_status and moves everything that
 * already exists into a default company ("MK") so no old data is lost.
 * Also: products.stock (one shared number) is converted to opening stock and dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'work_status'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->foreignId('account_year_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            });
        }

        if (DB::getDriverName() === 'mysql') {
            // old installs: status was an ENUM (no 'cancelled'), user FKs cascaded (deleting a user deleted products / work)
            DB::statement("ALTER TABLE orders MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
            foreach (['products', 'work_status'] as $name) {
                $fk = DB::selectOne(
                    "SELECT CONSTRAINT_NAME AS n FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'",
                    [$name]
                );
                if ($fk) {
                    DB::statement("ALTER TABLE `$name` DROP FOREIGN KEY `{$fk->n}`");
                }
                DB::statement("ALTER TABLE `$name` MODIFY user_id BIGINT UNSIGNED NULL");
                DB::statement("ALTER TABLE `$name` ADD CONSTRAINT `{$name}_user_id_foreign` FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
            }
        }

        $hasData = DB::table('users')->exists() || DB::table('orders')->exists() || DB::table('products')->exists();

        if ($hasData) {
            $company = Bootstrapper::defaultCompany();
            $current = Bootstrapper::year(Bootstrapper::fyStart());

            // make sure every order / work date has an account year
            foreach (['orders' => 'order_date', 'work_status' => 'work_date'] as $name => $col) {
                foreach (DB::table($name)->whereNotNull($col)->distinct()->pluck($col) as $d) {
                    Bootstrapper::year(Bootstrapper::fyStart(Carbon::parse($d)));
                }
            }

            $years = AccountYear::all();
            foreach (['orders' => 'order_date', 'work_status' => 'work_date'] as $name => $col) {
                DB::table($name)->whereNull('company_id')->update(['company_id' => $company->id]);
                foreach ($years as $y) {
                    DB::table($name)->whereNull('account_year_id')->whereBetween($col, [$y->start_date, $y->end_date])
                        ->update(['account_year_id' => $y->id]);
                }
                DB::table($name)->whereNull('account_year_id')->update(['account_year_id' => $current->id]);
            }

            // everybody can open the default company; old admins become super admin + company admin
            $adminRole = DB::table('roles')->where('code', 'company_admin')->value('id');
            $staffRole = DB::table('roles')->where('code', 'staff')->value('id');
            foreach (DB::table('users')->get() as $u) {
                DB::table('company_user')->insertOrIgnore([
                    'company_id' => $company->id, 'user_id' => $u->id, 'is_default' => true,
                    'role_id' => $u->is_admin ? $adminRole : $staffRole,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (AccountYear::pluck('id') as $yid) {
                    DB::table('user_year_access')->insertOrIgnore(['user_id' => $u->id, 'account_year_id' => $yid]);
                }
            }

            // the old single "stock" number becomes this year's opening stock
            if (Schema::hasColumn('products', 'stock')) {
                foreach (DB::table('products')->whereNotNull('stock')->get(['id', 'stock']) as $p) {
                    DB::table('opening_stocks')->insertOrIgnore([
                        'company_id' => $company->id, 'account_year_id' => $current->id, 'product_id' => $p->id,
                        'quantity' => max(0, $p->stock), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasColumn('products', 'stock')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('stock'));
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->bigInteger('stock')->nullable());
        foreach (['orders', 'work_status'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('account_year_id');
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
