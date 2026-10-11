<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebOrderController;
use App\Http\Controllers\WorkStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1');

// Customer facing order form (no login). Strictly rate limited.
Route::get('/public/products', [WebOrderController::class, 'products']);
Route::post('/public/orders', [WebOrderController::class, 'create'])->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| ADMIN + USER (any active, logged-in account)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Products: everyone can read, only admin can change (see below)
    Route::get('/products', [ProductsController::class, 'index']);
    Route::get('/products/{product}', [ProductsController::class, 'show']);

    // Customers
    Route::get('/customers', [CustomersController::class, 'index']);
    Route::get('/customers/{customer}', [CustomersController::class, 'show']);
    Route::post('/customers', [CustomersController::class, 'store']);
    Route::put('/customers/{customer}', [CustomersController::class, 'update']);

    // Orders (update/delete: admin or the user who created the order)
    Route::get('/orders', [OrdersController::class, 'index']);
    Route::get('/orders/{order}', [OrdersController::class, 'show']);
    Route::get('/orders/{order}/print', [OrdersController::class, 'print']);
    Route::post('/orders', [OrdersController::class, 'store']);
    Route::put('/orders/{order}', [OrdersController::class, 'update']);
    Route::patch('/orders/{order}/status', [OrdersController::class, 'updateStatus']);
    Route::delete('/orders/{order}', [OrdersController::class, 'destroy']);

    // Work status
    Route::get('/works', [WorkStatusController::class, 'index']);
    Route::get('/works/{work}', [WorkStatusController::class, 'show']);
    Route::post('/works', [WorkStatusController::class, 'store']);
    Route::put('/works/{work}', [WorkStatusController::class, 'update']);
    Route::delete('/works/{work}', [WorkStatusController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | ADMIN ONLY
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')->group(function () {
        Route::post('/products', [ProductsController::class, 'store']);
        Route::put('/products/{product}', [ProductsController::class, 'update']);
        Route::delete('/products/{product}', [ProductsController::class, 'destroy']);

        Route::delete('/customers/{customer}', [CustomersController::class, 'destroy']);

        Route::get('/users', [UsersController::class, 'index']);
        Route::post('/users', [UsersController::class, 'store']);
        Route::put('/users/{user}', [UsersController::class, 'update']);
        Route::delete('/users/{user}', [UsersController::class, 'destroy']);
    });
});
