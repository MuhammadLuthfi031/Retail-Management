<?php

namespace tests\Feature\Access;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Dua fungsi sekaligus:
 *
 * 1) MATRIKS HAK AKSES — siapa boleh/tidak membuka tiap halaman & memanggil
 *    tiap aksi tulis. Kalau nanti ada route baru yang lupa dipasangi
 *    middleware role, atau role dilonggarkan tidak sengaja, test ini merah.
 *
 * 2) SMOKE TEST RENDER — setiap halaman dirender dengan data nyata, dengan
 *    Model::preventLazyLoading() aktif (environment testing = non-production).
 *    Ini jaring pengaman untuk kelas bug yang sudah 3x lolos ke production
 *    (LazyLoadingViolationException karena lupa eager-load): halaman yang
 *    kena akan jadi 500 di sini, bukan di layar user.
 *
 * Data seed sengaja berisi >1 baris di tiap daftar, karena Laravel hanya
 * memicu lazy-loading violation pada hasil query berisi lebih dari 1 model.
 */
class RoleAccessTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private Product $product;

    private PurchaseOrder $purchaseOrder;

    private Transaction $transaction;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->otherUser = User::factory()->kasir()->create();

        // Produk stok menipis (<= min_stock) + 2 produk lain, semua multi-satuan.
        $this->product = $this->makeProduct(['stock' => 2, 'min_stock' => 5]);
        $second = $this->makeProduct(['stock' => 3, 'min_stock' => 5]);
        $this->makeProduct(['stock' => 500, 'min_stock' => 5]);

        StockMovement::record($this->product, 'in', 2, $admin->id, 'SEED');
        StockMovement::record($this->product, 'sale', 1, $admin->id, 'SEED');

        // 2 transaksi, masing-masing 2 baris detail.
        foreach ([1, 2] as $n) {
            $trx = Transaction::forceCreate([
                'invoice_number' => sprintf('INV-20260924-%04d', $n),
                'user_id' => $this->otherUser->id,
                'total_amount' => 12000,
                'discount_amount' => 0,
                'grand_total' => 12000,
                'paid_amount' => 15000,
                'change_amount' => 3000,
                'payment_method' => 'cash',
                'status' => 'completed',
            ]);

            foreach ([$this->product, $second] as $p) {
                TransactionDetail::forceCreate([
                    'transaction_id' => $trx->id,
                    'product_id' => $p->id,
                    'product_name' => $p->name,
                    'unit_name' => 'sachet',
                    'unit_conversion' => 1,
                    'price' => 1000,
                    'unit_cost' => 700,
                    'discount_amount' => 0,
                    'quantity' => 6,
                    'subtotal' => 6000,
                ]);
            }

            $this->transaction ??= $trx;
        }

        // 1 PO berstatus "ordered" dengan 2 item (supaya muncul di daftar Gudang & Admin).
        $supplier = Supplier::forceCreate(['name' => 'Supplier Uji']);
        $this->purchaseOrder = PurchaseOrder::forceCreate([
            'po_number' => 'PO-UJI-0001',
            'supplier_id' => $supplier->id,
            'created_by' => $admin->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(3)->toDateString(),
            'status' => 'ordered',
            'payment_status' => 'unpaid',
            'total_amount' => 200000,
        ]);

        foreach ([$this->product, $second] as $p) {
            PurchaseOrderItem::forceCreate([
                'purchase_order_id' => $this->purchaseOrder->id,
                'product_id' => $p->id,
                'product_unit_id' => $p->units->firstWhere('unit_name', 'dus')->id,
                'quantity_ordered' => 1,
                'quantity_received' => 0,
                'unit_price' => 100000,
                'subtotal' => 100000,
            ]);
        }
    }

    private function makeTransaction(User $kasir, string $invoice): Transaction
    {
        return Transaction::forceCreate([
            'invoice_number' => $invoice,
            'user_id' => $kasir->id,
            'total_amount' => 5000,
            'discount_amount' => 0,
            'grand_total' => 5000,
            'paid_amount' => 5000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
    }

    private function routeParameter(?string $key): array
    {
        return match ($key) {
            'product' => [$this->product],
            'po' => [$this->purchaseOrder],
            'transaction' => [$this->transaction],
            'user' => [$this->otherUser],
            default => [],
        };
    }

    // === 1a. Halaman (GET): siapa boleh membuka ===

    public static function pages(): array
    {
        // route => [parameter, role yang BOLEH]
        $matrix = [
            'kasir.pos' => [null, ['admin', 'kasir']],
            'kasir.produk' => [null, ['admin', 'kasir']],
            'kasir.riwayat' => [null, ['admin', 'kasir']],

            'gudang.kategori.index' => [null, ['admin', 'gudang']],
            'gudang.produk.index' => [null, ['admin', 'gudang']],
            'gudang.produk.show' => ['product', ['admin', 'gudang']],
            'gudang.stok.index' => [null, ['admin', 'gudang']],
            'gudang.stok.show' => ['product', ['admin', 'gudang']],
            'gudang.pembelian.index' => [null, ['admin', 'gudang']],
            'gudang.pembelian.show' => ['po', ['admin', 'gudang']],

            'admin.dashboard' => [null, ['admin']],
            'admin.laporan.penjualan' => [null, ['admin']],
            'admin.laporan.laba-rugi' => [null, ['admin']],
            'admin.laporan.stok' => [null, ['admin']],
            'admin.users.index' => [null, ['admin']],
            'admin.supplier.index' => [null, ['admin']],
            'admin.pembelian.index' => [null, ['admin']],
            'admin.pembelian.show' => ['po', ['admin']],
        ];

        $cases = [];
        foreach ($matrix as $route => [$param, $allowed]) {
            foreach (['admin', 'kasir', 'gudang'] as $role) {
                $cases["{$role} -> {$route}"] = [$route, $param, $role, in_array($role, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('pages')]
    public function test_page_access_and_rendering_per_role(string $route, ?string $param, string $role, bool $allowed): void
    {
        $this->signInAs($role);

        $response = $this->get(route($route, $this->routeParameter($param)));

        // Diizinkan -> halaman HARUS render sukses (200). 500 di sini biasanya
        // LazyLoadingViolationException: cek stack trace, tambahkan with(...).
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    // === 1b. Aksi tulis: role yang TIDAK boleh harus ditolak ===

    public static function forbiddenWrites(): array
    {
        // [method, route, parameter, role yang HARUS ditolak]
        $writes = [
            ['POST', 'gudang.produk.store', null, ['kasir']],
            ['DELETE', 'gudang.produk.destroy', 'product', ['kasir']],
            ['POST', 'gudang.stok.keluar', 'product', ['kasir']],
            ['POST', 'gudang.stok.opname', 'product', ['kasir']],
            ['POST', 'gudang.pembelian.store', 'po', ['kasir']],
            ['POST', 'kasir.pos.checkout', null, ['gudang']],

            ['POST', 'admin.users.store', null, ['kasir', 'gudang']],
            ['PUT', 'admin.users.toggle-status', 'user', ['kasir', 'gudang']],
            ['PUT', 'admin.users.reset-password', 'user', ['kasir', 'gudang']],
            ['POST', 'admin.supplier.store', null, ['kasir', 'gudang']],
            ['POST', 'admin.pembelian.store', null, ['kasir', 'gudang']],
            ['DELETE', 'admin.pembelian.destroy', 'po', ['kasir', 'gudang']],
            ['PUT', 'admin.pembelian.mark-ordered', 'po', ['kasir', 'gudang']],
            ['PUT', 'admin.pembelian.cancel', 'po', ['kasir', 'gudang']],
            ['PUT', 'admin.pembelian.payment-status', 'po', ['kasir', 'gudang']],
        ];

        $cases = [];
        foreach ($writes as [$method, $route, $param, $roles]) {
            foreach ($roles as $role) {
                $cases["{$role} {$method} {$route}"] = [$method, $route, $param, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('forbiddenWrites')]
    public function test_forbidden_roles_cannot_perform_write_actions(string $method, string $route, ?string $param, string $role): void
    {
        $this->signInAs($role);

        // Middleware role jalan SEBELUM validasi, jadi body kosong pun cukup untuk menguji penolakan.
        $this->call($method, route($route, $this->routeParameter($param)))->assertForbidden();
    }

    // === 1c. Guest & akun nonaktif ===

    public static function protectedRoutes(): array
    {
        return [
            'kasir.pos' => ['kasir.pos'],
            'gudang.produk.index' => ['gudang.produk.index'],
            'admin.dashboard' => ['admin.dashboard'],
            'admin.laporan.penjualan' => ['admin.laporan.penjualan'],
            'profile.edit' => ['profile.edit'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_are_redirected_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_deactivated_accounts_are_blocked_even_with_a_valid_session(): void
    {
        // Skenario nyata: admin menonaktifkan karyawan yang MASIH punya sesi login aktif.
        foreach (['kasir.pos' => 'kasir', 'gudang.produk.index' => 'gudang', 'admin.dashboard' => 'admin'] as $route => $role) {
            $user = User::factory()->{$role}()->inactive()->create();

            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    // === 1d. Struk & riwayat: kasir hanya boleh melihat miliknya sendiri ===

    public function test_kasir_can_open_own_receipt_but_not_another_kasirs(): void
    {
        $me = $this->signInAs('kasir');
        $mine = $this->makeTransaction($me, 'INV-MINE-0001');

        $this->get(route('kasir.riwayat.struk', $mine))->assertOk();

        // $this->transaction milik kasir lain -> tidak boleh dibuka (IDOR).
        $this->get(route('kasir.riwayat.struk', $this->transaction))->assertForbidden();
    }

    public function test_admin_can_open_any_receipt(): void
    {
        $this->signInAs('admin');

        $this->get(route('kasir.riwayat.struk', $this->transaction))->assertOk();
    }

    public function test_kasir_history_lists_only_own_transactions(): void
    {
        $me = $this->signInAs('kasir');
        $this->makeTransaction($me, 'INV-MINE-0001');

        $this->get(route('kasir.riwayat'))
            ->assertOk()
            ->assertSee('INV-MINE-0001')
            ->assertDontSee('INV-20260924-0001');
    }
}
