<?php

use App\Http\Controllers\Admin\AccountYearsController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\CompaniesController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\UsersController as AdminUsersController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OpeningStockController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\StockAdjustmentsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WebOrderController;
use App\Http\Controllers\WorkStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');            // company admin, manager, staff ... (not the product admin)
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');   // product admin + company admin only
Route::get('/public/account-years', [AuthController::class, 'years']);

// Customer facing order form (no login). Strictly rate limited.   /order-data?company=MK
Route::get('/public/products', [WebOrderController::class, 'products']);
Route::post('/public/orders', [WebOrderController::class, 'create'])->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| LOGGED IN (any active account)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword']);

    /*
    |----------------------------------------------------------------------
    | ADMIN CONSOLE - token from the /admin login only.
    | Product admin: everything.  Company admin: only his own companies / users / roles.
    |----------------------------------------------------------------------
    */
    Route::middleware(['abilities:admin', 'admin.portal'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        Route::get('/companies', [CompaniesController::class, 'index']);
        Route::put('/companies/{id}', [CompaniesController::class, 'update']);
        Route::get('/companies/{id}/users', [CompaniesController::class, 'users']);

        Route::get('/users', [AdminUsersController::class, 'index']);
        Route::post('/users', [AdminUsersController::class, 'store']);
        Route::put('/users/{id}', [AdminUsersController::class, 'update']);

        Route::get('/roles', [RolesController::class, 'index']);
        Route::post('/roles', [RolesController::class, 'store']);
        Route::put('/roles/{id}', [RolesController::class, 'update']);
        Route::delete('/roles/{id}', [RolesController::class, 'destroy']);

        Route::get('/account-years', [AccountYearsController::class, 'index']);
        Route::put('/account-years/{yearId}/companies/{companyId}', [AccountYearsController::class, 'lock']);

        // product admin only
        Route::middleware('admin')->group(function () {
            Route::post('/companies', [CompaniesController::class, 'store']);
            Route::delete('/companies/{id}', [CompaniesController::class, 'destroy']);
            Route::delete('/users/{id}', [AdminUsersController::class, 'destroy']);
            Route::post('/account-years', [AccountYearsController::class, 'store']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | COMPANY SCREENS - need X-Company-Id + X-Year-Id headers (checked on every request)
    | and the permission the admin gave to the user's role in that company
    |----------------------------------------------------------------------
    */
    Route::middleware(['abilities:app', 'tenant'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('can_do:dashboard.view');

        // masters are shared by every company; forms of other screens also need to list them
        Route::get('/products', [ProductsController::class, 'index'])
            ->middleware('can_do:products.view,orders.create,sales.create,purchases.create,stock_adjustments.create,opening_stock.view');
        Route::post('/products', [ProductsController::class, 'store'])->middleware('can_do:products.manage');
        Route::put('/products/{id}', [ProductsController::class, 'update'])->middleware('can_do:products.manage');
        Route::delete('/products/{id}', [ProductsController::class, 'destroy'])->middleware('can_do:products.manage');

        Route::get('/customers', [CustomersController::class, 'index'])->middleware('can_do:customers.view,orders.create,sales.create');
        Route::get('/customers/{id}', [CustomersController::class, 'show'])->middleware('can_do:customers.view');
        Route::post('/customers', [CustomersController::class, 'store'])->middleware('can_do:customers.manage');
        Route::put('/customers/{id}', [CustomersController::class, 'update'])->middleware('can_do:customers.manage');
        Route::delete('/customers/{id}', [CustomersController::class, 'destroy'])->middleware('can_do:customers.delete');

        // orders
        Route::get('/orders', [OrdersController::class, 'index'])->middleware('can_do:orders.view');
        Route::get('/orders/{id}', [OrdersController::class, 'show'])->middleware('can_do:orders.view');
        Route::get('/orders/{id}/print', [OrdersController::class, 'print'])->middleware('can_do:orders.view');
        Route::post('/orders', [OrdersController::class, 'store'])->middleware('can_do:orders.create');
        Route::put('/orders/{id}', [OrdersController::class, 'update'])->middleware('can_do:orders.update');
        Route::patch('/orders/{id}/status', [OrdersController::class, 'updateStatus'])->middleware('can_do:orders.update,orders.cancel');
        Route::delete('/orders/{id}', [OrdersController::class, 'destroy'])->middleware('can_do:orders.delete');

        // purchase
        Route::get('/purchases', [PurchasesController::class, 'index'])->middleware('can_do:purchases.view');
        Route::get('/purchases/{id}', [PurchasesController::class, 'show'])->middleware('can_do:purchases.view');
        Route::post('/purchases', [PurchasesController::class, 'store'])->middleware('can_do:purchases.create');
        Route::put('/purchases/{id}', [PurchasesController::class, 'update'])->middleware('can_do:purchases.create');
        Route::delete('/purchases/{id}', [PurchasesController::class, 'destroy'])->middleware('can_do:purchases.delete');

        // sales (direct, not through orders)
        Route::get('/sales', [SalesController::class, 'index'])->middleware('can_do:sales.view');
        Route::get('/sales/{id}', [SalesController::class, 'show'])->middleware('can_do:sales.view');
        Route::post('/sales', [SalesController::class, 'store'])->middleware('can_do:sales.create');
        Route::put('/sales/{id}', [SalesController::class, 'update'])->middleware('can_do:sales.create');
        Route::delete('/sales/{id}', [SalesController::class, 'destroy'])->middleware('can_do:sales.delete');

        // stock
        Route::get('/stock', [StockController::class, 'index'])->middleware('can_do:stock.view');
        Route::get('/opening-stock', [OpeningStockController::class, 'index'])->middleware('can_do:opening_stock.view');
        Route::post('/opening-stock/refresh', [OpeningStockController::class, 'refresh'])->middleware('can_do:opening_stock.manage');
        Route::put('/opening-stock/{productId}', [OpeningStockController::class, 'update'])->middleware('can_do:opening_stock.manage');
        Route::get('/stock-adjustments', [StockAdjustmentsController::class, 'index'])->middleware('can_do:stock_adjustments.view');
        Route::post('/stock-adjustments', [StockAdjustmentsController::class, 'store'])->middleware('can_do:stock_adjustments.create');
        Route::delete('/stock-adjustments/{id}', [StockAdjustmentsController::class, 'destroy'])->middleware('can_do:stock_adjustments.delete');

        // work status
        Route::get('/works', [WorkStatusController::class, 'index'])->middleware('can_do:work_status.view');
        Route::get('/works/{id}', [WorkStatusController::class, 'show'])->middleware('can_do:work_status.view');
        Route::post('/works', [WorkStatusController::class, 'store'])->middleware('can_do:work_status.manage');
        Route::put('/works/{id}', [WorkStatusController::class, 'update'])->middleware('can_do:work_status.manage');
        Route::delete('/works/{id}', [WorkStatusController::class, 'destroy'])->middleware('can_do:work_status.manage');
    });
});
