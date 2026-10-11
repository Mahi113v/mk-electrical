<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        try {
            $customers = Customer::with('user:id,name')->withCount('orders')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(fn ($w) => $w->where('customer_name', 'LIKE', "%{$s}%")
                        ->orWhere('customer_phone', 'LIKE', "%{$s}%")
                        ->orWhere('area', 'LIKE', "%{$s}%")
                        ->orWhere('city', 'LIKE', "%{$s}%"));
                })
                ->orderByDesc('id')->get();

            return $this->ok($customers, 'Customer fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Customer fetch server error');
        }
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->ok($customer->load(['user:id,name', 'orders' => fn ($q) => $q->latest('id')->limit(20)]));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate($this->rules(), [
                'customer_phone.required' => 'Customer phone is required',
            ]);

            $customer = Customer::create($data + ['user_id' => $request->user()->id]);

            return $this->ok($customer, 'Customer created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating customer');
        }
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        try {
            $customer->update($request->validate($this->rules()));

            return $this->ok($customer->fresh(), 'Customer updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while updating customer');
        }
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->orders()->update(['customer_id' => null]);   // keep order history
        $customer->delete();

        return $this->ok(null, 'Customer deleted successfully');
    }
}
