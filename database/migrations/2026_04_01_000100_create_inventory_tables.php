<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock is NEVER stored as one number. It is always calculated:
 *   opening + purchases - sales - orders + adjustments
 * so there is nothing that can go out of sync.
 * Every table carries company_id + account_year_id (year wise database).
 */
return new class extends Migration
{
    private function tenant(Blueprint $table): void
    {
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('account_year_id')->constrained()->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table) {
            $table->id();
            $this->tenant($table);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->string('source', 12)->default('manual');   // manual | calculated
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_year_id', 'product_id'], 'opening_unique');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $this->tenant($table);
            $table->string('purchase_no', 30);
            $table->date('purchase_date');
            $table->string('supplier_name');
            $table->string('supplier_phone', 20)->nullable();
            $table->string('invoice_no')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('purchase_image')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_year_id', 'purchase_no'], 'purchase_no_unique');
            $table->index('purchase_date');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $this->tenant($table);
            $table->string('sale_no', 30);
            $table->date('sale_date');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('reference');                  // who we gave the goods to
            $table->string('payment_mode', 20)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_year_id', 'sale_no'], 'sale_no_unique');
            $table->index('sale_date');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $this->tenant($table);
            $table->string('adjustment_no', 30);
            $table->date('adjustment_date');
            $table->string('reason', 30);                 // damage | lost | found | correction | expired | returned | other | order_cancel
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->boolean('is_auto')->default(false);   // created by the system (order cancel)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_year_id', 'adjustment_no'], 'adjustment_no_unique');
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_change', 12, 2);    // + increases stock, - decreases it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('opening_stocks');
    }
};
