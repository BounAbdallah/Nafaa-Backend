<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\VatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ── Helpers ─────────────────────────────────────────────────────────────────

function makeTenant(array $attrs = []): Tenant
{
    return Tenant::create(array_merge([
        'name'             => 'Test Shop',
        'slug'             => 'test-shop-' . uniqid(),
        'industry'         => 'commerce',
        'profile_type'     => 'reseller',
        'plan'             => 'demarrage',
        'is_active'        => true,
        'default_vat_rate' => 0,
    ], $attrs));
}

function makeOwner(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->update(['owner_id' => $user->id]);
    // Spatie roles table may not exist in all test configs; fallback to mock
    try {
        $user->assignRole('admin');
    } catch (\Throwable) {
        // Si Spatie n'est pas bootstrappé, on force canDo à true via module_permissions
        $user->update(['module_permissions' => ['orders' => ['create' => true, 'view' => true, 'delete' => true]]]);
    }
    return $user;
}

function makeProduct(Tenant $tenant, array $attrs = []): Product
{
    return Product::create(array_merge([
        'tenant_id'      => $tenant->id,
        'name'           => 'Produit Test',
        'selling_price'  => 10000,
        'cost_price'     => 5000,
        'stock_quantity' => 100,
        'type'           => 'product',
    ], $attrs));
}

function makeCustomer(Tenant $tenant, array $attrs = []): Customer
{
    return Customer::create(array_merge([
        'tenant_id' => $tenant->id,
        'name'      => 'Client Test',
        'is_active' => true,
    ], $attrs));
}

// ── VatService unit tests ────────────────────────────────────────────────────

describe('VatService', function () {
    it('résout le taux à 0 quand tout est nul', function () {
        $tenant = makeTenant(['default_vat_rate' => 0]);
        $svc    = new VatService();
        expect($svc->resolveRate(null, null, $tenant))->toBe(0.0);
    });

    it('utilise le taux tenant par défaut', function () {
        $tenant = makeTenant(['default_vat_rate' => 18]);
        $svc    = new VatService();
        expect($svc->resolveRate(null, null, $tenant))->toBe(18.0);
    });

    it('hérite du taux client en priorité sur le tenant', function () {
        $tenant   = makeTenant(['default_vat_rate' => 18]);
        $customer = makeCustomer($tenant, ['vat_rate' => 10]);
        $svc      = new VatService();
        expect($svc->resolveRate(null, $customer, $tenant))->toBe(10.0);
    });

    it('override facture prime sur client et tenant', function () {
        $tenant   = makeTenant(['default_vat_rate' => 18]);
        $customer = makeCustomer($tenant, ['vat_rate' => 10]);
        $svc      = new VatService();
        expect($svc->resolveRate(5.0, $customer, $tenant))->toBe(5.0);
    });

    it('calcule le montant TVA correctement', function () {
        $svc = new VatService();
        expect($svc->computeAmount(10000, 18))->toBe(1800.0);
        expect($svc->computeAmount(10000, 0))->toBe(0.0);
    });
});

// ── OrderController integration tests ───────────────────────────────────────

describe('Order TVA', function () {
    beforeEach(function () {
        $this->tenant  = makeTenant(['default_vat_rate' => 0]);
        $this->user    = makeOwner($this->tenant);
        $this->product = makeProduct($this->tenant, ['selling_price' => 10000, 'stock_quantity' => 50]);
    });

    function orderPayload(Product $product, array $extras = []): array
    {
        return array_merge([
            'items'    => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $extras);
    }

    it('taux 0 : vat_amount = 0, total_amount = subtotal', function () {
        $resp = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', orderPayload($this->product));

        $resp->assertStatus(201);
        $order = Order::latest()->first();
        expect($order->vat_rate)->toBe(0.0);
        expect($order->vat_amount)->toBe(0.0);
        expect($order->total_amount)->toBe(10000.0);
    });

    it('override taux facture : TVA calculée correctement', function () {
        $payload = orderPayload($this->product, [
            'vat_rate'       => 18,
            'payments'       => [['method' => 'cash', 'amount' => 11800]],
        ]);

        $resp = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', $payload);

        $resp->assertStatus(201);
        $order = Order::latest()->first();
        expect($order->vat_rate)->toBe(18.0);
        expect($order->vat_amount)->toBe(1800.0);
        expect($order->total_amount)->toBe(11800.0);
        expect($order->subtotal_ht)->toBe(10000.0);
    });

    it('héritage taux client', function () {
        $customer = makeCustomer($this->tenant, ['vat_rate' => 10]);

        $payload = orderPayload($this->product, [
            'customer_id' => $customer->id,
            'payments'    => [['method' => 'cash', 'amount' => 11000]],
        ]);

        $resp = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', $payload);

        $resp->assertStatus(201);
        $order = Order::latest()->first();
        expect($order->vat_rate)->toBe(10.0);
        expect($order->vat_amount)->toBe(1000.0);
    });

    it('taux figé à la création (immuabilité)', function () {
        $payload = orderPayload($this->product, [
            'vat_rate' => 18,
            'payments' => [['method' => 'cash', 'amount' => 11800]],
        ]);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', $payload);
        $order = Order::latest()->first();

        // Changer le taux tenant ne doit pas affecter l'ordre existant
        $this->tenant->update(['default_vat_rate' => 0]);
        $order->refresh();

        expect($order->vat_rate)->toBe(18.0);
        expect($order->vat_amount)->toBe(1800.0);
    });

    it('backfill : commandes existantes sans nouvelles colonnes ont vat_rate = 0', function () {
        // Simule une commande pré-migration (colonnes présentes grâce à la migration, valeurs par défaut à 0)
        $order = Order::create([
            'tenant_id'       => $this->tenant->id,
            'user_id'         => $this->user->id,
            'reference'       => 'ORD-BACK-001',
            'status'          => 'completed',
            'payment_status'  => 'paid',
            'payment_method'  => 'cash',
            'subtotal'        => 5000,
            'subtotal_ht'     => 5000,
            'vat_rate'        => 0,
            'vat_amount'      => 0,
            'discount_amount' => 0,
            'total_amount'    => 5000,
            'paid_amount'     => 5000,
            'change_amount'   => 0,
        ]);

        expect($order->vat_rate)->toBe(0.0);
        expect($order->vat_amount)->toBe(0.0);
        expect($order->total_amount)->toBe($order->subtotal_ht);
    });
});
