<?php

namespace App\Services;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates / changes / deletes orders for ONE company + year.
 *  - totals are always calculated on the server
 *  - stock is checked (company setting "allow negative stock" can switch the check off)
 *  - cancelling an order puts the stock back through an automatic Stock Adjustment
 *  - the customer record is created or refreshed from the phone number
 */
class OrderService
{
    public function __construct(private StockService $stock)
    {
    }

    public function save(?Order $order, array $data, Company $company, AccountYear $year, ?int $userId, bool $allowCustomRate, string $source = 'admin'): Order
    {
        return DB::transaction(function () use ($order, $data, $company, $year, $userId, $allowCustomRate, $source) {
            $newStatus = $data['status'] ?? ($order?->status ?? 'pending');
            $oldStatus = $order?->status;

            if ($order) {
                if ($oldStatus === 'cancelled' && $newStatus === 'cancelled') {
                    throw ValidationException::withMessages(['status' => ['This order is cancelled. Change its status first to edit it.']]);
                }
                if ($oldStatus === 'cancelled') {
                    $this->removeCancelAdjustments($order);   // reopening: the stock goes out again below
                }
                $order->items()->delete();
            }

            [$lines, $orderAmount] = $this->buildLines($data['items'], $allowCustomRate);

            $serviceCharge = (float) ($data['service_charge'] ?? 0);
            $customer = $this->syncCustomer($data, $userId);

            $payload = [
                'customer_id'     => $customer->id,
                'order_name'      => $data['order_name'],
                'order_date'      => $data['order_date'],
                'customer_name'   => $data['customer_name'],
                'customer_phone'  => $data['customer_phone'],
                'whatsapp'        => $data['whatsapp'] ?? '',
                'alternate_phone' => $data['alternate_phone'] ?? '',
                'area'            => $data['area'] ?? '',
                'city'            => $data['city'] ?? '',
                'address'         => $data['address'] ?? '',
                'service_charge'  => $serviceCharge,
                'order_amount'    => $orderAmount,
                'total'           => round($orderAmount + $serviceCharge, 2),
                'referral'        => $data['referral'] ?? 'Mathan',
                'notes'           => $data['notes'] ?? '',
                'status'          => $newStatus,
            ];

            if ($order) {
                $order->update($payload);
            } else {
                $order = Order::create($payload + [
                    'company_id' => $company->id, 'account_year_id' => $year->id, 'user_id' => $userId, 'source' => $source,
                ]);
            }

            $order->items()->createMany($lines);

            if ($newStatus === 'cancelled') {
                $this->createCancelAdjustment($order, $year, $userId);
            }

            $this->stock->assertNotNegative($company, $year->id, collect($lines)->pluck('product_id')->all());

            return $order->load('items.product', 'customer');
        });
    }

    /** Status only (no item changes) */
    public function changeStatus(Order $order, string $status, Company $company, AccountYear $year, ?int $userId): Order
    {
        return DB::transaction(function () use ($order, $status, $company, $year, $userId) {
            $old = $order->status;
            if ($old === $status) {
                return $order;
            }

            if ($status === 'cancelled') {
                $this->createCancelAdjustment($order, $year, $userId);
            } elseif ($old === 'cancelled') {
                $this->removeCancelAdjustments($order);
                $this->stock->assertNotNegative($company, $year->id, $order->items()->pluck('product_id')->all());
            }

            $order->update(['status' => $status]);

            return $order;
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->adjustments()->delete();   // automatic cancel adjustments
            $order->delete();                  // items cascade
        });
    }

    private function createCancelAdjustment(Order $order, AccountYear $year, ?int $userId): void
    {
        $order->loadMissing('items');
        $today = now()->toDateString();

        $adjustment = StockAdjustment::create([
            'company_id'      => $order->company_id,
            'account_year_id' => $order->account_year_id,
            'adjustment_no'   => DocNumber::next(StockAdjustment::class, 'adjustment_no', 'ADJ', $order->company_id, $order->account_year_id, $year->code),
            'adjustment_date' => $year->contains($today) ? $today : $year->end_date->toDateString(),
            'reason'          => 'order_cancel',
            'order_id'        => $order->id,
            'is_auto'         => true,
            'notes'           => "Order #{$order->id} ({$order->order_name}) cancelled - stock returned",
            'created_by'      => $userId,
        ]);

        $adjustment->items()->createMany(
            $order->items->groupBy('product_id')->map(fn ($rows, $pid) => [
                'product_id' => $pid, 'quantity_change' => $rows->sum('quantity'),
            ])->values()->all()
        );
    }

    private function removeCancelAdjustments(Order $order): void
    {
        $order->adjustments()->where('is_auto', true)->get()->each->delete();
    }

    private function buildLines(array $items, bool $allowCustomRate): array
    {
        $lines = [];
        $orderAmount = 0.0;

        // same product on two rows -> one row
        $merged = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $merged[$id]['quantity'] = ($merged[$id]['quantity'] ?? 0) + (float) $item['quantity'];
            $merged[$id]['rate'] = $item['rate'] ?? null;
        }

        $products = Product::whereIn('id', array_keys($merged))->get()->keyBy('id');

        foreach ($merged as $id => $item) {
            $product = $products[$id] ?? null;
            if (!$product) {
                throw ValidationException::withMessages(['items' => ["Product #{$id} not found."]]);
            }

            $rate = ($allowCustomRate && $item['rate'] !== null) ? (float) $item['rate'] : (float) $product->rate;
            $amount = round($rate * $item['quantity'], 2);
            $orderAmount += $amount;

            $lines[] = ['product_id' => $id, 'quantity' => $item['quantity'], 'rate' => $rate, 'amount' => $amount, 'total' => $amount];
        }

        return [$lines, round($orderAmount, 2)];
    }

    private function syncCustomer(array $data, ?int $userId): Customer
    {
        $customer = Customer::firstOrNew(['customer_phone' => $data['customer_phone']]);

        if (!$customer->exists) {
            $customer->user_id = $userId;
        }

        foreach (['customer_name', 'whatsapp', 'alternate_phone', 'area', 'city', 'address'] as $f) {
            if (!empty($data[$f])) {
                $customer->{$f} = $data[$f];
            }
        }
        $customer->save();

        return $customer;
    }
}
