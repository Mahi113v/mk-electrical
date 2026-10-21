<?php

use App\Support\Bootstrapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi company + account year + roles.
 *  - companies        : tenants. Products and customers stay shared, transactions carry company_id
 *  - account_years    : 1 April - 31 March, same for every company
 *  - company_years    : per company lock of a year (locked = view only)
 *  - roles            : permission sets the admin edits
 *  - company_user     : which user can open which company, with which role
 *  - user_year_access : which years a user can pick on the login page
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('gstin', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('status', 12)->default('active');           // active | suspended | inactive
            $table->boolean('allow_negative_stock')->default(false);   // false = cannot sell / order more than the stock
            $table->timestamps();
        });

        Schema::create('account_years', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();      // 2627
            $table->string('label', 10);              // 2026-27
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        Schema::create('company_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_year_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'account_year_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('user_year_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_year_id')->constrained()->cascadeOnDelete();

            $table->unique(['user_id', 'account_year_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
        });

        // reference data
        Bootstrapper::roles();
        Bootstrapper::years();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_login_at'));
        Schema::dropIfExists('user_year_access');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('company_years');
        Schema::dropIfExists('account_years');
        Schema::dropIfExists('companies');
    }
};
