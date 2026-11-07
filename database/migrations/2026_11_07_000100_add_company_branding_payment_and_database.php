<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company page: logo (already there), image, UPI + bank details.
 * Own database: database_name = a separate MySQL database for this company (null = the shared database),
 * database_tables = the tables that were created in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('image')->nullable()->after('logo');
            $t->string('upi_id', 80)->nullable();
            $t->string('upi_name', 120)->nullable();
            $t->string('bank_name')->nullable();
            $t->string('account_holder')->nullable();
            $t->string('account_number', 40)->nullable();
            $t->string('ifsc', 20)->nullable();
            $t->string('bank_branch')->nullable();
            $t->string('database_name', 64)->nullable()->unique();
            $t->json('database_tables')->nullable();
            $t->timestamp('database_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropUnique(['database_name']);
            $t->dropColumn(['image', 'upi_id', 'upi_name', 'bank_name', 'account_holder', 'account_number', 'ifsc', 'bank_branch',
                'database_name', 'database_tables', 'database_created_at']);
        });
    }
};
