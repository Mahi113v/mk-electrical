<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One place that creates / updates / deletes orders so the admin screen and the
 * public web form behave exactly the same.
 *
 *  - totals are ALWAYS calculated on the server (client numbers are ignored)
 *  - stock is reduced / restored inside a DB transaction
 *  - the customer record is created or refreshed from the phone number
 */
class OrderService
{
    /**
     * @param  array $data validated order data (items: product_id, quantity, [rate])
     * @param  bool  $allowCustomRate staff may give a negotiated rate, the public form may not
     */
    public function save(?Order $order, array $data, ?int $userId, bool $allowCustomRate, string $source = 'admin'): Order
    {
        return DB::transaction(function () use ($order, $data, $userId, $allowCustomRate, $source) {
            if ($order) {
                $this->restoreStock($order);
                $order->items()->delete();
            }

            [$lines, $orderAmount] = $this->buildLines($data['items'], $allowCustomRate);

            $serviceCharge = (float) ($data['service_charge'] ?? 0);
            $customer = $this->syncCustomer($data, $userId);

            $payload = [
                'customer_id'     => $customer->id,
                'order_name'      => $data['order_name'],
                'order_date'      => $data['order_date'] ?? now()->toDateString(),
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
                'status'          => $data['status'] ?? 'pending',
            ];

            if ($order) {
                $order->update($payload);
            } else {
                $order = Order::create($payload + ['user_id' => $userId, 'source' => $source]);
            }

            $order->items()->createMany($lines);

            return $order->load('items.product', 'customer');
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $this->restoreStock($order);
            $order->delete();   // order_items are removed by FK cascade
        });
    }

    private function buildLines(array $items, bool $allowCustomRate): array
    {
        $lines = [];
        $orderAmount = 0.0;

        // merge duplicate product rows so stock is checked once per product
        $merged = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $merged[$id]['product_id'] = $id;
            $merged[$id]['quantity'] = ($merged[$id]['quantity'] ?? 0) + (float) $item['quantity'];
            $merged[$id]['rate'] = $item['rate'] ?? null;
        }

        foreach ($merged as $id => $item) {
            $product = Product::whereKey($id)->lockForUpdate()->first();

            if (!$product) {
                throw ValidationException::withMessages(['items' => ["Product #{$id} not found."]]);
            }

            if ($product->stock !== null) {
                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Not enough stock for {$product->product_name} (available: {$product->stock})."],
                    ]);
                }
                $product->decrement('stock', (int) ceil($item['quantity']));
            }

            $rate = ($allowCustomRate && $item['rate'] !== null) ? (float) $item['rate'] : (float) $product->rate;
            $amount = round($rate * $item['quantity'], 2);
            $orderAmount += $amount;

            $lines[] = [
                'product_id' => $id,
                'quantity'   => $item['quantity'],
                'rate'       => $rate,
                'amount'     => $amount,
                'total'      => $amount,
            ];
        }

        return [$lines, round($orderAmount, 2)];
    }

    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
            if ($product && $product->stock !== null) {
                $product->increment('stock', (int) ceil($item->quantity));
            }
        }
    }

    private function syncCustomer(array $data, ?int $userId): Customer
    {
        $customer = Customer::firstOrNew(['customer_phone' => $data['customer_phone']]);

        if (!$customer->exists) {
            $customer->user_id = $userId;
        }

        // only overwrite stored details with non-empty new values
        foreach (['customer_name', 'whatsapp', 'alternate_phone', 'area', 'city', 'address'] as $f) {
            if (!empty($data[$f])) {
                $customer->{$f} = $data[$f];
            }
        }
        $customer->save();

        return $customer;
    }
}
