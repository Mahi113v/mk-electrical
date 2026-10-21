<?php

namespace Tests\Feature;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Bootstrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $co;
    private AccountYear $year;
    private AccountYear $prevYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = Bootstrapper::year(Bootstrapper::fyStart());
        $this->prevYear = Bootstrapper::year(Bootstrapper::fyStart() - 1);
        $this->co = Company::create(['code' => 'AAA', 'name' => 'Alpha Co', 'status' => 'active']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_active' => true, 'password' => 'secret-pass1']);
    }

    /** user with a role in a company (default: company_admin in $this->co) and access to both years */
    private function member(string $role = 'company_admin', ?Company $co = null): User
    {
        $u = User::factory()->create(['is_admin' => false, 'is_active' => true, 'password' => 'secret-pass1']);
        $u->companies()->attach(($co ?? $this->co)->id, ['role_id' => Role::where('code', $role)->value('id'), 'is_default' => true]);
        $u->years()->sync([$this->year->id, $this->prevYear->id]);

        return $u;
    }

    /** signed in through the /admin login */
    private function adm(User $u): static
    {
        Sanctum::actingAs($u, ['admin']);

        return $this;
    }

    private function as(User $u, ?Company $co = null, ?AccountYear $year = null): static
    {
        Sanctum::actingAs($u, ['app']);

        return $this->withHeaders([
            'X-Company-Id' => ($co ?? $this->co)->id, 'X-Year-Id' => ($year ?? $this->year)->id,
        ]);
    }

    private function product(string $name = 'Wire', float $rate = 100): Product
    {
        return Product::create(['product_name' => $name, 'product_code' => 'T-' . uniqid(), 'rate' => $rate]);
    }

    private function dateIn(AccountYear $y = null): string
    {
        $y ??= $this->year;

        return $y->contains(now()->toDateString()) ? now()->toDateString() : $y->start_date->copy()->addDays(10)->toDateString();
    }

    private function buy(Product $p, float $qty, ?AccountYear $y = null, ?User $by = null): void
    {
        $this->as($by ?? $this->member(), null, $y)->postJson('/api/purchases', [
            'purchase_date' => $this->dateIn($y), 'supplier_name' => 'ABC Traders',
            'items' => [['product_id' => $p->id, 'quantity' => $qty, 'rate' => 50]],
        ])->assertStatus(201);
    }

    private function stockOf(Product $p, ?AccountYear $y = null): float
    {
        $rows = $this->as($this->member(), null, $y)->getJson('/api/stock')->assertOk()->json('data.rows');

        return (float) collect($rows)->firstWhere('product_id', $p->id)['closing'];
    }

    // ---------------------------------------------------------------- login / context

    public function test_login_needs_company_and_year_access_and_returns_permissions(): void
    {
        $u = $this->member('viewer');

        $r = $this->postJson('/api/login', ['login' => $u->email, 'password' => 'secret-pass1', 'year_id' => $this->year->id])->assertOk();
        $r->assertJsonPath('company_id', $this->co->id)->assertJsonPath('year_id', $this->year->id);
        $this->assertContains('orders.view', $r->json('companies.0.permissions'));
        $this->assertNotContains('orders.create', $r->json('companies.0.permissions'));

        $other = AccountYear::create(['code' => '9900', 'label' => '2099-00', 'start_date' => '2099-04-01', 'end_date' => '2100-03-31']);
        $this->postJson('/api/login', ['login' => $u->email, 'password' => 'secret-pass1', 'year_id' => $other->id])->assertStatus(403);

        $nobody = User::factory()->create(['password' => 'secret-pass1', 'is_admin' => false]);
        $this->postJson('/api/login', ['login' => $nobody->email, 'password' => 'secret-pass1'])->assertStatus(403);
        $this->postJson('/api/login', ['login' => $u->email, 'password' => 'bad'])->assertStatus(401);
        $this->getJson('/api/public/account-years')->assertOk()->assertJsonStructure(['data' => [['id', 'label']]]);
    }

    public function test_company_screens_need_headers_and_real_access(): void
    {
        $u = $this->member();
        Sanctum::actingAs($u, ['app']);
        $this->getJson('/api/orders')->assertStatus(422);

        $beta = Company::create(['code' => 'BBB', 'name' => 'Beta Co', 'status' => 'active']);
        $this->as($u, $beta)->getJson('/api/orders')->assertStatus(403);   // not assigned to Beta

        $beta->update(['status' => 'suspended']);
        $v = $this->member('company_admin', $beta);
        $this->as($v, $beta)->getJson('/api/orders')->assertStatus(403);   // suspended company

        $noYear = $this->member();
        $noYear->years()->sync([$this->prevYear->id]);
        $this->as($noYear)->getJson('/api/orders')->assertStatus(403);     // year not allowed
    }

    // ---------------------------------------------------------------- company isolation

    public function test_one_company_cannot_see_or_touch_another_companys_data(): void
    {
        $p = $this->product();
        $alpha = $this->member();
        $this->buy($p, 10, null, $alpha);
        $orderId = $this->as($alpha)->postJson('/api/orders', $this->orderPayload($p, 2))->assertStatus(201)->json('data.id');

        $beta = Company::create(['code' => 'BBB', 'name' => 'Beta Co', 'status' => 'active']);
        $b = $this->member('company_admin', $beta);

        $this->as($b, $beta)->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->as($b, $beta)->getJson('/api/purchases')->assertOk()->assertJsonCount(0, 'data');
        $this->as($b, $beta)->getJson("/api/orders/$orderId")->assertStatus(404);
        $this->as($b, $beta)->deleteJson("/api/orders/$orderId")->assertStatus(404);
        $this->as($b, $beta)->putJson("/api/orders/$orderId", $this->orderPayload($p, 1))->assertStatus(404);
        $this->assertSame(0.0, $this->stockOfFor($p, $b, $beta));   // Beta never bought anything
    }

    private function stockOfFor(Product $p, User $u, Company $co): float
    {
        $rows = $this->as($u, $co)->getJson('/api/stock')->json('data.rows');

        return (float) collect($rows)->firstWhere('product_id', $p->id)['closing'];
    }

    // ---------------------------------------------------------------- permissions

    public function test_admin_decides_what_a_role_can_do(): void
    {
        $viewer = $this->member('viewer');
        $this->as($viewer)->getJson('/api/sales')->assertOk();
        $this->as($viewer)->postJson('/api/sales', [])->assertStatus(403);
        $this->as($viewer)->getJson('/api/admin/users')->assertStatus(403);

        // admin gives the viewer role the sales.create permission
        $admin = $this->superAdmin();
        $role = Role::where('code', 'viewer')->first();
        $this->adm($admin)->putJson("/api/admin/roles/{$role->id}", [
            'name' => 'Viewer', 'permissions' => array_merge($role->permissions, ['sales.create', 'bogus.permission']),
        ])->assertOk();

        $this->assertContains('sales.create', $role->fresh()->permissions);
        $this->assertNotContains('bogus.permission', $role->fresh()->permissions);
        $this->as($viewer)->postJson('/api/sales', [])->assertStatus(422);   // allowed now, but empty form is invalid
    }

    // ---------------------------------------------------------------- purchase

    public function test_purchase_adds_new_products_with_unique_code_and_tops_up_existing_ones(): void
    {
        $u = $this->member();
        Product::create(['product_name' => 'Old', 'product_code' => 'PRD-00007', 'rate' => 5]);
        Product::create(['product_name' => 'Manual', 'product_code' => 'CAM-01', 'rate' => 5]);

        $r = $this->as($u)->postJson('/api/purchases', [
            'purchase_date' => $this->dateIn(), 'supplier_name' => 'ABC Traders', 'invoice_no' => 'INV-1',
            'items' => [
                ['product_name' => 'LED Bulb 9W', 'quantity' => 10, 'rate' => 40, 'selling_rate' => 60],
                ['product_name' => 'MCB 32A', 'quantity' => 5, 'rate' => 200],
                ['product_name' => '', 'quantity' => 0, 'rate' => 0],   // empty form row is ignored
            ],
        ])->assertStatus(201);

        $r->assertJsonCount(2, 'data.new_products');
        $r->assertJsonPath('data.new_products.0.product_code', 'PRD-00008');
        $r->assertJsonPath('data.new_products.1.product_code', 'PRD-00009');
        $r->assertJsonPath('data.purchase.total', '1400.00');
        $this->assertMatchesRegularExpression('/^PUR-\d{4}-0001$/', $r->json('data.purchase.purchase_no'));
        $led = Product::where('product_name', 'LED Bulb 9W')->first();
        $this->assertEquals(60, $led->rate);   // selling rate used for a NEW product

        // same name again (different case) = existing product, stock goes up, no duplicate product
        $r2 = $this->as($u)->postJson('/api/purchases', [
            'purchase_date' => $this->dateIn(), 'supplier_name' => 'XYZ',
            'items' => [['product_name' => 'led bulb 9w', 'quantity' => 4, 'rate' => 41], ['product_id' => $led->id, 'quantity' => 1, 'rate' => 41]],
        ])->assertStatus(201);
        $r2->assertJsonCount(0, 'data.new_products');
        $this->assertSame(1, Product::where('product_name', 'LED Bulb 9W')->count());
        $this->assertSame(15.0, $this->stockOf($led));
        $this->assertStringEndsWith('-0002', $r2->json('data.purchase.purchase_no'));
    }

    public function test_purchase_delete_cannot_make_stock_negative(): void
    {
        $p = $this->product();
        $u = $this->member();
        $id = $this->as($u)->postJson('/api/purchases', ['purchase_date' => $this->dateIn(), 'supplier_name' => 'S',
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'rate' => 1]]])->json('data.purchase.id');
        $this->as($u)->postJson('/api/sales', $this->salePayload($p, 4))->assertStatus(201);

        $this->as($u)->deleteJson("/api/purchases/$id")->assertStatus(422);
        $this->assertSame(1.0, $this->stockOf($p));
    }

    // ---------------------------------------------------------------- sales

    private function salePayload(Product $p, float $qty, array $o = []): array
    {
        return $o + ['sale_date' => $this->dateIn(), 'reference' => 'Ravi (shop)', 'customer_name' => 'Walk in',
            'items' => [['product_id' => $p->id, 'quantity' => $qty, 'rate' => 120]]];
    }

    public function test_sale_keeps_reference_checks_stock_and_reduces_it(): void
    {
        $p = $this->product();
        $u = $this->member();
        $this->buy($p, 10, null, $u);

        $this->as($u)->postJson('/api/sales', $this->salePayload($p, 3, ['reference' => '']))->assertStatus(422);   // reference needed
        $this->as($u)->postJson('/api/sales', $this->salePayload($p, 11))->assertStatus(422);                        // more than stock

        $r = $this->as($u)->postJson('/api/sales', $this->salePayload($p, 3))->assertStatus(201);
        $r->assertJsonPath('data.reference', 'Ravi (shop)')->assertJsonPath('data.total', '360.00');
        $this->assertSame(7.0, $this->stockOf($p));

        $this->as($u)->getJson('/api/sales?search=Ravi')->assertOk()->assertJsonCount(1, 'data');

        // the company can allow negative stock
        $this->co->update(['allow_negative_stock' => true]);
        $this->as($u)->postJson('/api/sales', $this->salePayload($p, 20))->assertStatus(201);
        $this->assertSame(-13.0, $this->stockOf($p));

        $this->as($u)->deleteJson('/api/sales/' . $r->json('data.id'))->assertOk();
        $this->assertSame(-10.0, $this->stockOf($p));
    }

    // ---------------------------------------------------------------- orders + cancel -> stock adjustment

    private function orderPayload(Product $p, float $qty, array $o = []): array
    {
        return $o + ['order_name' => 'House wiring', 'order_date' => $this->dateIn(), 'customer_name' => 'Ravi', 'customer_phone' => '9000011111',
            'service_charge' => 50, 'items' => [['product_id' => $p->id, 'quantity' => $qty, 'rate' => 120]]];
    }

    public function test_order_uses_stock_and_cancel_puts_it_back_through_an_adjustment(): void
    {
        $p = $this->product();
        $u = $this->member();
        $this->buy($p, 10, null, $u);

        $r = $this->as($u)->postJson('/api/orders', $this->orderPayload($p, 4, ['total' => 1]))->assertStatus(201);
        $r->assertJsonPath('data.total', '530.00');   // server total, client total ignored
        $id = $r->json('data.id');
        $this->assertSame(6.0, $this->stockOf($p));
        $this->as($u)->postJson('/api/orders', $this->orderPayload($p, 7))->assertStatus(422);   // only 6 left

        // cancel
        $this->as($u)->patchJson("/api/orders/$id/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10.0, $this->stockOf($p));
        $adj = $this->as($u)->getJson('/api/stock-adjustments')->assertOk()->json('data');
        $this->assertCount(1, $adj);
        $this->assertSame('order_cancel', $adj[0]['reason']);
        $this->assertTrue($adj[0]['is_auto']);
        $this->assertEquals(4, $adj[0]['items'][0]['quantity_change']);

        // a cancelled order cannot be edited, and the automatic adjustment cannot be deleted by hand
        $this->as($u)->putJson("/api/orders/$id", $this->orderPayload($p, 1, ['status' => 'cancelled']))->assertStatus(422);
        $this->as($u)->deleteJson("/api/stock-adjustments/{$adj[0]['id']}")->assertStatus(422);

        // re-open: stock goes out again, adjustment disappears
        $this->as($u)->patchJson("/api/orders/$id/status", ['status' => 'pending'])->assertOk();
        $this->assertSame(6.0, $this->stockOf($p));
        $this->as($u)->getJson('/api/stock-adjustments')->assertJsonCount(0, 'data');

        // cancelling then deleting leaves no stray adjustment
        $this->as($u)->patchJson("/api/orders/$id/status", ['status' => 'cancelled'])->assertOk();
        $this->as($u)->deleteJson("/api/orders/$id")->assertOk();
        $this->assertSame(10.0, $this->stockOf($p));
        $this->as($u)->getJson('/api/stock-adjustments')->assertJsonCount(0, 'data');
    }

    public function test_cancel_permission_is_separate(): void
    {
        $p = $this->product();
        $admin = $this->member();
        $this->buy($p, 5, null, $admin);
        $id = $this->as($admin)->postJson('/api/orders', $this->orderPayload($p, 1))->json('data.id');

        $staff = $this->member('staff');   // can update orders but cannot cancel
        $this->as($staff)->patchJson("/api/orders/$id/status", ['status' => 'completed'])->assertOk();
        $this->as($staff)->patchJson("/api/orders/$id/status", ['status' => 'cancelled'])->assertStatus(403);
        $this->as($staff)->deleteJson("/api/orders/$id")->assertStatus(403);
    }

    // ---------------------------------------------------------------- stock adjustment

    public function test_manual_stock_adjustment_increase_decrease_and_no_negative(): void
    {
        $p = $this->product();
        $u = $this->member();
        $this->buy($p, 10, null, $u);

        $dec = $this->as($u)->postJson('/api/stock-adjustments', ['adjustment_date' => $this->dateIn(), 'reason' => 'damage',
            'items' => [['product_id' => $p->id, 'type' => 'decrease', 'quantity' => 3]]])->assertStatus(201);
        $this->assertSame(7.0, $this->stockOf($p));
        $this->assertStringStartsWith('ADJ-', $dec->json('data.adjustment_no'));

        $this->as($u)->postJson('/api/stock-adjustments', ['reason' => 'lost', 'items' => [['product_id' => $p->id, 'type' => 'decrease', 'quantity' => 8]]])->assertStatus(422);
        $this->as($u)->postJson('/api/stock-adjustments', ['reason' => 'nonsense', 'items' => [['product_id' => $p->id, 'type' => 'increase', 'quantity' => 1]]])->assertStatus(422);

        $this->as($u)->postJson('/api/stock-adjustments', ['reason' => 'found', 'items' => [['product_id' => $p->id, 'type' => 'increase', 'quantity' => 2]]])->assertStatus(201);
        $this->assertSame(9.0, $this->stockOf($p));

        $this->as($u)->deleteJson('/api/stock-adjustments/' . $dec->json('data.id'))->assertOk();
        $this->assertSame(12.0, $this->stockOf($p));
    }

    // ---------------------------------------------------------------- opening stock

    public function test_opening_stock_is_fetched_from_previous_years_purchases_and_sales(): void
    {
        $p = $this->product('Camera');
        $q = $this->product('Wire');
        $u = $this->member();

        // previous year: buy 20, sell 5, order 3, adjust -2  => closes with 10
        $this->buy($p, 20, $this->prevYear, $u);
        $this->as($u, null, $this->prevYear)->postJson('/api/sales', ['sale_date' => $this->dateIn($this->prevYear), 'reference' => 'R', 'customer_name' => 'x',
            'items' => [['product_id' => $p->id, 'quantity' => 5]]])->assertStatus(201);
        $this->as($u, null, $this->prevYear)->postJson('/api/orders', ['order_name' => 'o', 'order_date' => $this->dateIn($this->prevYear), 'customer_name' => 'c', 'customer_phone' => '1',
            'items' => [['product_id' => $p->id, 'quantity' => 3]]])->assertStatus(201);
        $this->as($u, null, $this->prevYear)->postJson('/api/stock-adjustments', ['adjustment_date' => $this->dateIn($this->prevYear), 'reason' => 'damage',
            'items' => [['product_id' => $p->id, 'type' => 'decrease', 'quantity' => 2]]])->assertStatus(201);

        $this->as($u)->getJson('/api/opening-stock')->assertOk()->assertJsonPath('data.previous_year.label', $this->prevYear->label);

        $r = $this->as($u)->postJson('/api/opening-stock/refresh')->assertOk();
        $r->assertJsonPath('data.summary.updated', 1);
        $row = collect($r->json('data.rows'))->firstWhere('product_id', $p->id);
        $this->assertEquals(10, $row['quantity']);
        $this->assertSame('calculated', $row['source']);
        $this->assertEquals(20, $row['previous']['purchased']);
        $this->assertEquals(5, $row['previous']['sold']);
        $this->assertSame(10.0, $this->stockOf($p));          // opening is part of the current stock
        $this->assertNull(collect($r->json('data.rows'))->firstWhere('product_id', $q->id)['quantity']);

        // second fetch changes nothing
        $this->as($u)->postJson('/api/opening-stock/refresh')->assertOk()->assertJsonPath('data.summary.updated', 0)->assertJsonPath('data.summary.unchanged', 1);

        // a number typed by hand is protected from refresh unless force
        $this->as($u)->putJson("/api/opening-stock/{$p->id}", ['quantity' => 99])->assertOk();
        $this->as($u)->postJson('/api/opening-stock/refresh')->assertOk()->assertJsonPath('data.summary.skipped_manual', 1);
        $this->assertSame(99.0, $this->stockOf($p));
        $this->as($u)->postJson('/api/opening-stock/refresh', ['force' => true])->assertOk();
        $this->assertSame(10.0, $this->stockOf($p));
    }

    public function test_opening_stock_has_nothing_to_fetch_in_the_first_year(): void
    {
        $this->as($this->member(), null, $this->prevYear)->postJson('/api/opening-stock/refresh')->assertStatus(422);
        $this->as($this->member('viewer'))->postJson('/api/opening-stock/refresh')->assertStatus(403);
    }

    // ---------------------------------------------------------------- years

    public function test_locked_year_is_view_only_and_dates_must_be_inside_the_year(): void
    {
        $p = $this->product();
        $u = $this->member();
        $admin = $this->superAdmin();

        $this->as($u)->postJson('/api/purchases', ['purchase_date' => $this->prevYear->start_date->toDateString(), 'supplier_name' => 'S',
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'rate' => 1]]])->assertStatus(422);

        $this->adm($admin)->putJson("/api/admin/account-years/{$this->year->id}/companies/{$this->co->id}", ['is_locked' => true])->assertOk();
        $this->as($u)->getJson('/api/purchases')->assertOk();
        $this->as($u)->postJson('/api/purchases', ['purchase_date' => $this->dateIn(), 'supplier_name' => 'S',
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'rate' => 1]]])->assertStatus(423);
        $this->as($u, null, $this->prevYear)->getJson('/api/purchases')->assertOk();   // other year not locked

        $this->adm($admin)->putJson("/api/admin/account-years/{$this->year->id}/companies/{$this->co->id}", ['is_locked' => false])->assertOk();
        $this->as($u)->postJson('/api/purchases', ['purchase_date' => $this->dateIn(), 'supplier_name' => 'S',
            'items' => [['product_id' => $p->id, 'quantity' => 1, 'rate' => 1]]])->assertStatus(201);
    }

    // ---------------------------------------------------------------- date range (registers)

    private function day(int $offset): string
    {
        return $this->year->start_date->copy()->addDays($offset)->toDateString();
    }

    public function test_every_register_filters_by_date_range(): void
    {
        $p = $this->product();
        $u = $this->member();
        $mk = fn (int $d) => [
            'purchase' => ['purchase_date' => $this->day($d), 'supplier_name' => 'S', 'items' => [['product_id' => $p->id, 'quantity' => 10, 'rate' => 5]]],
            'sale'     => ['sale_date' => $this->day($d), 'reference' => 'R', 'items' => [['product_id' => $p->id, 'quantity' => 1]]],
            'order'    => ['order_name' => 'o', 'order_date' => $this->day($d), 'customer_name' => 'c', 'customer_phone' => '1', 'items' => [['product_id' => $p->id, 'quantity' => 1]]],
            'adjust'   => ['adjustment_date' => $this->day($d), 'reason' => 'found', 'items' => [['product_id' => $p->id, 'type' => 'increase', 'quantity' => 1]]],
            'work'     => ['work_date' => $this->day($d), 'items' => [['work_place' => 'x', 'worker_name' => 'y']]],
        ];

        foreach ([5, 40] as $d) {   // one early, one late document of every kind
            $m = $mk($d);
            $this->as($u)->postJson('/api/purchases', $m['purchase'])->assertStatus(201);
            $this->as($u)->postJson('/api/sales', $m['sale'])->assertStatus(201);
            $this->as($u)->postJson('/api/orders', $m['order'])->assertStatus(201);
            $this->as($u)->postJson('/api/stock-adjustments', $m['adjust'])->assertStatus(201);
            $this->as($u)->postJson('/api/works', $m['work'])->assertStatus(201);
        }

        foreach (['purchases', 'sales', 'orders', 'stock-adjustments', 'works'] as $path) {
            $all = $this->as($u)->getJson("/api/$path")->assertOk()->json('data');
            $early = $this->as($u)->getJson("/api/$path?from={$this->day(0)}&to={$this->day(10)}")->assertOk()->json('data');
            $late = $this->as($u)->getJson("/api/$path?from={$this->day(30)}")->assertOk()->json('data');
            $exact = $this->as($u)->getJson("/api/$path?from={$this->day(5)}&to={$this->day(5)}")->assertOk()->json('data');
            $none = $this->as($u)->getJson("/api/$path?from={$this->day(10)}&to={$this->day(20)}")->assertOk()->json('data');

            $this->assertCount(2, $all, $path);
            $this->assertCount(1, $early, "$path early");
            $this->assertCount(1, $late, "$path late");
            $this->assertCount(1, $exact, "$path exact day is included");
            $this->assertCount(0, $none, "$path middle");
        }

        $this->as($u)->getJson("/api/orders?from={$this->day(10)}&to={$this->day(1)}")->assertStatus(422);   // to before from
        $this->as($u)->getJson('/api/orders?from=banana')->assertStatus(422);
    }

    public function test_stock_register_has_opening_at_the_from_date_and_closing_at_the_to_date(): void
    {
        $p = $this->product();
        $u = $this->member();
        $post = fn ($url, $body) => $this->as($u)->postJson($url, $body)->assertStatus(201);

        $post('/api/purchases', ['purchase_date' => $this->day(10), 'supplier_name' => 'S', 'items' => [['product_id' => $p->id, 'quantity' => 10, 'rate' => 5]]]);
        $post('/api/sales', ['sale_date' => $this->day(20), 'reference' => 'R', 'items' => [['product_id' => $p->id, 'quantity' => 3]]]);
        $post('/api/stock-adjustments', ['adjustment_date' => $this->day(30), 'reason' => 'damage', 'items' => [['product_id' => $p->id, 'type' => 'decrease', 'quantity' => 2]]]);
        $this->as($u)->putJson("/api/opening-stock/{$p->id}", ['quantity' => 4])->assertOk();

        $row = fn (array $q) => collect($this->as($u)->getJson('/api/stock?' . http_build_query($q))->assertOk()->json('data.rows'))->firstWhere('product_id', $p->id);

        // whole year (no dates): old behaviour
        $y = $row([]);
        $this->assertEquals([4, 10, 3, 0, -2, 9], [$y['opening'], $y['purchased'], $y['sold'], $y['ordered'], $y['adjusted'], $y['closing']]);

        // from the first day to day 15: only the purchase has happened
        $a = $row(['from' => $this->day(0), 'to' => $this->day(15)]);
        $this->assertEquals([4, 10, 0, 0, 0, 14], [$a['opening'], $a['purchased'], $a['sold'], $a['ordered'], $a['adjusted'], $a['closing']]);

        // day 15 to day 25: opening already includes the purchase (4 + 10), then the sale of 3
        $b = $row(['from' => $this->day(15), 'to' => $this->day(25)]);
        $this->assertEquals([14, 0, 3, 0, 0, 11], [$b['opening'], $b['purchased'], $b['sold'], $b['ordered'], $b['adjusted'], $b['closing']]);

        // day 25 to the end: opening 11, adjustment -2
        $c = $row(['from' => $this->day(25), 'to' => $this->year->end_date->toDateString()]);
        $this->assertEquals([11, 0, 0, 0, -2, 9], [$c['opening'], $c['purchased'], $c['sold'], $c['ordered'], $c['adjusted'], $c['closing']]);
    }

    // ---------------------------------------------------------------- public order form

    public function test_public_order_goes_to_the_company_pending_and_ignores_client_prices(): void
    {
        $p = $this->product('Camera', 100);
        $this->buy($p, 3);
        $empty = $this->product('Out of stock');

        $list = $this->getJson('/api/public/products?company=aaa')->assertOk();
        $this->assertSame(['Camera'], collect($list->json('data.products'))->pluck('product_name')->all());   // no stock -> not offered
        $this->getJson('/api/public/products?company=NOPE')->assertStatus(422);

        $this->postJson('/api/public/orders?company=AAA', [
            'customer_name' => 'Walk in', 'customer_phone' => '9000022222', 'status' => 'completed',
            'items' => [['product_id' => $p->id, 'quantity' => 2, 'rate' => 1, 'total' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.total', '200.00');

        $this->assertDatabaseHas('orders', ['source' => 'web', 'status' => 'pending', 'user_id' => null, 'company_id' => $this->co->id, 'account_year_id' => $this->year->id]);
        $this->postJson('/api/public/orders?company=AAA', ['customer_name' => 'x', 'customer_phone' => '1',
            'items' => [['product_id' => $p->id, 'quantity' => 5]]])->assertStatus(422);   // only 1 left
    }

    // ---------------------------------------------------------------- admin console

    public function test_admin_creates_company_user_with_roles_and_years(): void
    {
        $admin = $this->superAdmin();
        $this->adm($admin);

        $company = $this->postJson('/api/admin/companies', ['code' => 'vag', 'name' => 'V Agencies', 'status' => 'active'])->assertStatus(201)->json('data');
        $this->assertSame('VAG', $company['code']);
        $this->postJson('/api/admin/companies', ['code' => 'VAG', 'name' => 'Dup', 'status' => 'active'])->assertStatus(422);

        $role = Role::where('code', 'sales_executive')->first();
        $u = $this->postJson('/api/admin/users', [
            'name' => 'Sales Guy', 'email' => 'sg@x.in', 'password' => 'secret-pass1',
            'companies' => [['company_id' => $company['id'], 'role_id' => $role->id, 'is_default' => true], ['company_id' => $this->co->id, 'role_id' => $role->id]],
            'year_ids' => [$this->year->id],
        ])->assertStatus(201);
        $u->assertJsonCount(2, 'data.companies')->assertJsonPath('data.companies.0.role_name', 'Sales Executive');
        $this->assertArrayHasKey('company_id', $u->json('data.companies.0'));

        $this->postJson('/api/login', ['login' => 'sg@x.in', 'password' => 'secret-pass1', 'year_id' => $this->year->id])
            ->assertOk()->assertJsonPath('company_id', $company['id']);
        $this->postJson('/api/login', ['login' => 'sg@x.in', 'password' => 'secret-pass1', 'year_id' => $this->prevYear->id])->assertStatus(403);

        $this->adm($admin)->getJson('/api/admin/users?company_id=' . $company['id'])->assertOk()->assertJsonCount(1, 'data');
        $this->adm($admin)->getJson('/api/admin/roles')->assertOk()->assertJsonStructure(['catalogue' => [['key', 'label', 'actions']]]);

        // next account year: created once, users with access to the last year get it too
        $next = $this->postJson('/api/admin/account-years')->assertStatus(201)->json('data');
        $this->assertSame(sprintf('%d-%02d', Bootstrapper::fyStart() + 1, (Bootstrapper::fyStart() + 2) % 100), $next['label']);
        $this->assertDatabaseHas('user_year_access', ['user_id' => $u->json('data.id'), 'account_year_id' => $next['id']]);

        // a company that has data cannot be deleted
        $this->member();
        $this->as($this->member())->postJson('/api/purchases', ['purchase_date' => $this->dateIn(), 'supplier_name' => 'S', 'items' => [['product_name' => 'New thing', 'quantity' => 1, 'rate' => 1]]])->assertStatus(201);
        $this->adm($admin)->deleteJson("/api/admin/companies/{$this->co->id}")->assertStatus(422);
        $this->adm($admin)->deleteJson("/api/admin/companies/{$company['id']}")->assertOk();
    }

    public function test_roles_in_use_or_system_cannot_be_deleted_and_company_admin_keeps_everything(): void
    {
        $admin = $this->superAdmin();
        $this->adm($admin);
        $ca = Role::where('code', 'company_admin')->first();

        $this->putJson("/api/admin/roles/{$ca->id}", ['name' => 'Company Admin', 'permissions' => ['orders.view']])->assertOk();
        $this->assertGreaterThan(20, count($ca->fresh()->permissions));
        $this->deleteJson("/api/admin/roles/{$ca->id}")->assertStatus(422);

        $custom = $this->postJson('/api/admin/roles', ['name' => 'Night Shift', 'permissions' => ['orders.view']])->assertStatus(201)->json('data');
        $this->assertSame('night_shift', $custom['code']);
        $u = $this->member();
        $u->companies()->updateExistingPivot($this->co->id, ['role_id' => $custom['id']]);
        $this->deleteJson("/api/admin/roles/{$custom['id']}")->assertStatus(422);
    }

    public function test_admin_cannot_lock_themselves_out_and_users_cannot_use_admin_endpoints(): void
    {
        $admin = $this->superAdmin();
        $this->adm($admin)->putJson("/api/admin/users/{$admin->id}", ['name' => 'x', 'email' => $admin->email, 'is_admin' => false])->assertStatus(422);
        $this->adm($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);
        $this->adm($this->member('staff'))->getJson('/api/admin/companies')->assertStatus(403);
        $this->adm($this->member('manager'))->getJson('/api/admin/companies')->assertStatus(403);
    }

    public function test_api_without_a_token_is_401_json(): void
    {
        $this->getJson('/api/orders')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    // ---------------------------------------------------------------- two logins: /login (users) and /admin (product admin + company admin)

    /** each request with its own token (the test app keeps the first logged in user otherwise) */
    private function withTok(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function login(string $url, User $u): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($url, ['login' => $u->email, 'password' => 'secret-pass1', 'year_id' => $this->year->id]);
    }

    public function test_user_login_and_admin_login_allow_different_people(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // this test signs in many times
        $product = $this->superAdmin();
        $companyAdmin = $this->member('company_admin');

        // product admin: admin login yes, user login NO
        $this->login('/api/login', $product)->assertStatus(403);
        $this->login('/api/admin/login', $product)->assertOk()->assertJsonPath('scope', 'platform')->assertJsonStructure(['token']);

        // company admin: both. In the admin login he only manages his own company
        $this->login('/api/login', $companyAdmin)->assertOk()->assertJsonPath('portal', 'app');
        $this->login('/api/admin/login', $companyAdmin)->assertOk()->assertJsonPath('scope', 'company')
            ->assertJsonPath('managed.0.code', 'AAA')->assertJsonCount(1, 'managed');

        // manager, stock keeper, sales, staff, viewer, custom roles: user login only
        foreach (['manager', 'stock_keeper', 'sales_executive', 'staff', 'viewer'] as $role) {
            $u = $this->member($role);
            $this->login('/api/login', $u)->assertOk();
            $this->login('/api/admin/login', $u)->assertStatus(403)->assertJsonPath('message', 'This account cannot sign in here.');
        }

        // wrong password is the same 401 on both, and a company admin of a SUSPENDED company has nothing to manage
        $this->postJson('/api/admin/login', ['login' => $product->email, 'password' => 'nope'])->assertStatus(401);
        $this->postJson('/api/login', ['login' => $companyAdmin->email, 'password' => 'nope'])->assertStatus(401);
        $this->co->update(['status' => 'suspended']);
        $this->login('/api/admin/login', $companyAdmin)->assertStatus(403);
    }

    public function test_login_tries_are_limited_per_account_not_per_office(): void
    {
        $a = $this->member('staff');
        $b = $this->member('staff');

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/login', ['login' => $a->email, 'password' => 'wrong'])->assertStatus(401);
        }
        $this->postJson('/api/login', ['login' => $a->email, 'password' => 'wrong'])->assertStatus(429);        // 9th guess on ONE account is blocked
        $this->postJson('/api/admin/login', ['login' => $a->email, 'password' => 'wrong'])->assertStatus(429);   // ... on both logins
        $this->login('/api/login', $b)->assertOk();                                                              // a colleague on the same address is not
    }

    public function test_a_token_only_works_in_the_portal_it_was_made_for(): void
    {
        $companyAdmin = $this->member('company_admin');
        $userToken = $this->login('/api/login', $companyAdmin)->json('token');
        $adminToken = $this->login('/api/admin/login', $companyAdmin)->json('token');
        $h = ['X-Company-Id' => $this->co->id, 'X-Year-Id' => $this->year->id];

        $this->withTok($userToken)->withHeaders($h)->getJson('/api/orders')->assertOk();
        $this->withTok($userToken)->getJson('/api/admin/users')->assertStatus(403);       // user token cannot open the admin console
        $this->withTok($adminToken)->withHeaders($h)->getJson('/api/orders')->assertStatus(403);   // admin token cannot open company screens
        $this->withTok($adminToken)->getJson('/api/admin/users')->assertOk();

        // /api/user answers for the portal of the token
        $this->withTok($adminToken)->getJson('/api/user')->assertOk()->assertJsonPath('portal', 'admin')->assertJsonPath('scope', 'company');
        $this->withTok($userToken)->getJson('/api/user')->assertOk()->assertJsonPath('portal', 'app')->assertJsonStructure(['companies', 'years']);
    }

    public function test_product_admin_has_no_company_screens(): void
    {
        $product = $this->superAdmin();
        $product->companies()->attach($this->co->id, ['role_id' => Role::where('code', 'company_admin')->value('id'), 'is_default' => true]);
        $product->years()->sync([$this->year->id]);

        $this->as($product)->getJson('/api/orders')->assertStatus(403);
        $this->as($product)->getJson('/api/dashboard')->assertStatus(403);
    }

    // ---------------------------------------------------------------- company admin: only his own company

    public function test_company_admin_manages_only_his_own_company(): void
    {
        $beta = Company::create(['code' => 'BBB', 'name' => 'Beta Co', 'status' => 'active']);
        $adminA = $this->member('company_admin');
        $adminB = $this->member('company_admin', $beta);
        $staffA = $this->member('staff');
        $staffB = $this->member('staff', $beta);
        $shared = $this->member('staff');
        $shared->companies()->attach($beta->id, ['role_id' => Role::where('code', 'viewer')->value('id'), 'is_default' => false]);
        $sales = Role::where('code', 'sales_executive')->first();

        // --- who he sees
        $list = collect($this->adm($adminA)->getJson('/api/admin/users')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$adminA->id, $staffA->id, $shared->id], $list->pluck('id')->all());   // not B's people
        $this->assertSame(['AAA'], $list->firstWhere('id', $shared->id)['companies'] ? collect($list->firstWhere('id', $shared->id)['companies'])->pluck('code')->all() : []);   // shared user: only HIS company shown
        $this->assertTrue($list->firstWhere('id', $shared->id)['shared']);
        $this->adm($adminA)->getJson('/api/admin/companies')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AAA');
        $this->adm($adminA)->getJson("/api/admin/companies/{$beta->id}/users")->assertStatus(403);

        // --- company: profile yes (not the code / status), create / delete no
        $this->adm($adminA)->putJson("/api/admin/companies/{$this->co->id}", ['code' => 'HACK', 'name' => 'Alpha Renamed', 'status' => 'inactive', 'city' => 'Madurai', 'allow_negative_stock' => true])->assertOk();
        $fresh = $this->co->fresh();
        $this->assertSame(['AAA', 'active', 'Alpha Renamed', 'Madurai', true], [$fresh->code, $fresh->status, $fresh->name, $fresh->city, $fresh->allow_negative_stock]);
        $this->adm($adminA)->putJson("/api/admin/companies/{$beta->id}", ['code' => 'BBB', 'name' => 'x', 'status' => 'active'])->assertStatus(403);
        $this->adm($adminA)->postJson('/api/admin/companies', ['code' => 'NEW', 'name' => 'New', 'status' => 'active'])->assertStatus(403);
        $this->adm($adminA)->deleteJson("/api/admin/companies/{$this->co->id}")->assertStatus(403);

        // --- creating users: only into his own company, never a product admin
        $mk = fn (array $o = []) => $o + ['name' => 'New Guy', 'email' => 'new@x.in', 'password' => 'secret-pass1', 'year_ids' => [$this->year->id],
            'companies' => [['company_id' => $this->co->id, 'role_id' => $sales->id, 'is_default' => true]]];
        $this->adm($adminA)->postJson('/api/admin/users', $mk(['companies' => [['company_id' => $beta->id, 'role_id' => $sales->id]]]))->assertStatus(403);
        $this->adm($adminA)->postJson('/api/admin/users', $mk(['is_admin' => true]))->assertStatus(422);
        $this->adm($adminA)->postJson('/api/admin/users', $mk(['companies' => []]))->assertStatus(422);
        $new = $this->adm($adminA)->postJson('/api/admin/users', $mk())->assertStatus(201)->json('data');
        $this->assertFalse($new['is_admin']);
        $this->login('/api/login', User::find($new['id']))->assertOk();   // the new user can sign in at /login

        // --- editing: B's people do not exist for him; a shared user only gets a role change in HIS company
        $this->adm($adminA)->putJson("/api/admin/users/{$staffB->id}", ['name' => 'x', 'email' => $staffB->email])->assertStatus(404);
        $before = $shared->name;
        $this->adm($adminA)->putJson("/api/admin/users/{$shared->id}", [
            'name' => 'Renamed By A', 'email' => $shared->email, 'password' => 'hijacked-pass1', 'is_active' => false,
            'companies' => [['company_id' => $this->co->id, 'role_id' => $sales->id, 'is_default' => true]],
        ])->assertOk();
        $shared->refresh();
        $this->assertSame($before, $shared->name);                                   // details untouched
        $this->assertTrue($shared->is_active);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-pass1', $shared->password));
        $this->assertSame($sales->id, (int) $shared->companies()->where('companies.id', $this->co->id)->first()->pivot->role_id);   // role in A changed
        $this->assertSame(Role::where('code', 'viewer')->value('id'), (int) $shared->companies()->where('companies.id', $beta->id)->first()->pivot->role_id);   // B untouched

        // removing him from HIS company keeps the membership in B
        $this->adm($adminA)->putJson("/api/admin/users/{$shared->id}", ['name' => $shared->name, 'email' => $shared->email, 'companies' => []])->assertStatus(422);   // company admin must keep >= 1 company
        $this->adm($adminA)->putJson("/api/admin/users/{$staffA->id}", ['name' => 'Staff A2', 'email' => $staffA->email, 'is_active' => false, 'year_ids' => [$this->year->id],
            'companies' => [['company_id' => $this->co->id, 'role_id' => Role::where('code', 'viewer')->value('id')]]])->assertOk();
        $this->assertFalse($staffA->fresh()->is_active);   // not shared -> he may deactivate

        // nobody edits himself, nobody deletes a user
        $this->adm($adminA)->putJson("/api/admin/users/{$adminA->id}", ['name' => 'x', 'email' => $adminA->email, 'companies' => [['company_id' => $this->co->id, 'role_id' => $sales->id]]])->assertStatus(422);
        $this->adm($adminA)->deleteJson("/api/admin/users/{$staffA->id}")->assertStatus(403);

        // --- years: lock his own company only, cannot make a new year
        $y = $this->adm($adminA)->getJson('/api/admin/account-years')->assertOk();
        $y->assertJsonCount(1, 'companies')->assertJsonPath('can_create', false);
        $this->adm($adminA)->putJson("/api/admin/account-years/{$this->year->id}/companies/{$this->co->id}", ['is_locked' => true])->assertOk();
        $this->adm($adminA)->putJson("/api/admin/account-years/{$this->year->id}/companies/{$beta->id}", ['is_locked' => true])->assertStatus(403);
        $this->adm($adminA)->postJson('/api/admin/account-years')->assertStatus(403);

        // --- dashboard
        $this->adm($adminA)->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.scope', 'company')->assertJsonPath('data.companies', 1);
    }

    public function test_company_admin_makes_roles_for_his_company_only(): void
    {
        $beta = Company::create(['code' => 'BBB', 'name' => 'Beta Co', 'status' => 'active']);
        $adminA = $this->member('company_admin');
        $viewer = Role::where('code', 'viewer')->first();

        $list = $this->adm($adminA)->getJson('/api/admin/roles')->assertOk();
        $this->assertFalse(collect($list->json('data'))->firstWhere('id', $viewer->id)['editable']);   // shared roles are read only for him

        $this->adm($adminA)->putJson("/api/admin/roles/{$viewer->id}", ['name' => 'Hacked', 'permissions' => ['orders.delete']])->assertStatus(403);
        $this->adm($adminA)->postJson('/api/admin/roles', ['name' => 'Global one', 'permissions' => ['orders.view']])->assertStatus(403);              // needs a company
        $this->adm($adminA)->postJson('/api/admin/roles', ['name' => 'Beta one', 'company_id' => $beta->id, 'permissions' => ['orders.view']])->assertStatus(403);

        $mine = $this->adm($adminA)->postJson('/api/admin/roles', ['name' => 'Counter', 'company_id' => $this->co->id, 'permissions' => ['sales.create', 'sales.view', 'bogus.x']])->assertStatus(201)->json('data');
        $this->assertSame('counter_aaa', $mine['code']);
        $this->assertSame(['sales.create', 'sales.view'], collect($mine['permissions'])->sort()->values()->all());
        $this->adm($adminA)->putJson("/api/admin/roles/{$mine['id']}", ['name' => 'Counter 2', 'permissions' => ['sales.view']])->assertOk();

        // a role made for company A cannot be given to a user of company B
        $adminB = $this->member('company_admin', $beta);
        $staffB = $this->member('staff', $beta);
        $this->adm($adminB)->putJson("/api/admin/users/{$staffB->id}", ['name' => $staffB->name, 'email' => $staffB->email,
            'companies' => [['company_id' => $beta->id, 'role_id' => $mine['id']]]])->assertStatus(422);
        $this->assertNotContains($mine['id'], collect($this->adm($adminB)->getJson('/api/admin/roles')->json('data'))->pluck('id')->all());   // B does not even see it

        // and it works for A: the user then has only that role's permissions
        $cashier = $this->member('staff');
        $this->adm($adminA)->putJson("/api/admin/users/{$cashier->id}", ['name' => $cashier->name, 'email' => $cashier->email,
            'companies' => [['company_id' => $this->co->id, 'role_id' => $mine['id']]]])->assertOk();
        $this->as($cashier)->getJson('/api/sales')->assertOk();
        $this->as($cashier)->getJson('/api/orders')->assertStatus(403);

        $this->adm($adminA)->deleteJson("/api/admin/roles/{$mine['id']}")->assertStatus(422);   // in use
    }

    // ---------------------------------------------------------------- products, dashboard, work status

    public function test_product_code_is_automatic_when_empty_and_unique_when_given(): void
    {
        $u = $this->member();
        $a = $this->as($u)->postJson('/api/products', ['product_name' => 'Switch', 'rate' => 40])->assertStatus(201)->json('data');
        $b = $this->as($u)->postJson('/api/products', ['product_name' => 'Socket', 'rate' => 30])->assertStatus(201)->json('data');
        $this->assertSame(['PRD-00001', 'PRD-00002'], [$a['product_code'], $b['product_code']]);
        $this->as($u)->postJson('/api/products', ['product_name' => 'Dup', 'product_code' => 'PRD-00001', 'rate' => 1])->assertStatus(422);

        $this->as($this->member('staff'))->postJson('/api/products', ['product_name' => 'x', 'rate' => 1])->assertStatus(403);
        $this->buy(Product::find($a['id']), 1);
        $this->as($u)->deleteJson("/api/products/{$a['id']}")->assertStatus(422);   // used in a purchase
        $this->as($u)->deleteJson("/api/products/{$b['id']}")->assertOk();
    }

    public function test_dashboard_and_work_status_are_per_company(): void
    {
        $p = $this->product();
        $u = $this->member();
        $this->buy($p, 2, null, $u);
        $this->as($u)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.purchase_total', 100)->assertJsonCount(12, 'data.monthly_revenue');

        $id = $this->as($u)->postJson('/api/works', ['work_date' => $this->dateIn(), 'items' => [['work_place' => 'Sivakasi', 'worker_name' => 'Mani']]])->assertStatus(201)->json('data.id');
        $beta = Company::create(['code' => 'BBB', 'name' => 'Beta', 'status' => 'active']);
        $this->as($this->member('company_admin', $beta), $beta)->getJson("/api/works/$id")->assertStatus(404);
        $this->as($u)->putJson("/api/works/$id", ['items' => [['work_place' => 'Virudhunagar', 'worker_name' => 'Raja']]])->assertOk();
        $this->as($u)->deleteJson("/api/works/$id")->assertOk();
    }
}
