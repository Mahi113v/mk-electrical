<?php

namespace Tests\Feature;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Bootstrapper;
use App\Support\Tenant;
use App\Support\TenantDb;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * company_id on every table, data kept apart per company, company logo / payment details,
 * and (MySQL / MariaDB only) a separate database per company with the tables the product admin picked.
 */
class CompanyDatabaseTest extends TestCase
{

    private AccountYear $year;
    private Company $a;
    private Company $b;
    private array $createdDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();
        // CREATE DATABASE commits by itself, so a rolled-back transaction cannot clean up: start every test from an empty schema
        $this->artisan('migrate:fresh');
        $this->year = Bootstrapper::year(Bootstrapper::fyStart());
        Bootstrapper::year(Bootstrapper::fyStart() - 1);
        $this->a = Company::create(['code' => 'AAA', 'name' => 'Alpha Co', 'status' => 'active']);
        $this->b = Company::create(['code' => 'BBB', 'name' => 'Beta Co', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(Tenant::class)->clear();
        if (DB::getDriverName() === 'mysql') {
            foreach ($this->createdDatabases as $db) {
                DB::statement("DROP DATABASE IF EXISTS `$db`");
            }
        }
        parent::tearDown();
    }

    /** a real 1x1 PNG (no GD needed) */
    private function png(string $name = 'a.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function needMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('needs MySQL / MariaDB (run with DB_CONNECTION=mysql)');
        }
    }

    private function productAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_active' => true]);
    }

    private function member(Company $co, string $role = 'company_admin'): User
    {
        $u = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $u->companies()->attach($co->id, ['role_id' => Role::where('code', $role)->value('id'), 'is_default' => true]);
        $u->years()->sync(AccountYear::pluck('id'));

        return $u;
    }

    private function adm(User $u): static
    {
        Sanctum::actingAs($u, ['admin']);

        return $this;
    }

    private function as(User $u, Company $co): static
    {
        Sanctum::actingAs($u, ['app']);
        app(Tenant::class)->clear();

        return $this->withHeaders(['X-Company-Id' => $co->id, 'X-Year-Id' => $this->year->id]);
    }

    private function day(): string
    {
        return $this->year->contains(now()->toDateString()) ? now()->toDateString() : $this->year->start_date->copy()->addDays(5)->toDateString();
    }

    private function db(string $name): string
    {
        return $this->createdDatabases[] = $name;
    }

    // ------------------------------------------------------------ every table has company_id

    public function test_every_business_table_has_company_id(): void
    {
        foreach (['products', 'customers', 'orders', 'order_items', 'purchases', 'purchase_items', 'sales', 'sale_items',
            'opening_stocks', 'stock_adjustments', 'stock_adjustment_items', 'work_status', 'work_list'] as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id'), "$table.company_id");
        }
    }

    // ------------------------------------------------------------ data is kept apart per company

    public function test_same_product_code_and_customer_phone_in_two_companies_never_mix(): void
    {
        $pa = Product::create(['company_id' => $this->a->id, 'product_name' => 'Wire', 'product_code' => 'W-1', 'rate' => 10]);
        $pb = Product::create(['company_id' => $this->b->id, 'product_name' => 'Wire', 'product_code' => 'W-1', 'rate' => 99]);   // same code: allowed
        $this->assertNotSame($pa->id, $pb->id);

        $ua = $this->member($this->a);
        $ub = $this->member($this->b);
        $this->as($ua, $this->a)->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pa->id);
        $this->as($ub, $this->b)->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pb->id);

        // B cannot use A's product in a purchase / order
        $this->as($ub, $this->b)->postJson('/api/purchases', ['purchase_date' => $this->day(), 'supplier_name' => 'S', 'items' => [['product_id' => $pa->id, 'quantity' => 1, 'rate' => 1]]])->assertStatus(422);

        // an order from the same phone number: each company keeps its OWN customer, the other is untouched
        foreach ([[$ua, $this->a, $pa, 'Ravi A'], [$ub, $this->b, $pb, 'Ravi B']] as [$u, $co, $p, $name]) {
            $this->as($u, $co)->postJson('/api/purchases', ['purchase_date' => $this->day(), 'supplier_name' => 'S', 'items' => [['product_id' => $p->id, 'quantity' => 5, 'rate' => 1]]])->assertStatus(201);
            $this->as($u, $co)->postJson('/api/orders', [
                'order_name' => 'O', 'order_date' => $this->day(), 'customer_name' => $name, 'customer_phone' => '9000000001',
                'items' => [['product_id' => $p->id, 'quantity' => 1]],
            ])->assertStatus(201);
        }
        app(Tenant::class)->clear();
        $this->assertSame(1, Customer::where('company_id', $this->a->id)->count());
        $this->assertSame('Ravi A', Customer::where('company_id', $this->a->id)->value('customer_name'));
        $this->assertSame('Ravi B', Customer::where('company_id', $this->b->id)->value('customer_name'));
        $this->assertSame(0, DB::table('order_items')->whereNull('company_id')->count());
        $this->assertSame(1, DB::table('order_items')->where('company_id', $this->a->id)->count());

        $this->as($ua, $this->a)->getJson('/api/customers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Ravi A');
        $this->as($ua, $this->a)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.products', 1)->assertJsonPath('data.customers', 1);
    }

    public function test_public_order_form_only_shows_and_uses_the_typed_company(): void
    {
        $this->a->update(['upi_id' => 'alpha@okbank', 'bank_name' => 'Alpha Bank', 'account_number' => '123456']);
        $pa = Product::create(['company_id' => $this->a->id, 'product_name' => 'Alpha Wire', 'product_code' => 'A', 'rate' => 10]);
        Product::create(['company_id' => $this->b->id, 'product_name' => 'Beta Cable', 'product_code' => 'B', 'rate' => 20]);
        $this->a->update(['allow_negative_stock' => true]);
        $this->b->update(['allow_negative_stock' => true]);

        $r = $this->getJson('/api/public/products?company=AAA')->assertOk();
        $r->assertJsonPath('data.company.name', 'Alpha Co')->assertJsonPath('data.company.logo_url', null)
            ->assertJsonPath('data.company.upi_id', 'alpha@okbank')->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.product_name', 'Alpha Wire');
        $this->getJson('/api/public/products?company=NOPE')->assertStatus(422);

        // a BBB order cannot contain AAA's product
        $this->postJson('/api/public/orders?company=BBB', ['customer_name' => 'X', 'customer_phone' => '9111111111', 'items' => [['product_id' => $pa->id, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/public/orders?company=AAA', ['customer_name' => 'X', 'customer_phone' => '9111111111', 'items' => [['product_id' => $pa->id, 'quantity' => 1]]])->assertStatus(201);

        // "I ordered before", strict mode: a full mobile number of THIS company's customers only
        config(['app.public_customer_search' => 'phone']);
        $this->getJson('/api/public/customers?company=AAA&q=9111111111')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'X');
        $this->getJson('/api/public/customers?company=BBB&q=9111111111')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/public/customers?company=AAA&q=X')->assertStatus(422);
        $this->getJson('/api/public/customers?company=AAA&q=91111')->assertOk()->assertJsonCount(0, 'data');   // a part of a number finds nothing

        // default mode: name or part of the mobile number, still only this company's customers
        config(['app.public_customer_search' => 'name_phone']);
        $this->getJson('/api/public/customers?company=AAA&q=91111')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/public/customers?company=AAA&q=Xyz')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/public/customers?company=BBB&q=91111')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_logo_image_and_payment_details_of_a_company(): void
    {
        Storage::fake('public');
        $adminA = $this->member($this->a);
        $this->adm($adminA)->post("/api/admin/companies/{$this->a->id}/logo", ['file' => $this->png('logo.png')], ['Accept' => 'application/json'])->assertOk();
        $this->adm($adminA)->post("/api/admin/companies/{$this->a->id}/image", ['file' => $this->png('shop.jpg')], ['Accept' => 'application/json'])->assertOk();
        $a = $this->a->fresh();
        Storage::disk('public')->assertExists($a->logo);
        Storage::disk('public')->assertExists($a->image);
        $this->assertNotNull($a->logo_url);
        $this->assertNotNull($a->image_url);

        // another company's admin cannot change it
        $this->adm($this->member($this->b))->post("/api/admin/companies/{$this->a->id}/logo", ['file' => $this->png('x.png')], ['Accept' => 'application/json'])->assertStatus(403);

        $this->adm($adminA)->putJson("/api/admin/companies/{$this->a->id}", [
            'code' => 'AAA', 'name' => 'Alpha Co', 'status' => 'active', 'upi_id' => 'alpha@okbank', 'upi_name' => 'Alpha', 'bank_name' => 'SBI',
            'account_holder' => 'Alpha Co', 'account_number' => '0011223344', 'ifsc' => 'SBIN0001', 'bank_branch' => 'Sivakasi',
        ])->assertOk()->assertJsonPath('data.upi_id', 'alpha@okbank');
        $this->adm($adminA)->putJson("/api/admin/companies/{$this->a->id}", ['code' => 'AAA', 'name' => 'Alpha Co', 'status' => 'active', 'upi_id' => 'not a upi'])->assertStatus(422);

        // the user login carries the logo + payment details for every screen
        $login = $this->postJson('/api/login', ['login' => $adminA->email, 'password' => 'password', 'year_id' => $this->year->id])->assertOk();
        $login->assertJsonPath('companies.0.upi_id', 'alpha@okbank');
        $this->assertNotNull($login->json('companies.0.logo_url'));

        $this->adm($adminA)->deleteJson("/api/admin/companies/{$this->a->id}/logo")->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing($a->logo);
    }

    public function test_database_screens_are_for_the_product_admin_only(): void
    {
        $ca = $this->member($this->a);
        foreach (['/api/admin/database/tables', '/api/admin/database/options', "/api/admin/companies/{$this->a->id}/database"] as $url) {
            $this->adm($ca)->getJson($url)->assertStatus(403);
        }
        $this->adm($ca)->getJson("/api/admin/database/download?company_id={$this->a->id}&database=x")->assertStatus(403);
        $this->adm($ca)->postJson('/api/admin/companies', ['code' => 'NEW', 'name' => 'New', 'status' => 'active'])->assertStatus(403);
        $this->adm($this->productAdmin())->getJson('/api/admin/database/tables')->assertOk();
        $this->assertTrue(collect($this->adm($this->productAdmin())->getJson('/api/admin/database/tables')->json('data.tables'))->firstWhere('name', 'products')['selectable']);
    }

    // ------------------------------------------------------------ own database (MySQL / MariaDB)

    public function test_company_with_its_own_database_gets_only_the_chosen_tables(): void
    {
        $this->needMysql();
        $admin = $this->productAdmin();
        $db = $this->db('mk_t_own1');

        // the table list: every table of the main database, shared ones are not selectable
        $tables = collect($this->adm($admin)->getJson('/api/admin/database/tables')->assertOk()->json('data.tables'));
        $this->assertTrue($tables->firstWhere('name', 'orders')['selectable']);
        $this->assertFalse($tables->firstWhere('name', 'users')['selectable']);
        $this->assertFalse($tables->firstWhere('name', 'companies')['selectable']);

        // choose only "orders": its customers / order_items / products come with it
        $r = $this->adm($admin)->postJson('/api/admin/companies', [
            'code' => 'OWN', 'name' => 'Own Db Co', 'status' => 'active', 'create_database' => true, 'database_name' => $db, 'tables' => ['orders'],
        ])->assertStatus(201);
        $company = Company::find($r->json('data.id'));
        $this->assertSame($db, $company->database_name);
        $this->assertEqualsCanonicalizing(['customers', 'order_items', 'orders', 'products'], $company->database_tables);
        $live = collect(DB::select('SELECT table_name t FROM information_schema.tables WHERE table_schema = ?', [$db]))->pluck('t')->all();
        $this->assertEqualsCanonicalizing(['customers', 'order_items', 'orders', 'products'], $live);

        // bad names / duplicates are refused and nothing is left behind
        $this->adm($admin)->postJson('/api/admin/companies', ['code' => 'BAD', 'name' => 'Bad', 'status' => 'active', 'create_database' => true, 'database_name' => $db, 'tables' => ['orders']])->assertStatus(422);
        $this->adm($admin)->postJson('/api/admin/companies', ['code' => 'BAD', 'name' => 'Bad', 'status' => 'active', 'create_database' => true, 'database_name' => 'mysql', 'tables' => ['orders']])->assertStatus(422);
        $this->adm($admin)->postJson('/api/admin/companies', ['code' => 'BAD', 'name' => 'Bad', 'status' => 'active', 'create_database' => true, 'database_name' => 'drop table;--', 'tables' => ['orders']])->assertStatus(422);
        $this->adm($admin)->postJson('/api/admin/companies', ['code' => 'BAD', 'name' => 'Bad', 'status' => 'active', 'create_database' => true, 'database_name' => 'mk_t_empty', 'tables' => []])->assertStatus(422);
        $this->assertNull(Company::where('code', 'BAD')->first());

        // the company's users work normally, but modules without tables are off
        $u = $this->member($company);
        $login = $this->postJson('/api/login', ['login' => $u->email, 'password' => 'password', 'year_id' => $this->year->id])->assertOk();
        $perms = $login->json('companies.0.permissions');
        $this->assertContains('orders.create', $perms);
        $this->assertNotContains('purchases.view', $perms);
        $this->assertNotContains('sales.view', $perms);
        $this->as($u, $company)->getJson('/api/purchases')->assertStatus(403);
        $this->as($u, $company)->getJson('/api/dashboard')->assertOk();

        // products, customers and orders are written to the COMPANY database, not to the main one
        $p = $this->as($u, $company)->postJson('/api/products', ['product_name' => 'Own Wire', 'rate' => 50])->assertStatus(201)->json('data');
        $this->assertSame('PRD-00001', $p['product_code']);
        $company->update(['allow_negative_stock' => true]);
        $this->as($u, $company)->postJson('/api/orders', [
            'order_name' => 'First', 'order_date' => $this->day(), 'customer_name' => 'Cust', 'customer_phone' => '9222222222',
            'items' => [['product_id' => $p['id'], 'quantity' => 2]],
        ])->assertStatus(201)->assertJsonPath('data.total', '100.00');
        $this->assertSame(1, DB::connection(TenantDb::CONNECTION)->table('orders')->count());
        $this->assertSame(1, DB::connection(TenantDb::CONNECTION)->table('order_items')->where('company_id', $company->id)->count());
        app(Tenant::class)->clear();
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('products')->where('company_id', $company->id)->count());

        // cancelling goes through (no stock adjustment table: nothing to put back) and stock / public form still work
        $list = $this->as($u, $company)->getJson('/api/orders')->assertOk();
        $oid = $list->json('data.0.id');
        $this->as($u, $company)->patchJson("/api/orders/$oid/status", ['status' => 'cancelled'])->assertOk();
        $this->as($u, $company)->getJson('/api/stock')->assertOk();   // the stock summary only needs products
    }

    public function test_tables_can_be_added_later_and_extra_tables_made(): void
    {
        $this->needMysql();
        $admin = $this->productAdmin();
        $db = $this->db('mk_t_own2');
        $company = Company::find($this->adm($admin)->postJson('/api/admin/companies', [
            'code' => 'OW2', 'name' => 'Own Two', 'status' => 'active', 'create_database' => true, 'database_name' => $db, 'tables' => ['products', 'customers'],
        ])->assertStatus(201)->json('data.id'));

        $info = $this->adm($admin)->getJson("/api/admin/companies/{$company->id}/database")->assertOk();
        $this->assertSame($db, $info->json('data.database'));
        $this->assertContains('purchases', collect($info->json('data.available'))->pluck('name')->all());

        // purchases need purchase_items + products: all are added in one go
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/tables", ['tables' => ['purchases']])->assertOk();
        $company->refresh();
        $this->assertEqualsCanonicalizing(['customers', 'products', 'purchase_items', 'purchases'], $company->database_tables);
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/tables", ['tables' => ['purchases']])->assertStatus(422);   // nothing new

        $u = $this->member($company);
        $p = $this->as($u, $company)->postJson('/api/products', ['product_name' => 'Cable', 'rate' => 5])->assertStatus(201)->json('data');
        $this->as($u, $company)->postJson('/api/purchases', ['purchase_date' => $this->day(), 'supplier_name' => 'S', 'items' => [['product_id' => $p['id'], 'quantity' => 4, 'rate' => 2]]])->assertStatus(201);
        $this->assertSame(1, DB::connection(TenantDb::CONNECTION)->table('purchase_items')->count());
        // a purchase item of this company cannot be deleted-orphaned: product is protected by its foreign key
        $this->as($u, $company)->deleteJson("/api/products/{$p['id']}")->assertStatus(422);

        // extra table of your own
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/custom-table", [
            'table' => 'vehicle_log', 'columns' => [['name' => 'vehicle_no', 'type' => 'string'], ['name' => 'km', 'type' => 'decimal']],
        ])->assertOk();
        $this->assertContains('vehicle_log', $company->fresh()->database_tables);
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/custom-table", ['table' => 'orders', 'columns' => [['name' => 'x', 'type' => 'string']]])->assertStatus(422);
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/custom-table", ['table' => 'vehicle_log', 'columns' => [['name' => 'x', 'type' => 'string']]])->assertStatus(422);
        $this->adm($admin)->postJson("/api/admin/companies/{$company->id}/database/custom-table", ['table' => 'ok_table', 'columns' => [['name' => 'bad col;', 'type' => 'string']]])->assertStatus(422);
        $shown = collect($this->adm($admin)->getJson("/api/admin/companies/{$company->id}/database")->json('data.tables'));
        $this->assertTrue($shown->firstWhere('name', 'vehicle_log')['custom']);
    }

    public function test_existing_company_gets_its_database_with_its_data_copied_and_can_be_downloaded(): void
    {
        $this->needMysql();
        $admin = $this->productAdmin();
        $db = $this->db('mk_t_own3');
        $ua = $this->member($this->a);
        $ub = $this->member($this->b);
        $pa = Product::create(['company_id' => $this->a->id, 'product_name' => 'Alpha Wire', 'product_code' => 'A-1', 'rate' => 10]);
        Product::create(['company_id' => $this->b->id, 'product_name' => 'Beta Wire', 'product_code' => 'B-1', 'rate' => 10]);
        $this->as($ua, $this->a)->postJson('/api/purchases', ['purchase_date' => $this->day(), 'supplier_name' => "O'Brien & Sons", 'items' => [['product_id' => $pa->id, 'quantity' => 7, 'rate' => 3]]])->assertStatus(201);
        $this->as($ua, $this->a)->postJson('/api/orders', ['order_name' => 'O', 'order_date' => $this->day(), 'customer_name' => 'Cust', 'customer_phone' => '9333333333', 'items' => [['product_id' => $pa->id, 'quantity' => 2]]])->assertStatus(201);

        $this->adm($admin)->postJson("/api/admin/companies/{$this->a->id}/database", ['database_name' => $db, 'tables' => ['orders', 'purchases', 'stock_adjustments', 'opening_stocks'], 'copy_data' => true])->assertOk();
        $this->assertSame($db, $this->a->fresh()->database_name);

        // A's data moved to its database (only A's rows), screens show the same numbers, B is untouched
        $this->assertSame(1, DB::select("SELECT COUNT(*) c FROM `$db`.products")[0]->c);
        $this->assertSame(1, DB::select("SELECT COUNT(*) c FROM `$db`.purchases")[0]->c);
        $stock = collect($this->as($ua, $this->a)->getJson('/api/stock')->assertOk()->json('data.rows'))->firstWhere('product_id', $pa->id);
        $this->assertEquals(5, $stock['closing']);   // 7 bought - 2 ordered
        $this->as($ub, $this->b)->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
        // foreign keys between the company's own tables exist
        $fks = collect(DB::select('SELECT table_name t FROM information_schema.referential_constraints WHERE constraint_schema = ?', [$db]))->pluck('t')->all();
        $this->assertContains('order_items', $fks);
        $this->assertContains('purchase_items', $fks);

        // download: options, own database dump, shared database dump (this company's rows only), wrong database refused
        $opts = collect($this->adm($admin)->getJson('/api/admin/database/options')->assertOk()->json('data'))->firstWhere('code', 'AAA');
        $this->assertSame([$db, TenantDb::mainDatabase()], collect($opts['databases'])->pluck('name')->all());
        $own = $this->adm($admin)->get("/api/admin/database/download?company_id={$this->a->id}&database=$db");
        $own->assertOk();
        $sql = $own->streamedContent();
        $this->assertStringContainsString('CREATE TABLE `purchases`', $sql);
        $this->assertStringContainsString("O\\'Brien & Sons", $sql);
        $this->assertStringContainsString('REFERENCES `orders`', $sql);
        $this->assertStringNotContainsString('Beta Wire', $sql);
        $shared = $this->adm($admin)->get("/api/admin/database/download?company_id={$this->b->id}&database=" . TenantDb::mainDatabase())->streamedContent();
        $this->assertStringContainsString('Beta Wire', $shared);
        $this->assertStringNotContainsString('Alpha Wire', $shared);
        $this->assertStringNotContainsString('REFERENCES `users`', $shared);
        $this->adm($admin)->getJson("/api/admin/database/download?company_id={$this->b->id}&database=$db")->assertStatus(422);

        // the dump can be restored into an empty database
        $restore = $this->db('mk_t_restore');
        DB::statement("CREATE DATABASE `$restore`");
        $file = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($file, "USE `$restore`;\n" . $sql);
        $cfg = config('database.connections.mysql');
        exec(sprintf('mysql -h%s -P%s -u%s %s < %s 2>&1', escapeshellarg($cfg['host']), escapeshellarg($cfg['port']), escapeshellarg($cfg['username']),
            $cfg['password'] ? '-p' . escapeshellarg($cfg['password']) : '', escapeshellarg($file)), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(1, DB::select("SELECT COUNT(*) c FROM `$restore`.purchases")[0]->c);
        unlink($file);

        // a company with data cannot be deleted; an empty one is deleted together with its database
        $this->adm($admin)->deleteJson("/api/admin/companies/{$this->a->id}")->assertStatus(422);
        $db2 = $this->db('mk_t_own4');
        $empty = Company::find($this->adm($admin)->postJson('/api/admin/companies', ['code' => 'EMP', 'name' => 'Empty', 'status' => 'active', 'create_database' => true, 'database_name' => $db2, 'tables' => ['products']])->json('data.id'));
        $this->adm($admin)->deleteJson("/api/admin/companies/{$empty->id}")->assertOk();
        $this->assertSame([], DB::select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$db2]));
    }
}
