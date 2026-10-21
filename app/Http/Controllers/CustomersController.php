<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customers are a shared master (all companies). */
class CustomersController extends Controller
{
    private function rules(): array
    {
        return [
            'customer_name'   => ['required', 'string', 'max:255'],
            'customer_phone'  => ['required', 'string', 'max:15'],
            'whatsapp'        => ['nullable', 'string', 'max:15'],
            'alternate_phone' => ['nullable', 'string', 'max:15'],
            'area'            => ['nullable', 'string', 'max:255'],
            'city'            => ['nullable', 'string', 'max:255'],
            'address'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $customers = Customer::with('user:id,name')->withCount('orders')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('customer_name', 'LIKE', "%{$s}%")
                    ->orWhere('customer_phone', 'LIKE', "%{$s}%")
                    ->orWhere('area', 'LIKE', "%{$s}%")->orWhere('city', 'LIKE', "%{$s}%"));
            })
            ->orderByDesc('id')->get();

        return $this->ok($customers, 'Customer fetched successfully');
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(Customer::with(['user:id,name', 'orders' => fn ($q) => $q->latest('id')->limit(20)])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return $this->ok(Customer::create($data + ['user_id' => $request->user()->id]), 'Customer created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->validate($this->rules()));

        return $this->ok($customer->fresh(), 'Customer updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->orders()->update(['customer_id' => null]);   // keep order history
        $customer->delete();

        return $this->ok(null, 'Customer deleted successfully');
    }
}
