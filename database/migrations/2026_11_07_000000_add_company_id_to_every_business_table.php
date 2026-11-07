<?php

use App\Support\Bootstrapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY business table now carries company_id:
 *   products, customers, order_items, purchase_items, sale_items, stock_adjustment_items, work_list
 * (orders, purchases, sales, stock_adjustments, opening_stocks, work_status already had it).
 *
 * Old data: products / customers used to be shared by all companies. Each one is given to the company that
 * uses it. A product / customer used by SEVERAL companies is copied, one copy per company, and the
 * orders / purchases / sales / stock rows of each company are pointed to its own copy.
 * Product code is now unique per company (not for the whole database).
 */
return new class extends Migration
{
    private const TABLES = ['products', 'customers', 'order_items', 'purchase_items', 'sale_items', 'stock_adjustment_items', 'work_list'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->after('id'));
        }

        // unique product code per company
        Schema::table('products', fn (Blueprint $t) => $t->dropUnique(['product_code']));

        $hasData = DB::table('products')->exists() || DB::table('customers')->exists();
        if ($hasData) {
            // unused products / customers go to the MK company, or to the first company if there is no MK
            $default = DB::table('companies')->where('code', 'MK')->value('id') ?? DB::table('companies')->orderBy('id')->value('id') ?? Bootstrapper::defaultCompany()->id;
            $this->backfill((int) $default);
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (self::TABLES as $name) {
                DB::statement("ALTER TABLE `$name` MODIFY company_id BIGINT UNSIGNED NOT NULL");
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }
        Schema::table('products', fn (Blueprint $t) => $t->unique(['company_id', 'product_code'], 'products_company_code_unique'));
        Schema::table('customers', fn (Blueprint $t) => $t->index(['company_id', 'customer_phone'], 'customers_company_phone_index'));
    }

    private function backfill(int $default): void
    {
        // items follow their parent document
        $this->fromParent('order_items', 'order_id', 'orders');
        $this->fromParent('purchase_items', 'purchase_id', 'purchases');
        $this->fromParent('sale_items', 'sale_id', 'sales');
        $this->fromParent('stock_adjustment_items', 'stock_adjustment_id', 'stock_adjustments');
        $this->fromParent('work_list', 'work_status_id', 'work_status');

        // ---- products
        $usage = [];   // product_id => [company_id => true]
        foreach (['order_items', 'purchase_items', 'sale_items', 'stock_adjustment_items'] as $t) {
            foreach (DB::table($t)->whereNotNull('company_id')->select('product_id', 'company_id')->distinct()->get() as $r) {
                $usage[$r->product_id][$r->company_id] = true;
            }
        }
        foreach (DB::table('opening_stocks')->select('product_id', 'company_id')->distinct()->get() as $r) {
            $usage[$r->product_id][$r->company_id] = true;
        }
        foreach (DB::table('products')->get() as $p) {
            $companies = array_keys($usage[$p->id] ?? []);
            sort($companies);
            $own = $companies[0] ?? $default;
            DB::table('products')->where('id', $p->id)->update(['company_id' => $own]);
            foreach (array_slice($companies, 1) as $other) {
                $copy = (array) $p;
                unset($copy['id']);
                $copy['company_id'] = $other;
                $newId = DB::table('products')->insertGetId($copy);
                foreach (['order_items', 'purchase_items', 'sale_items', 'stock_adjustment_items', 'opening_stocks'] as $t) {
                    DB::table($t)->where('product_id', $p->id)->where('company_id', $other)->update(['product_id' => $newId]);
                }
            }
        }

        // ---- customers (used by orders and sales)
        $usage = [];
        foreach (['orders', 'sales'] as $t) {
            foreach (DB::table($t)->whereNotNull('customer_id')->select('customer_id', 'company_id')->distinct()->get() as $r) {
                if ($r->company_id) {
                    $usage[$r->customer_id][$r->company_id] = true;
                }
            }
        }
        foreach (DB::table('customers')->get() as $c) {
            $companies = array_keys($usage[$c->id] ?? []);
            sort($companies);
            DB::table('customers')->where('id', $c->id)->update(['company_id' => $companies[0] ?? $default]);
            foreach (array_slice($companies, 1) as $other) {
                $copy = (array) $c;
                unset($copy['id']);
                $copy['company_id'] = $other;
                $newId = DB::table('customers')->insertGetId($copy);
                foreach (['orders', 'sales'] as $t) {
                    DB::table($t)->where('customer_id', $c->id)->where('company_id', $other)->update(['customer_id' => $newId]);
                }
            }
        }

        // anything left without a company (orphans) goes to the default company
        foreach (self::TABLES as $t) {
            DB::table($t)->whereNull('company_id')->update(['company_id' => $default]);
        }
    }

    private function fromParent(string $child, string $fk, string $parent): void
    {
        DB::statement("UPDATE `$child` SET company_id = (SELECT `$parent`.company_id FROM `$parent` WHERE `$parent`.id = `$child`.`$fk`)");
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropForeign(['company_id']));
        }
        Schema::table('customers', fn (Blueprint $t) => $t->dropIndex('customers_company_phone_index'));
        Schema::table('products', fn (Blueprint $t) => $t->dropUnique('products_company_code_unique'));
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
        // the old rule: one code in the whole database. Codes that two companies share get the company id appended.
        foreach (DB::table('products')->select('product_code')->groupBy('product_code')->havingRaw('COUNT(*) > 1')->pluck('product_code') as $code) {
            foreach (DB::table('products')->where('product_code', $code)->orderBy('id')->get(['id'])->slice(1) as $p) {
                DB::table('products')->where('id', $p->id)->update(['product_code' => $code . '-' . $p->id]);
            }
        }
        Schema::table('products', fn (Blueprint $t) => $t->unique('product_code'));
    }
};
