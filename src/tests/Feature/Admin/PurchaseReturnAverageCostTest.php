<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-025 — retur ke supplier mengoreksi nilai persediaan dan average_cost.
 *
 * Fixture: satuan dus=144, renceng=12, sachet=1 (dasar). Semua angka dihitung tangan:
 *   avg baru = (stok x avg - nilai retur) / (stok - qty retur), nilai retur = harga PO.
 */
class PurchaseReturnAverageCostTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        $this->hindariIdUserPertama();
        $this->gudang = User::factory()->gudang()->create();
    }

    protected function tearDown(): void
    {
        Product::flushEventListeners();
        PurchaseOrder::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: Product} */
    private function poDiterima(int $stock, int $avg, int $pricePerDus, int|float $received = 1): array
    {
        $product = $this->makeProduct(['stock' => $stock, 'average_cost' => $avg]);

        $po = $this->makePurchaseOrder(['status' => 'received'], [[
            'product' => $product,
            'quantity_ordered' => $received,
            'quantity_received' => $received,
            'unit_price' => $pricePerDus,
            'subtotal' => (int) round($pricePerDus * $received),
        ]]);

        return [$po, $po->items->first(), $product];
    }

    private function retur(PurchaseOrder $po, PurchaseOrderItem $item, string|int|float $qty, ?string $key = null, ?int $unitId = null)
    {
        return $this->actingAs($this->gudang)->postJson(
            route('gudang.pembelian.retur.store', $po),
            [
                'idempotency_key' => $key ?? (string) Str::uuid(),
                'reason' => 'Kemasan rusak',
                'items' => [$item->id => ['qty' => (string) $qty, 'unit_id' => $unitId ?? $item->product_unit_id]],
            ]
        );
    }

    private function avg(Product $p): int
    {
        return (int) Product::find($p->id)->average_cost;
    }

    public function test_kasus_qa_025_retur_batch_mahal_mengembalikan_rata_rata_ke_harga_batch_sisa(): void
    {
        // 144 sachet @1.000 + 144 sachet @2.000 => stok 288, rata-rata 1.500 (nilai Rp 432.000).
        // Batch kedua (1 dus @ Rp 288.000) diretur penuh => sisa 144 sachet @1.000.
        [$po, $item, $product] = $this->poDiterima(288, 1500, 288000);

        $this->retur($po, $item, 1)->assertOk();

        $fresh = Product::find($product->id);
        $this->assertEquals(144, $fresh->stock);
        $this->assertSame(1000, (int) $fresh->average_cost);
        // Nilai inventori (stok x rata-rata) = tepat nilai batch yang tersisa.
        $this->assertSame(144000, (int) round((float) $fresh->stock * $fresh->average_cost));
    }

    public function test_retur_sebagian_menghitung_ulang_dari_sisa_dan_dibulatkan_sekali(): void
    {
        // 0,5 dus = 72 sachet, nilai Rp 144.000. (288 x 1.500 - 144.000) / 216 = 1.333,33 -> 1.333.
        [$po, $item, $product] = $this->poDiterima(288, 1500, 288000);

        $this->retur($po, $item, '0.5')->assertOk();

        $this->assertSame(1333, $this->avg($product));
        $this->assertEquals(216, Product::find($product->id)->stock);
    }

    public function test_retur_dalam_satuan_lain_memakai_nilai_proporsional(): void
    {
        // 12 renceng = 144 sachet = 1 dus: hasil sama dengan retur 1 dus (nilai Rp 288.000).
        [$po, $item, $product] = $this->poDiterima(288, 1500, 288000);
        $renceng = $product->units->firstWhere('unit_name', 'renceng')->id;

        $this->retur($po, $item, 12, null, $renceng)->assertOk();

        $this->assertSame(1000, $this->avg($product));
    }

    public function test_stok_habis_mempertahankan_rata_rata_lama(): void
    {
        // Semua stok (144) diretur: tidak ada sisa untuk dinilai, rata-rata tidak dibagi nol.
        [$po, $item, $product] = $this->poDiterima(144, 2000, 288000);

        $this->retur($po, $item, 1)->assertOk();

        $fresh = Product::find($product->id);
        $this->assertEquals(0, $fresh->stock);
        $this->assertSame(2000, (int) $fresh->average_cost);
    }

    public function test_hasil_negatif_dijepit_ke_nol(): void
    {
        // Nilai persediaan tercatat (150 x 100 = 15.000) < nilai retur (Rp 288.000): hasil
        // mentah negatif. Tidak boleh pernah negatif.
        [$po, $item, $product] = $this->poDiterima(150, 100, 288000);

        $this->retur($po, $item, 1)->assertOk();

        $fresh = Product::find($product->id);
        $this->assertEquals(6, $fresh->stock);
        $this->assertSame(0, (int) $fresh->average_cost);
    }

    public function test_dua_produk_dalam_satu_retur_dikoreksi_sendiri_sendiri(): void
    {
        $p1 = $this->makeProduct(['stock' => 288, 'average_cost' => 1500]);
        $p2 = $this->makeProduct(['stock' => 288, 'average_cost' => 1000]);

        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $p1, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 288000, 'subtotal' => 288000],
            ['product' => $p2, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
        ]);
        [$i1, $i2] = [$po->items[0], $po->items[1]];

        $this->actingAs($this->gudang)->postJson(route('gudang.pembelian.retur.store', $po), [
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Rusak',
            'items' => [
                $i1->id => ['qty' => '1', 'unit_id' => $i1->product_unit_id],
                $i2->id => ['qty' => '1', 'unit_id' => $i2->product_unit_id],
            ],
        ])->assertOk();

        // p1: (288x1.500 - 288.000)/144 = 1.000 ; p2: (288x1.000 - 144.000)/144 = 1.000
        $this->assertSame(1000, $this->avg($p1));
        $this->assertSame(1000, $this->avg($p2));
    }

    public function test_kirim_ulang_dengan_kunci_sama_tidak_mengoreksi_dua_kali(): void
    {
        [$po, $item, $product] = $this->poDiterima(288, 1500, 288000);
        $key = (string) Str::uuid();

        $this->retur($po, $item, '0.5', $key)->assertOk();
        $setelahPertama = $this->avg($product);
        $this->retur($po, $item, '0.5', $key)->assertOk();

        $this->assertSame(1333, $setelahPertama);
        $this->assertSame(1333, $this->avg($product));
        $this->assertEquals(216, Product::find($product->id)->stock);
    }

    public function test_retur_yang_ditolak_tidak_mengubah_rata_rata(): void
    {
        // Stok 100 < 144 yang diretur: ditolak, rata-rata utuh.
        [$po, $item, $product] = $this->poDiterima(100, 1500, 288000);

        $this->retur($po, $item, 1)->assertStatus(422);

        $this->assertSame(1500, $this->avg($product));
        $this->assertEquals(100, Product::find($product->id)->stock);
    }

    public function test_return_out_tanpa_nilai_tidak_menyentuh_rata_rata(): void
    {
        // Jalur record() tanpa $totalCost (mis. pemakai lain) tetap tidak mengubah average_cost.
        $product = $this->makeProduct(['stock' => 100, 'average_cost' => 1500]);

        StockMovement::record($product, 'return_out', 40, $this->gudang->id, 'REF-1');

        $this->assertSame(1500, $this->avg($product));
        $this->assertEquals(60, Product::find($product->id)->stock);
    }

    public function test_retur_pelanggan_return_in_tetap_tidak_mengubah_rata_rata(): void
    {
        $product = $this->makeProduct(['stock' => 100, 'average_cost' => 1500]);

        StockMovement::record($product, 'return_in', 5, $this->gudang->id, 'RTN-1');

        $this->assertSame(1500, $this->avg($product));
    }

    public function test_metode_hitung_ulang_langsung(): void
    {
        $p = $this->makeProduct(['stock' => 200, 'average_cost' => 1500]);

        // (200x1.500 - 200.000)/100 = 1.000
        $this->assertSame(1000, $p->recalculateAverageCostAfterRemoval(100, 200000));
        // Sisa tepat 0 -> rata-rata dipertahankan.
        $this->assertSame(1500, $p->recalculateAverageCostAfterRemoval(200, 300000));
        // Sisa mikroskopis akibat float juga dianggap habis.
        $this->assertSame(1500, $p->recalculateAverageCostAfterRemoval(199.9999, 1));
        // Negatif dijepit.
        $this->assertSame(0, $p->recalculateAverageCostAfterRemoval(199, 900000));
        // Nilai 0 (retur barang gratis) -> rata-rata naik karena stok berkurang tanpa nilai keluar.
        $this->assertSame(3000, $p->recalculateAverageCostAfterRemoval(100, 0));
    }
}