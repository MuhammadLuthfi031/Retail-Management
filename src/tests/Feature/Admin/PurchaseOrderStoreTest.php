<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Regresi: PurchaseOrderController::store() sempat SELALU gagal dengan
 * MassAssignmentException ("Add fillable property [id]...") begitu
 * `Model::preventSilentlyDiscardingAttributes()` aktif (§QA-007).
 *
 * Sebab: extractItems() (dipakai bersama oleh store() & update()) selalu
 * menyertakan key `id` di tiap baris item — pola ini BENAR di update() (di
 * situ `id` dipakai membedakan item lama/baru, lalu di-unset sebelum
 * create()/update()), tapi store() lupa membuang key itu sebelum memanggil
 * `$po->items()->create($item)`. Karena `id` bukan fillable (primary key),
 * create() gagal — bahkan waktu isinya cuma null (PO baru tidak punya item
 * lama sama sekali). Sebelum guard QA-007 aktif, ini "berhasil" secara diam-
 * diam (key non-fillable dibuang otomatis oleh Laravel) — makanya lolos
 * tanpa ketahuan sampai sekarang: tidak ada test yang pernah memanggil
 * store() dengan payload sungguhan (celah yang sama dengan QA-004).
 */
class PurchaseOrderStoreTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->supplier = Supplier::forceCreate(['name' => 'Supplier Uji']);
        $this->product = $this->makeProduct();
    }

    private function store(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.pembelian.store'), array_merge([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDay()->toDateString(),
            'items' => $items,
        ], $extra));
    }

    /**
     * Reproduksi persis payload form "buat PO baru" di browser: baris item
     * ditambahkan lewat po-items.js TANPA field `id` sama sekali (hidden
     * input `items[i][id]` cuma ada di partial edit item yang sudah punya
     * baris di database — lihat _item-row.blade.php). Jadi test ini SENGAJA
     * tidak mengirim `id`, supaya bug (yang disuntikkan server sendiri lewat
     * `$row['id'] ?? null`, bukan dari client) tidak tertutupi.
     */
    public function test_creating_a_new_po_with_one_item_succeeds(): void
    {
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        $response = $this->store([
            ['product_id' => $this->product->id, 'product_unit_id' => $unit->id, 'quantity_ordered' => 10, 'unit_price' => 11000],
        ]);

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrderItem::count());

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('draft', $po->status);
        $this->assertSame('unpaid', $po->payment_status);
        $this->assertSame(110000, $po->total_amount); // 10 x 11.000, dihitung ULANG server, bukan dari client

        $item = PurchaseOrderItem::firstOrFail();
        $this->assertSame($this->product->id, $item->product_id);
        $this->assertSame($unit->id, $item->product_unit_id);
        $this->assertEquals(10.0, (float) $item->quantity_ordered);
        $this->assertSame(11000, $item->unit_price);
        $this->assertSame(110000, $item->subtotal);
    }

    public function test_creating_a_new_po_with_several_items_succeeds(): void
    {
        $renceng = $this->product->units->firstWhere('unit_name', 'renceng');
        $sachet = $this->product->units->firstWhere('unit_name', 'sachet');
        $second = $this->makeProduct();
        $secondUnit = $second->units->firstWhere('unit_name', 'renceng');

        $response = $this->store([
            ['product_id' => $this->product->id, 'product_unit_id' => $renceng->id, 'quantity_ordered' => 5, 'unit_price' => 11000],
            ['product_id' => $this->product->id, 'product_unit_id' => $sachet->id, 'quantity_ordered' => 20, 'unit_price' => 1000],
            ['product_id' => $second->id, 'product_unit_id' => $secondUnit->id, 'quantity_ordered' => 3, 'unit_price' => 11000],
        ]);

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertSame(3, PurchaseOrderItem::count());
        $this->assertSame(108000, PurchaseOrder::firstOrFail()->total_amount); // 55.000 + 20.000 + 33.000
    }

    /** Baris tanpa product_id/unit_id/qty valid dibuang oleh extractItems() — pastikan tidak ikut menyebabkan crash lain. */
    public function test_incomplete_rows_are_silently_skipped(): void
    {
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        $this->store([
            ['product_id' => $this->product->id, 'product_unit_id' => $unit->id, 'quantity_ordered' => 10, 'unit_price' => 11000],
            ['product_id' => '', 'product_unit_id' => '', 'quantity_ordered' => 0, 'unit_price' => 0],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, PurchaseOrderItem::count());
    }
}