<?php

namespace Tests\Feature;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WorkStatus;
use App\Services\StockService;
use App\Support\Bootstrapper;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The seeded sample data has to be complete for every page, consistent, and safe to run again. */
class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ChangeMe@123';   // seeder default when SEED_*_PASSWORD is not set

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed();
    }

    private function mk(): Company
    {
        return Company::where('code', 'MK')->firstOrFail();
    }

    private function cur(): AccountYear
    {
        return Bootstrapper::year(Bootstrapper::fyStart());
    }

    private function prev(): AccountYear
    {
        return Bootstrapper::year(Bootstrapper::fyStart() - 1);
    }

    private function login(string $url, string $email, ?int $yearId = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($url, ['login' => $email, 'password' => self::PASSWORD, 'year_id' => $yearId ?? $this->cur()->id]);
    }

    public function test_every_page_has_sample_data(): void
    {
        $mk = $this->mk();
        foreach ([$this->prev(), $this->cur()] as $year) {
            $this->assertGreaterThanOrEqual(3, Purchase::where('company_id', $mk->id)->where('account_year_id', $year->id)->count(), "purchases {$year->label}");
            $this->assertGreaterThanOrEqual(4, Sale::where('company_id', $mk->id)->where('account_year_id', $year->id)->count(), "sales {$year->label}");
            $this->assertGreaterThanOrEqual(3, Order::where('company_id', $mk->id)->where('account_year_id', $year->id)->count(), "orders {$year->label}");
            $this->assertGreaterThanOrEqual(2, StockAdjustment::where('company_id', $mk->id)->where('account_year_id', $year->id)->count(), "adjustments {$year->label}");
            $this->assertGreaterThanOrEqual(2, WorkStatus::where('company_id', $mk->id)->where('account_year_id', $year->id)->count(), "work {$year->label}");
        }

        // orders: every status, a web order, and the cancelled ones have their automatic stock adjustment
        $statuses = Order::where('company_id', $mk->id)->where('account_year_id', $this->cur()->id)->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['active', 'cancelled', 'completed', 'pending', 'upcoming'], $statuses);
        $this->assertTrue(Order::where('source', 'web')->whereNull('user_id')->exists());
        $cancelled = Order::where('company_id', $mk->id)->where('status', 'cancelled')->count();
        $this->assertSame($cancelled, StockAdjustment::where('company_id', $mk->id)->where('is_auto', true)->where('reason', 'order_cancel')->count());
        $this->assertTrue(StockAdjustment::where('company_id', $mk->id)->where('is_auto', false)->exists());

        // opening stock typed in the first year, left EMPTY this year so the "Fetch" button has something to do
        $this->assertGreaterThan(5, DB::table('opening_stocks')->where('company_id', $mk->id)->where('account_year_id', $this->prev()->id)->count());
        $this->assertSame(0, DB::table('opening_stocks')->where('company_id', $mk->id)->where('account_year_id', $this->cur()->id)->count());

        // the Reference column is filled, customers and products exist, two products got automatic codes
        $this->assertSame(0, Sale::whereNull('reference')->orWhere('reference', '')->count());
        $this->assertGreaterThanOrEqual(14, Product::count());
        $this->assertSame(['PRD-00001', 'PRD-00002'], Product::where('product_code', 'like', 'PRD-%')->orderBy('product_code')->pluck('product_code')->all());
        $this->assertGreaterThanOrEqual(8, DB::table('customers')->count());

        // three companies, each with data
        $this->assertSame(3, Company::count());
        foreach (['SAI', 'VA'] as $code) {
            $c = Company::where('code', $code)->first();
            $this->assertTrue(Purchase::where('company_id', $c->id)->exists() && Sale::where('company_id', $c->id)->exists(), $code);
        }
    }

    public function test_sample_stock_is_never_negative_and_totals_add_up(): void
    {
        $stock = app(StockService::class);

        foreach (Company::all() as $company) {
            foreach ([$this->prev(), $this->cur()] as $year) {
                foreach ($stock->movements($company->id, $year->id) as $productId => $row) {
                    $this->assertGreaterThanOrEqual(0, $row['closing'], "{$company->code} {$year->label} product #$productId");
                }
            }
        }

        // an order's stored total = its items + service charge
        foreach (Order::with('items')->get() as $order) {
            $this->assertEquals(round($order->items->sum('amount') + $order->service_charge, 2), (float) $order->total, "order #{$order->id}");
        }
        foreach (Purchase::with('items')->get() as $p) {
            $this->assertEquals($p->items->sum('amount'), (float) $p->total);
        }
    }

    public function test_seeder_can_run_again_without_duplicating_anything(): void
    {
        $count = fn () => [Purchase::count(), Sale::count(), Order::count(), StockAdjustment::count(), WorkStatus::count(), Product::count(), User::count(), DB::table('opening_stocks')->count()];
        $before = $count();

        $this->seed(SampleDataSeeder::class);
        $this->seed();

        $this->assertSame($before, $count());
    }

    public function test_sample_logins_follow_the_two_login_rules(): void
    {
        // product admin: /admin only
        foreach (['mahesh@mkelectrical-app.in', 'mathan@mkelectrical-app.in'] as $email) {
            $this->login('/api/login', $email)->assertStatus(403);
            $this->login('/api/admin/login', $email)->assertOk()->assertJsonPath('scope', 'platform');
        }

        // company admins: both logins, the admin login only manages their own company
        $this->login('/api/login', 'mani@mkelectrical-app.in')->assertOk()->assertJsonCount(2, 'companies')->assertJsonPath('company_id', $this->mk()->id);
        $this->login('/api/admin/login', 'mani@mkelectrical-app.in')->assertOk()->assertJsonPath('scope', 'company')->assertJsonCount(1, 'managed')->assertJsonPath('managed.0.code', 'MK');
        $this->login('/api/admin/login', 'sairaj@mkelectrical-app.in')->assertOk()->assertJsonPath('managed.0.code', 'SAI');
        $this->login('/api/admin/login', 'vagencies@mkelectrical-app.in')->assertOk()->assertJsonPath('managed.0.code', 'VA');
        $this->login('/api/login', 'sairaj@mkelectrical-app.in')->assertOk();

        // everybody else: user login only
        foreach (['dhinesh', 'manager', 'stock', 'sales', 'viewer', 'counter'] as $who) {
            $email = "$who@mkelectrical-app.in";
            $this->login('/api/login', $email)->assertOk()->assertJsonPath('company_id', $this->mk()->id);
            $this->login('/api/admin/login', $email)->assertStatus(403);
        }
    }

    public function test_the_sample_roles_see_only_their_pages(): void
    {
        $can = fn (string $email) => collect($this->login('/api/login', $email)->json('companies.0.permissions'));

        $this->assertTrue($can('stock@mkelectrical-app.in')->contains('purchases.create'));
        $this->assertFalse($can('stock@mkelectrical-app.in')->contains('sales.create'));
        $this->assertTrue($can('sales@mkelectrical-app.in')->contains('sales.create'));
        $this->assertFalse($can('sales@mkelectrical-app.in')->contains('purchases.create'));
        $this->assertTrue($can('counter@mkelectrical-app.in')->contains('sales.create'));      // company role "Counter Billing"
        $this->assertFalse($can('counter@mkelectrical-app.in')->contains('orders.view'));
        $this->assertSame([], $can('viewer@mkelectrical-app.in')->filter(fn ($p) => !str_ends_with($p, '.view'))->values()->all());
    }

    public function test_opening_stock_can_be_fetched_from_the_sample_previous_year(): void
    {
        $token = $this->login('/api/login', 'mani@mkelectrical-app.in')->json('token');
        $h = ['X-Company-Id' => $this->mk()->id, 'X-Year-Id' => $this->cur()->id];

        $this->app['auth']->forgetGuards();
        $first = $this->withToken($token)->withHeaders($h)->postJson('/api/opening-stock/refresh')->assertOk();
        $this->assertGreaterThan(10, $first->json('data.summary.updated'));
        $rows = collect($first->json('data.rows'));
        $this->assertTrue($rows->every(fn ($r) => $r['quantity'] === null || $r['quantity'] >= 0));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeaders($h)->postJson('/api/opening-stock/refresh')->assertOk()->assertJsonPath('data.summary.updated', 0);

        // the stock register of this year now starts from last year's closing
        $this->app['auth']->forgetGuards();
        $reg = $this->withToken($token)->withHeaders($h)->getJson('/api/stock')->assertOk()->json('data.rows');
        $this->assertGreaterThan(0, collect($reg)->sum('opening'));
    }
}
