<?php

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Expenses\ExpenseController;
use App\Http\Controllers\Api\V1\Products\ProductController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Suppliers\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Suppliers\SupplierController;
use App\Http\Controllers\Api\V1\Team\TeamController;
use App\Http\Controllers\Api\V1\Tenant\TenantController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NAFAA API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ─── Public auth routes ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/reset-password',  [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');
    });

    // ─── Utility routes ───────────────────────────────────────────────────
    Route::get('/tenants/industries', [TenantController::class, 'industries']);

    // ─── Authenticated routes ─────────────────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me',      [AuthController::class, 'me']);
            Route::post('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->middleware('signed')
                ->name('verification.verify');
            Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1');
        });

        // Tenant onboarding
        Route::prefix('tenants')->group(function () {
            Route::post('/',       [TenantController::class, 'store']);
            Route::get('/current', [TenantController::class, 'current']);
        });

        // ─── Super Admin routes ───────────────────────────────────────────
        Route::prefix('admin')->middleware(['role:super_admin'])->group(function () {
            // Statistiques plateforme
            Route::get('/users/stats', [AdminUserController::class, 'stats']);

            // Gestion des utilisateurs
            Route::get('/users',                  [AdminUserController::class, 'index']);
            Route::get('/users/{user}',            [AdminUserController::class, 'show']);
            Route::patch('/users/{user}/block',   [AdminUserController::class, 'block']);
            Route::patch('/users/{user}/unblock', [AdminUserController::class, 'unblock']);
        });

        // ─── Tenant-scoped routes (require tenant + verified) ─────────────
        Route::middleware(['tenant', 'verified'])->group(function () {

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Équipe
            Route::prefix('team')->group(function () {
                Route::get('/',                       [TeamController::class, 'index']);
                Route::post('/invite',                [TeamController::class, 'invite']);
                Route::get('/members/{user}',         [TeamController::class, 'show']);
                Route::patch('/members/{user}/role',  [TeamController::class, 'updateRole']);
                Route::delete('/members/{user}',      [TeamController::class, 'remove']);
                Route::get('/activity',               [TeamController::class, 'activity']);
            });

            // Produits & Services
            Route::prefix('products')->group(function () {
                Route::get('/meta',      [ProductController::class, 'meta']);
                Route::get('/',          [ProductController::class, 'index']);
                Route::post('/',         [ProductController::class, 'store']);
                Route::get('/{product}', [ProductController::class, 'show']);
                Route::match(['PUT', 'PATCH'], '/{product}', [ProductController::class, 'update']);
                Route::delete('/{product}', [ProductController::class, 'destroy']);
            });

            // Clients (CRM)
            Route::prefix('customers')->group(function () {
                Route::get('/meta',        [CustomerController::class, 'meta']);
                Route::get('/',            [CustomerController::class, 'index']);
                Route::post('/',           [CustomerController::class, 'store']);
                Route::get('/{customer}',  [CustomerController::class, 'show']);
                Route::match(['PUT', 'PATCH'], '/{customer}', [CustomerController::class, 'update']);
                Route::delete('/{customer}', [CustomerController::class, 'destroy']);
            });

            // Fournisseurs
            Route::prefix('suppliers')->group(function () {
                Route::get('/meta',          [SupplierController::class, 'meta']);
                Route::get('/',              [SupplierController::class, 'index']);
                Route::post('/',             [SupplierController::class, 'store']);
                Route::get('/{supplier}',    [SupplierController::class, 'show']);
                Route::match(['PUT', 'PATCH'], '/{supplier}', [SupplierController::class, 'update']);
                Route::delete('/{supplier}', [SupplierController::class, 'destroy']);
            });

            // Bons de commande fournisseurs
            Route::prefix('purchase-orders')->group(function () {
                Route::get('/meta',                         [PurchaseOrderController::class, 'meta']);
                Route::get('/',                             [PurchaseOrderController::class, 'index']);
                Route::post('/',                            [PurchaseOrderController::class, 'store']);
                Route::get('/{purchaseOrder}',              [PurchaseOrderController::class, 'show']);
                Route::patch('/{purchaseOrder}/status',     [PurchaseOrderController::class, 'updateStatus']);
                Route::delete('/{purchaseOrder}',           [PurchaseOrderController::class, 'destroy']);
            });

            // Dépenses opérationnelles
            Route::prefix('expenses')->group(function () {
                Route::get('/meta',        [ExpenseController::class, 'meta']);
                Route::get('/',            [ExpenseController::class, 'index']);
                Route::post('/',           [ExpenseController::class, 'store']);
                Route::get('/{expense}',   [ExpenseController::class, 'show']);
                Route::match(['PUT', 'PATCH'], '/{expense}', [ExpenseController::class, 'update']);
                Route::delete('/{expense}',[ExpenseController::class, 'destroy']);
            });

            // Commandes Clients (POS & Standard)
            Route::prefix('orders')->group(function () {
                Route::get('/meta',               [OrderController::class, 'meta']);
                Route::get('/',                   [OrderController::class, 'index']);
                Route::post('/',                  [OrderController::class, 'store']);
                Route::get('/{order}',            [OrderController::class, 'show']);
                Route::delete('/{order}',         [OrderController::class, 'destroy']);
                Route::get('/{order}/invoice',    [OrderController::class, 'downloadInvoice']);
            });

            // Rapports
            Route::prefix('reports')->group(function () {
                Route::get('/sales',      [ReportController::class, 'dailySummary']);
                Route::get('/finance',    [ReportController::class, 'financialSummary']);
                Route::get('/inventory',  [ReportController::class, 'inventoryValuation']);
            });

        });
    });
});
