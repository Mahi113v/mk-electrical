<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_active' => true, 'password' => 'secret-pass1']);
    }

    private function staff(): User
    {
        return User::factory()->create(['is_admin' => false, 'is_active' => true, 'password' => 'secret-pass1']);
    }

    private function product(array $o = []): Product
    {
        return Product::create($o + [
            'user_id' => $this->admin()->id, 'product_name' => 'Wire', 'product_code' => 'W' . uniqid(), 'rate' => 100, 'stock' => 10,
        ]);
    }

    private function orderPayload(Product $p, array $o = []): array
    {
        return $o + [
            'order_name' => 'House wiring', 'customer_name' => 'Ravi', 'customer_phone' => '9000011111',
            'service_charge' => 50,
            'items' => [['product_id' => $p->id, 'quantity' => 3, 'rate' => 120]],
        ];
    }

    public function test_login_by_email_and_phone_and_wrong_password(): void
    {
        $u = $this->admin();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'secret-pass1'])
            ->assertOk()->assertJsonPath('user.role', 'admin')->assertJsonStructure(['token']);
        $u->update(['phone' => '9123456780']);
        $this->postJson('/api/login', ['login' => '9123456780', 'password' => 'secret-pass1'])->assertOk();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'nope'])->assertStatus(401);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $u = $this->staff();
        $u->update(['is_active' => false]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'secret-pass1'])->assertStatus(403);
    }

    public function test_api_requires_token_and_returns_json_401(): void
    {
        $this->getJson('/api/orders')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_staff_cannot_use_admin_endpoints_but_admin_can(): void
    {
        $this->actingAs($this->staff(), 'sanctum');
        $this->postJson('/api/products', ['product_name' => 'x', 'product_code' => 'x', 'rate' => 1])->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);

        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/products', ['product_name' => 'Switch', 'product_code' => 'SW1', 'rate' => 40, 'stock' => 5])->assertStatus(201);
        $this->postJson('/api/products', ['product_name' => 'Switch', 'product_code' => 'SW1', 'rate' => 40])->assertStatus(422);
        $this->getJson('/api/users')->assertOk();
    }

    public function test_order_totals_are_calculated_on_server_and_stock_moves(): void
    {
        $p = $this->product();
        $this->actingAs($this->staff(), 'sanctum');

        $r = $this->postJson('/api/orders', $this->orderPayload($p, ['total' => 1, 'order_amount' => 1]))->assertStatus(201);
        $r->assertJsonPath('data.order_amount', '360.00')->assertJsonPath('data.total', '410.00');
        $this->assertSame(7, $p->fresh()->stock);
        $this->assertDatabaseHas('customers', ['customer_phone' => '9000011111']);

        $id = $r->json('data.id');
        $this->putJson("/api/orders/$id", $this->orderPayload($p, ['items' => [['product_id' => $p->id, 'quantity' => 1]]]))->assertOk();
        $this->assertSame(9, $p->fresh()->stock);

        $this->deleteJson("/api/orders/$id")->assertOk();
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_cannot_order_more_than_stock(): void
    {
        $p = $this->product(['stock' => 2]);
        $this->actingAs($this->staff(), 'sanctum');
        $this->postJson('/api/orders', $this->orderPayload($p))->assertStatus(422);
        $this->assertSame(2, $p->fresh()->stock);
    }

    public function test_staff_cannot_edit_or_delete_other_peoples_orders(): void
    {
        $p = $this->product();
        $this->actingAs($this->staff(), 'sanctum');
        $id = $this->postJson('/api/orders', $this->orderPayload($p))->json('data.id');

        $this->actingAs($this->staff(), 'sanctum');
        $this->putJson("/api/orders/$id", $this->orderPayload($p))->assertStatus(403);
        $this->deleteJson("/api/orders/$id")->assertStatus(403);

        $this->actingAs($this->admin(), 'sanctum');
        $this->patchJson("/api/orders/$id/status", ['status' => 'Completed'])->assertOk();
        $this->getJson("/api/orders/$id/print")->assertOk();
    }

    public function test_public_web_order_works_without_login_and_ignores_client_prices(): void
    {
        $p = $this->product();
        $this->getJson('/api/public/products')->assertOk()->assertJsonMissingPath('data.0.stock');
        $this->postJson('/api/public/orders', [
            'customer_name' => 'Walk in', 'customer_phone' => '9000022222',
            'items' => [['product_id' => $p->id, 'quantity' => 2, 'rate' => 1, 'total' => 1]],
            'status' => 'completed',
        ])->assertStatus(201)->assertJsonPath('data.total', '200.00');

        $this->assertDatabaseHas('orders', ['source' => 'web', 'status' => 'pending', 'user_id' => null]);
    }

    public function test_dashboard_for_admin_and_staff(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['data' => ['orders_total', 'monthly_revenue', 'top_products']]);
        $this->actingAs($this->staff(), 'sanctum');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('data.top_products');
    }

    public function test_work_status_crud(): void
    {
        $this->actingAs($this->staff(), 'sanctum');
        $id = $this->postJson('/api/works', ['work_date' => '2026-03-01', 'items' => [['work_place' => 'Sivakasi', 'worker_name' => 'Mani']]])
            ->assertStatus(201)->json('data.id');
        $this->getJson("/api/works/$id")->assertOk();
        $this->putJson("/api/works/$id", ['items' => [['work_place' => 'Virudhunagar', 'worker_name' => 'Raja']]])->assertOk();
        $this->deleteJson("/api/works/$id")->assertOk();
    }

    public function test_change_password(): void
    {
        $this->actingAs($u = $this->staff(), 'sanctum');
        $this->putJson('/api/profile/password', ['current_password' => 'bad', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertStatus(422);
    }

    public function test_admin_cannot_disable_or_delete_self(): void
    {
        $this->actingAs($a = $this->admin(), 'sanctum');
        $this->putJson("/api/users/{$a->id}", ['name' => 'x', 'is_admin' => false])->assertStatus(422);
        $this->deleteJson("/api/users/{$a->id}")->assertStatus(422);
    }
}
