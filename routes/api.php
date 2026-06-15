<?php

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Prestateur\AppointmentController;
use App\Http\Controllers\Api\V1\Prestateur\QuoteController;
use App\Http\Controllers\Api\V1\Prestateur\InvoiceController;
use App\Http\Controllers\Api\V1\Prestateur\ContractController;
use App\Http\Controllers\Api\V1\Prestateur\DocumentTemplateController;
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
use App\Http\Controllers\Api\V1\PrintController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Ambassador\AmbassadorController;
use App\Http\Controllers\Api\V1\Admin\AdminAmbassadorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Qiwam API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // ─── Contact public (portail landing page) ───────────────────────────
    Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

    // ─── Public auth routes ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:20,1');
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
            Route::patch('/profile', [AuthController::class, 'updateProfile']);
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
        Route::prefix('admin')->middleware(['role:super_admin|country_admin'])->group(function () {
            // Statistiques plateforme
            Route::get('/users/stats', [AdminUserController::class, 'stats']);

            // Monitoring des connexions
            Route::get('/logins',                  [\App\Http\Controllers\Api\V1\Admin\AdminMonitoringController::class, 'logins']);
            Route::get('/logins/frequency',        [\App\Http\Controllers\Api\V1\Admin\AdminMonitoringController::class, 'globalFrequency']);
            Route::get('/logins/{user}/frequency', [\App\Http\Controllers\Api\V1\Admin\AdminMonitoringController::class, 'loginFrequency']);

            // Gestion des utilisateurs
            Route::get('/users',                  [AdminUserController::class, 'index']);
            Route::get('/users/{user}',            [AdminUserController::class, 'show']);
            Route::patch('/users/{user}/block',   [AdminUserController::class, 'block']);
            Route::patch('/users/{user}/unblock', [AdminUserController::class, 'unblock']);

            // Gestion des espaces de travail (Tenants)
            Route::get('/tenants',                         [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'index']);
            Route::get('/tenants/{tenant}',                [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'show']);
            Route::patch('/tenants/{tenant}',              [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'updateTenant']);
            Route::patch('/tenants/{tenant}/modules',      [\App\Http\Controllers\Api\V1\Admin\AdminTenantController::class, 'updateModules']);

            // Gestion des Packs (lecture pour tous les admins, écriture super_admin)
            Route::get('/packs',        [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'index']);
            Route::get('/packs/{pack}', [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'show']);
            // Prix par pays — accessible aux admins pays (forcés sur leur pays) et au super admin
            Route::put('/packs/{pack}/country-price',                [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'setCountryPrice']);
            Route::delete('/packs/{pack}/country-price/{country}',   [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'removeCountryPrice']);
            Route::middleware(['role:super_admin'])->group(function () {
                Route::post('/packs',           [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'store']);
                Route::match(['PUT', 'PATCH'], '/packs/{pack}', [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'update']);
                Route::delete('/packs/{pack}',  [\App\Http\Controllers\Api\V1\Admin\PackController::class, 'destroy']);

                // Gestion des administrateurs plateforme (admins pays)
                Route::prefix('admins')->group(function () {
                    $a = \App\Http\Controllers\Api\V1\Admin\AdminManagementController::class;
                    Route::get('/',                [$a, 'index']);
                    Route::post('/',               [$a, 'store']);
                    Route::get('/{admin}',           [$a, 'show']);
                    Route::patch('/{admin}',         [$a, 'update']);
                    Route::patch('/{admin}/block',   [$a, 'block']);
                    Route::patch('/{admin}/unblock', [$a, 'unblock']);
                    Route::delete('/{admin}',        [$a, 'destroy']);
                });
            });

            // Catalogue de produits partagé (super admin + admins pays, scopé par pays)
            Route::prefix('catalog')->group(function () {
                $c = \App\Http\Controllers\Api\V1\Admin\CatalogProductController::class;
                Route::get('/',                   [$c, 'index']);
                Route::post('/',                  [$c, 'store']);
                Route::match(['PUT', 'PATCH'], '/{catalogProduct}', [$c, 'update']);
                Route::delete('/{catalogProduct}', [$c, 'destroy']);
            });

            // Gestion des Abonnements & Monitoring
            Route::prefix('subscriptions')->group(function () {
                $c = \App\Http\Controllers\Api\V1\Admin\AdminSubscriptionController::class;
                Route::get('/pending',         [$c, 'pendingApprovals']);
                Route::post('/{tenant}/approve', [$c, 'approve']);
                Route::get('/stats',           [$c, 'stats']);
                Route::get('/tracking',        [$c, 'tracking']);
                Route::get('/{tenant}/history', [$c, 'history']);
                Route::post('/{tenant}/payment', [$c, 'recordPayment']);
                Route::patch('/{tenant}/trial',   [$c, 'setTrial']);
                Route::patch('/{tenant}/pricing', [$c, 'setPricing']);
                Route::get('/plan-requests',              [$c, 'planRequests']);
                Route::patch('/plan-requests/{planRequest}', [$c, 'decidePlanRequest']);
            });

            // Ambassadeurs (super_admin uniquement)
            Route::prefix('ambassadors')->middleware(['role:super_admin'])->group(function () {
                Route::get('/',                         [AdminAmbassadorController::class, 'index']);
                Route::post('/',                        [AdminAmbassadorController::class, 'store']);
                Route::get('/{ambassador}',             [AdminAmbassadorController::class, 'show']);
                Route::put('/{ambassador}',             [AdminAmbassadorController::class, 'update']);
                Route::delete('/{ambassador}',          [AdminAmbassadorController::class, 'destroy']);
                Route::post('/{ambassador}/mark-paid',  [AdminAmbassadorController::class, 'markPaid']);
            });

            // Messages de contact (portail)
            Route::get('/contact-messages',              [\App\Http\Controllers\Api\V1\Admin\AdminContactController::class, 'index']);
            Route::post('/contact-messages/{contactMessage}/read', [\App\Http\Controllers\Api\V1\Admin\AdminContactController::class, 'markRead']);
            Route::delete('/contact-messages/{contactMessage}',    [\App\Http\Controllers\Api\V1\Admin\AdminContactController::class, 'destroy']);

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

        // ─── Ambassador routes ────────────────────────────────────────────
        Route::prefix('ambassador')->middleware(['role:ambassador'])->group(function () {
            Route::get('/dashboard',        [AmbassadorController::class, 'dashboard']);
            Route::post('/change-password', [AmbassadorController::class, 'changePassword']);
        });

        // ─── Tenant-scoped routes (require tenant + verified) ─────────────
        Route::middleware(['tenant', 'verified'])->group(function () {

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Abonnement (espace courant)
            Route::prefix('subscription')->group(function () {
                $c = \App\Http\Controllers\Api\V1\Tenant\SubscriptionController::class;
                Route::get('/',              [$c, 'show']);
                Route::get('/packs',         [$c, 'packs']);
                Route::post('/plan-request', [$c, 'requestPlanChange']);
            });

            // ─── AI Assistant (Qiwam assistant) ──────────────────────────
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
                Route::patch('/members/{user}/role',        [TeamController::class, 'updateRole']);
                Route::patch('/members/{user}/permissions', [TeamController::class, 'updatePermissions']);
                Route::delete('/members/{user}',      [TeamController::class, 'remove']);
                Route::get('/activity',               [TeamController::class, 'activity']);
            });

            // Rapports PDF (Exportation)
            Route::get('/reports/expenses/pdf',   [ReportController::class, 'exportExpensesPdf']);
            Route::get('/reports/production/pdf', [ReportController::class, 'exportProductionPdf']);

            // Produits & Services
            Route::prefix('products')->group(function () {
                Route::get('/meta',      [ProductController::class, 'meta']);
                Route::get('/lookup-barcode', [\App\Http\Controllers\Api\V1\Products\ProductLookupController::class, 'byBarcode']);
                Route::get('/trashed',   [ProductController::class, 'trashed']);
                Route::get('/',          [ProductController::class, 'index']);
                Route::post('/',         [ProductController::class, 'store']);
                Route::patch('/{id}/restore', [ProductController::class, 'restore']);
                Route::delete('/{id}/force',  [ProductController::class, 'forceDelete']);
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
                Route::post('/{order}/print',     [PrintController::class, 'receipt']);
            });

            // Impression thermique
            Route::prefix('print')->group(function () {
                Route::post('/test', [PrintController::class, 'test']);
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

            // ─── Module Prestateur ────────────────────────────────────────────
            Route::prefix('prestateur')->group(function () {

                // Rendez-vous
                Route::apiResource('appointments', AppointmentController::class);

                // Devis
                Route::apiResource('quotes', QuoteController::class);
                Route::post('quotes/{quote}/convert-to-invoice', [QuoteController::class, 'convertToInvoice']);
                Route::get('quotes/{quote}/pdf', [QuoteController::class, 'downloadPdf']);

                // Factures
                Route::apiResource('invoices', InvoiceController::class);
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf']);

                // Contrats
                Route::apiResource('contracts', ContractController::class);

                // Templates de documents
                Route::get('templates',                           [DocumentTemplateController::class, 'index']);
                Route::post('templates',                          [DocumentTemplateController::class, 'store']);
                Route::put('templates/{documentTemplate}',        [DocumentTemplateController::class, 'update']);
                Route::delete('templates/{documentTemplate}',     [DocumentTemplateController::class, 'destroy']);
                Route::post('templates/import-pdf',               [DocumentTemplateController::class, 'importPdf']);
            });

            // ─── Notifications ────────────────────────────────────────────────
            Route::prefix('notifications')->group(function () {
                Route::get('/',             [NotificationController::class, 'index']);
                Route::post('/read-all',    [NotificationController::class, 'markAllRead']);
                Route::post('/{id}/read',   [NotificationController::class, 'markRead']);
            });

            // Paramètres & Profil
            Route::prefix('settings')->group(function () {
                Route::put('/profile', [\App\Http\Controllers\Api\V1\Settings\SettingsController::class, 'updateProfile']);
                Route::post('/tenant', [\App\Http\Controllers\Api\V1\Settings\SettingsController::class, 'updateTenant']); // POST because of FormData file upload
            });

        });
    });
});
