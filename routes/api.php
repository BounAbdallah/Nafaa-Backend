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
use App\Http\Controllers\Api\V1\Production\BomController;
use App\Http\Controllers\Api\V1\Production\ProductionController;
use App\Http\Controllers\Api\V1\Ai\AiController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Qiwam API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // ─── EMERGENCY UTILS (Temporary) ──────────────────────────────────────
    Route::get('/debug/migrate', function() {
        try {
            Artisan::call('migrate', ['--force' => true]);
            return response()->json(['success' => true, 'output' => Artisan::output()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    });

    Route::get('/debug/make-me-super-admin', function(\Illuminate\Http\Request $request) {
        $user = $request->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Non connecté.'], 401);
        
        $user->assignRole('super_admin');
        return response()->json(['success' => true, 'message' => "Vous êtes maintenant Super Admin."]);
    })->middleware('auth:sanctum');

    // ─── Public auth routes ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/reset-password',  [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');
    });

    // ─── Utility routes ───────────────────────────────────────────────────
    Route::get('/tenants/industries', [TenantController::class, 'industries']);
    Route::get('/packs', [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'index']);

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

            // Notifications (Shared)
            Route::get('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'index']);
            Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'markAllRead']);
            Route::patch('/notifications/{id}/read', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'markAsRead']);
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

            // Gestion des espaces de travail (Tenants)
            Route::get('/tenants',                  [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'index']);
            Route::patch('/tenants/{tenant}',       [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'updateTenant']);

            // Gestion des Packs
            Route::apiResource('/packs', \App\Http\Controllers\Api\V1\Admin\PackController::class);

            // Gestion des Abonnements & Monitoring
            Route::prefix('subscriptions')->group(function () {
                $c = \App\Http\Controllers\Api\V1\Admin\AdminSubscriptionController::class;
                Route::get('/pending',         [$c, 'pendingApprovals']);
                Route::post('/{tenant}/approve', [$c, 'approve']);
                Route::get('/stats',           [$c, 'stats']);
                Route::get('/tracking',        [$c, 'tracking']);
                Route::get('/{tenant}/history', [$c, 'history']);
                Route::post('/{tenant}/payment', [$c, 'recordPayment']);
            });

            // Journal d'activité plateforme
            Route::get('/activity-logs', function() {
                return response()->json([
                    'success' => true,
                    'logs' => \App\Models\ActivityLog::with(['user', 'tenant'])
                        ->orderBy('created_at', 'desc')
                        ->paginate(50)
                ]);
            });
        });

        // ─── Tenant-scoped routes (require tenant + verified) ─────────────
        Route::middleware(['tenant', 'verified'])->group(function () {

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // ─── AI Assistant (Qiwam Intelligent) ──────────────────────────
            Route::prefix('ai')->group(function () {
                Route::get( '/tools',      [AiController::class, 'tools']);
                Route::post('/text',       [AiController::class, 'text']);
                Route::post('/voice',      [AiController::class, 'voice']);
                Route::post('/import-csv', [AiController::class, 'importCsv']);
            });

            // Équipe
            Route::prefix('team')->group(function () {
                Route::get('/',                       [TeamController::class, 'index']);
                Route::post('/invite',                [TeamController::class, 'invite']);
                Route::get('/members/{user}',         [TeamController::class, 'show']);
                Route::patch('/members/{user}/role',  [TeamController::class, 'updateRole']);
                Route::delete('/members/{user}',      [TeamController::class, 'remove']);
                Route::get('/activity',               [TeamController::class, 'activity']);
            });

            // Rapports PDF (Exportation)
            Route::get('/reports/expenses/pdf',   [ReportController::class, 'exportExpensesPdf']);
            Route::get('/reports/production/pdf', [ReportController::class, 'exportProductionPdf']);

            // Produits & Services
            Route::prefix('products')->group(function () {
                Route::get('/meta',      [ProductController::class, 'meta']);
                Route::get('/',          [ProductController::class, 'index']);
                Route::post('/',         [ProductController::class, 'store']);
                Route::get('/{product}', [ProductController::class, 'show']);
                Route::get('/{product}/stats', [ProductController::class, 'stats']);
                Route::match(['PUT', 'PATCH'], '/{product}', [ProductController::class, 'update']);
                Route::delete('/{product}', [ProductController::class, 'destroy']);
            });

            // Catégories
            Route::apiResource('/categories', \App\Http\Controllers\Api\V1\Products\CategoryController::class);

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
                Route::get('/team',       [ReportController::class, 'teamPerformance']);
                Route::get('/customers',  [ReportController::class, 'customerAnalytics']);
            });

            // ─── Production & BOM ─────────────────────────────────────────────
            Route::prefix('production')->group(function () {
                // Recettes (BOM)
                Route::get('/boms/meta',        [BomController::class, 'getMeta']);
                Route::apiResource('/boms',      BomController::class);

                // Fabrications (Productions)
                Route::get('/',                 [ProductionController::class, 'index']);
                Route::post('/',                [ProductionController::class, 'store']);
                Route::get('/{production}',      [ProductionController::class, 'show']);
                Route::post('/check-availability', [ProductionController::class, 'checkAvailability']);
                Route::post('/{production}/start', [ProductionController::class, 'start']);
                Route::post('/{production}/complete', [ProductionController::class, 'complete']);
                Route::post('/{production}/cancel', [ProductionController::class, 'cancel']);
                Route::get('/{production}/report', [ProductionController::class, 'downloadReport']);
            });

            // Paramètres & Profil
            Route::prefix('settings')->group(function () {
                Route::put('/profile', [\App\Http\Controllers\Api\V1\Settings\SettingsController::class, 'updateProfile']);
                Route::post('/tenant', [\App\Http\Controllers\Api\V1\Settings\SettingsController::class, 'updateTenant']); // POST because of FormData file upload
            });

        });
    });
});
