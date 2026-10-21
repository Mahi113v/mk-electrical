<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRODUCTS TABLE
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_code')->unique();
            $table->decimal('rate', 10, 2);
            $table->bigInteger('stock')->nullable();   // legacy, moved to opening_stocks and dropped by a later migration
            $table->string('image')->nullable();
            $table->timestamps();
        });

        // ORDERS TABLE
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_name');
            $table->date('order_date')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('whatsapp')->nullable();
            $table->string('alternate_phone')->nullable();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->decimal('service_charge', 10, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('order_amount', 15, 2)->nullable();
            $table->string('referral')->default('Mathan');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');   // pending|upcoming|active|completed|cancelled
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->decimal('quantity', 10, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('amount', 15, 2);
            $table->decimal('total', 15, 2);
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};

