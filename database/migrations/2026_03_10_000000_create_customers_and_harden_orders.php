<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - adds the missing `customers` table (controllers/models used it, no migration existed)
 * - orders.customer_id / orders.source, orders.user_id nullable (public web orders)
 * - quantity can be fractional (validation allowed 0.01), notes can be long
 * Safe to run on a fresh DB and on an existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('customer_name');
                $table->string('customer_phone', 20)->index();
                $table->string('whatsapp', 20)->nullable();
                $table->string('alternate_phone', 20)->nullable();
                $table->string('area')->nullable();
                $table->string('city')->nullable();
                $table->text('address')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('user_id')->index();
            }
            if (!Schema::hasColumn('orders', 'source')) {
                $table->string('source', 20)->default('admin')->after('status');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY user_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE orders MODIFY notes TEXT NULL');
            DB::statement('ALTER TABLE order_items MODIFY quantity DECIMAL(10,2) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('orders', 'customer_id')) {
                $table->dropIndex(['customer_id']);
                $table->dropColumn('customer_id');
            }
        });
        Schema::dropIfExists('customers');
    }
};
