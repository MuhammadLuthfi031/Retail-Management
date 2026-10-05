<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PurchaseReturnService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Retur ke supplier (retur pembelian) — Tahap 2a.
 *
 * Fixture standar: produk dengan satuan dus=144, renceng=12, sachet=1 (dasar); stok 720 (satuan
 * dasar), average_cost 1.000; PO berstatus 'received' dengan 5 dus diterima @ Rp 144.000
 * (= Rp 1.000 per sachet, total Rp 720.000). Semua angka di bawah dihitung tangan dari fixture itu.
 */
class PurchaseReturnTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    private User $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        $this->hindariIdUserPertama();
        $this->admin = User::factory()->admin()->create();
        $this->gudang = User::factory()->gudang()->create();
    }

    protected function tearDown(): void
    {
        Product::flushEventListeners();
        PurchaseOrder::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: Product} */
    private function poDiterima(
        int|float $received = 5,
        int $price = 144000,
        string $status = 'received',
        array $productAttr = [],
        ?array $units = null
    ): array {
        $product = $this->makeProduct(array_merge(['stock' => 720, 'average_cost' => 1000], $productAttr), $units);

        $po = $this->makePurchaseOrder(['status' => $status], [[
            'product' => $product,
            'quantity_ordered' => $received,
            'quantity_received' => $received,
            'unit_price' => $price,
            'subtotal' => (int) round($price * $received),
        ]]);

        return [$po, $po->items->first(), $product];
    }

    private function unitId(Product $product, string $name): int
    {
        return $product->units->firstWhere('unit_name', $name)->id;
    }

    /** Baris retur: satuan default = satuan beli item PO. */
    private function line(PurchaseOrderItem $item, string|int|float $qty, ?int $unitId = null): array
    {
        return [$item->id => ['qty' => (string) $qty, 'unit_id' => $unitId ?? $item->product_unit_id]];
    }

    private function retur(PurchaseOrder $po, array $items, array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->postJson(
            route('gudang.pembelian.retur.store', $po),
            array_merge([
                'idempotency_key' => (string) Str::uuid(),
                'reason' => 'Kemasan rusak',
                'items' => $items,
            ], $override)
        );
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, PurchaseReturn::count(), 'PurchaseReturn tidak boleh terbuat');
        $this->assertSame(0, PurchaseReturnItem::count(), 'PurchaseReturnItem tidak boleh terbuat');
        $this->assertSame(0, StockMovement::where('type', 'return_out')->count(), 'Stok tidak boleh bergerak');
    }

    private function nilaiItem(): array
    {
        return PurchaseReturnItem::orderBy('id')->pluck('value')->map(fn ($v) => (int) $v)->all();
    }

    // ----------------------------------------------------------- retur sukses (inti)

    public function test_retur_dus_mengurangi_stok_dan_mencatat_semuanya_tanpa_mengubah_po(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        $response = $this->retur($po, $this->line($item, 2), ['reason' => 'Penyok parah']);

        $response->assertOk()
            ->assertJsonPath('return_number', 'RTP-20261014-0001')
            ->assertJsonPath('created', true)
            ->assertJsonPath('message', 'Retur RTP-20261014-0001 berhasil dicatat. Stok sudah dikurangi.');

        $return = PurchaseReturn::firstOrFail();
        $this->assertSame('RTP-20261014-0001', $return->return_number);
        $this->assertSame($po->id, $return->purchase_order_id);
        $this->assertSame($this->gudang->id, $return->user_id);
        $this->assertSame('Penyok parah', $return->reason);
        $this->assertSame(288000, (int) $return->total_value);   // 2 dus x Rp 144.000
        $this->assertSame('pending', $return->status);
        $this->assertNull($return->settlement_type);
        $this->assertNull($return->settled_by);
        $this->assertNull($return->settled_at);

        $row = $return->items()->firstOrFail();
        $this->assertSame($item->id, $row->purchase_order_item_id);
        $this->assertSame($product->id, $row->product_id);
        $this->assertSame($product->name, $row->product_name);
        $this->assertSame('dus', $row->unit_name);
        $this->assertEquals(144, $row->unit_conversion);
        $this->assertEquals(2, $row->quantity);
        $this->assertEquals(288, $row->quantity_base);
        $this->assertSame(288000, (int) $row->value);

        // Stok: 720 - 2 dus x 144 = 432
        $this->assertEquals(432, $this->stockOf($product));
        $movement = StockMovement::where('type', 'return_out')->firstOrFail();
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame($this->gudang->id, $movement->user_id);
        $this->assertEquals(288, $movement->quantity);
        $this->assertEquals(720, $movement->stock_before);
        $this->assertEquals(432, $movement->stock_after);
        $this->assertSame('RTP-20261014-0001', $movement->reference);

        // Invarian: average_cost, PO, dan qty diterima TIDAK berubah.
        $this->assertSame(1000, (int) Product::find($product->id)->average_cost);
        $freshPo = PurchaseOrder::find($po->id);
        $this->assertSame('received', $freshPo->status);
        $this->assertSame(720000, (int) $freshPo->total_amount);
        $this->assertEquals(5, PurchaseOrderItem::find($item->id)->quantity_received);
    }

    public function test_satuan_retur_boleh_beda_dari_satuan_beli_nilai_proporsional(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        // 3 sachet -> base 3 -> 720.000 x 3/720 = 3.000
        $this->retur($po, $this->line($item, 3, $this->unitId($product, 'sachet')))->assertOk();
        // 2 renceng -> base 24 -> 24.000
        $this->retur($po, $this->line($item, 2, $this->unitId($product, 'renceng')))->assertOk();

        $this->assertSame([3000, 24000], $this->nilaiItem());
        $this->assertEquals(720 - 3 - 24, $this->stockOf($product));

        $rows = PurchaseReturnItem::orderBy('id')->get();
        $this->assertSame(['sachet', 'renceng'], $rows->pluck('unit_name')->all());
        $this->assertEquals([3, 24], $rows->pluck('quantity_base')->map(fn ($v) => (float) $v)->all());
    }

    public function test_setengah_dus_diizinkan_karena_hasilnya_bulat_di_satuan_dasar(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        $this->retur($po, $this->line($item, '0.5'))->assertOk();

        $this->assertSame([72000], $this->nilaiItem());           // 72 sachet
        $this->assertEquals(648, $this->stockOf($product));
    }

    public function test_pecahan_satuan_dasar_ditolak_untuk_produk_non_curah(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        // 0,001 dus = 0,144 sachet (tidak bulat)
        $this->retur($po, $this->line($item, '0.001'))->assertJsonValidationErrors([
            'items' => "Produk \"{$product->name}\" tidak bisa diretur dalam pecahan satuan dasar (sachet).",
        ]);
        $this->assertNothingCreated();
    }

    public function test_produk_curah_boleh_diretur_pecahan_kg(): void
    {
        // Karung 25 kg; 2 karung diterima @ Rp 250.000 = Rp 500.000 (Rp 10.000/kg), stok 50 kg.
        $units = [
            ['unit_name' => 'karung', 'conversion_to_base' => 25, 'is_purchase_unit' => true],
            ['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 12000, 'is_base_unit' => true],
        ];
        [$po, $item, $product] = $this->poDiterima(2, 250000, 'received',
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 50], $units);

        // 12,5 kg -> 500.000 x 12,5/50 = 125.000
        $this->retur($po, $this->line($item, '12.5', $this->unitId($product, 'kg')))->assertOk();
        // 0,5 karung = 12,5 kg -> 125.000
        $this->retur($po, $this->line($item, '0.5'))->assertOk();

        $this->assertSame([125000, 125000], $this->nilaiItem());
        $this->assertEquals(25, $this->stockOf($product));
    }

    public function test_qty_yang_terlalu_kecil_di_satuan_dasar_ditolak(): void
    {
        // 1 gram = 0,001 kg; 0,001 gram = 0,000001 kg -> membulat 0 di satuan dasar (per-seribu).
        $units = [
            ['unit_name' => 'karung', 'conversion_to_base' => 25, 'is_purchase_unit' => true],
            ['unit_name' => 'gram', 'conversion_to_base' => 0.001],
            ['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 12000, 'is_base_unit' => true],
        ];
        [$po, $item, $product] = $this->poDiterima(2, 250000, 'received',
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 50], $units);

        $this->retur($po, $this->line($item, '0.001', $this->unitId($product, 'gram')))->assertJsonValidationErrors([
            'items' => "Qty retur \"{$product->name}\" terlalu kecil.",
        ]);
        $this->assertNothingCreated();

        // 500 gram = 0,5 kg -> lolos, nilai 500.000 x 0,5/50 = 5.000
        $this->retur($po, $this->line($item, '500', $this->unitId($product, 'gram')))->assertOk();
        $this->assertSame([5000], $this->nilaiItem());
    }

    public function test_beberapa_item_sekaligus_dijumlahkan(): void
    {
        $p1 = $this->makeProduct(['stock' => 720, 'average_cost' => 1000]);
        $p2 = $this->makeProduct(['stock' => 144, 'average_cost' => 500]);
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $p1, 'quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
            ['product' => $p2, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 72000, 'subtotal' => 72000],
        ]);
        [$i1, $i2] = $po->items->all();

        $this->retur($po, $this->line($i1, 1) + $this->line($i2, 1))->assertOk();

        $this->assertSame(144000 + 72000, (int) PurchaseReturn::firstOrFail()->total_value);
        $this->assertEquals(576, $this->stockOf($p1));   // 720 - 144
        $this->assertEquals(0, $this->stockOf($p2));     // 144 - 144
        $this->assertSame(2, StockMovement::where('type', 'return_out')->count());
    }

    public function test_baris_qty_nol_atau_kosong_diabaikan(): void
    {
        $p1 = $this->makeProduct(['stock' => 720]);
        $p2 = $this->makeProduct(['stock' => 144]);
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $p1, 'quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
            ['product' => $p2, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 72000, 'subtotal' => 72000],
        ]);
        [$i1, $i2] = $po->items->all();

        $this->retur($po, $this->line($i1, 1) + $this->line($i2, 0))->assertOk();

        $this->assertSame(1, PurchaseReturnItem::count());
        $this->assertEquals(144, $this->stockOf($p2));
    }

    public function test_po_diterima_sebagian_boleh_diretur_dan_item_yang_belum_diterima_ditolak(): void
    {
        $p1 = $this->makeProduct(['stock' => 144]);
        $p2 = $this->makeProduct(['stock' => 0]);
        $po = $this->makePurchaseOrder(['status' => 'partially_received'], [
            ['product' => $p1, 'quantity_ordered' => 5, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 720000],
            ['product' => $p2, 'quantity_ordered' => 5, 'quantity_received' => 0, 'unit_price' => 144000, 'subtotal' => 720000],
        ]);
        [$i1, $i2] = $po->items->all();

        // Diterima baru 1 dari 5 dus dipesan: batas & nilai mengikuti yang DITERIMA, bukan yang dipesan.
        $this->retur($po, $this->line($i1, 2))->assertJsonValidationErrors([
            'items' => "Qty retur \"{$p1->name}\" melebihi sisa yang bisa diretur (144 sachet).",
        ]);
        $this->retur($po, $this->line($i1, 1))->assertOk();   // yang sudah diterima: boleh
        $this->assertSame([144000], $this->nilaiItem());      // 1 dus x Rp 144.000 (bukan subtotal PO Rp 720.000)

        $this->retur($po, $this->line($i2, 1))->assertJsonValidationErrors([
            'items' => 'Ada barang yang bukan bagian dari PO ini atau belum diterima.',
        ]);
        $this->assertSame(1, PurchaseReturn::count());
    }

    // --------------------------------------------------------- nilai & pembulatan

    public function test_nilai_dibulatkan_dan_retur_terakhir_mengambil_sisa(): void
    {
        // 1 dus (144 sachet) @ Rp 100. Retur 1 sachet = 100/144 = 0,69 -> 1 (round; floor akan 0).
        // Retur 143 sachet sisanya (menghabiskan) = sisa nilai 99. Total tepat Rp 100.
        [$po, $item, $product] = $this->poDiterima(1, 100, 'received', ['stock' => 144]);

        $this->retur($po, $this->line($item, 1, $this->unitId($product, 'sachet')))->assertOk();
        $this->retur($po, $this->line($item, 143, $this->unitId($product, 'sachet')))->assertOk();

        $this->assertSame([1, 99], $this->nilaiItem());
        $this->assertSame(100, array_sum($this->nilaiItem()));
    }

    public function test_nilai_satu_item_tidak_pernah_melebihi_yang_dibayar_walau_pembulatan_menumpuk(): void
    {
        // 1 pak = 5 pcs @ Rp 3 (total Rp 3). Tiap retur 1 pcs: 3/5 = 0,6 -> 1.
        // Tanpa batas "sisa nilai", retur ke-4 ikut mengembalikan Rp 1 (total 4 > 3).
        $units = [
            ['unit_name' => 'pak', 'conversion_to_base' => 5, 'is_purchase_unit' => true],
            ['unit_name' => 'pcs', 'conversion_to_base' => 1, 'selling_price' => 100, 'is_base_unit' => true],
        ];
        [$po, $item, $product] = $this->poDiterima(1, 3, 'received', ['stock' => 5], $units);
        $pcs = $this->unitId($product, 'pcs');

        for ($i = 0; $i < 5; $i++) {
            $this->retur($po, $this->line($item, 1, $pcs))->assertOk();
        }

        $this->assertSame([1, 1, 1, 0, 0], $this->nilaiItem());
        $this->assertSame(3, array_sum($this->nilaiItem()));
    }

    public function test_total_nilai_tetap_tepat_walau_tiap_retur_dibulatkan_ke_bawah(): void
    {
        // 1 pak = 5 pcs @ Rp 2 (Rp 0,40/pcs). Retur 1 pcs -> 0,4 -> Rp 0; lagi 1 pcs -> Rp 0.
        // Retur terakhir 3 pcs menghabiskan sisa: harus mengambil SISA nilai (Rp 2), bukan proporsional
        // (2 x 3/5 = 1,2 -> Rp 1) — kalau tidak, total retur Rp 1 padahal yang dibayar Rp 2.
        $units = [
            ['unit_name' => 'pak', 'conversion_to_base' => 5, 'is_purchase_unit' => true],
            ['unit_name' => 'pcs', 'conversion_to_base' => 1, 'selling_price' => 100, 'is_base_unit' => true],
        ];
        [$po, $item, $product] = $this->poDiterima(1, 2, 'received', ['stock' => 5], $units);
        $pcs = $this->unitId($product, 'pcs');

        $this->retur($po, $this->line($item, 1, $pcs))->assertOk();
        $this->retur($po, $this->line($item, 1, $pcs))->assertOk();
        $this->retur($po, $this->line($item, 3, $pcs))->assertOk();

        $this->assertSame([0, 0, 2], $this->nilaiItem());
        $this->assertSame(2, array_sum($this->nilaiItem()));
    }

    public function test_nilai_memakai_harga_beli_po_bukan_average_cost(): void
    {
        // average_cost produk 1.000/sachet, tapi harga beli di PO Rp 90.000/dus (= Rp 625/sachet).
        [$po, $item] = $this->poDiterima(5, 90000);

        $this->retur($po, $this->line($item, 2))->assertOk();

        $this->assertSame(180000, (int) PurchaseReturn::firstOrFail()->total_value);
    }

    // ------------------------------------------------------- batas qty (over-return)

    public function test_qty_melebihi_diterima_ditolak_dan_tepat_di_batas_lolos(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        $this->retur($po, $this->line($item, 6))->assertJsonValidationErrors([
            'items' => "Qty retur \"{$product->name}\" melebihi sisa yang bisa diretur (720 sachet).",
        ]);
        $this->assertNothingCreated();

        $this->retur($po, $this->line($item, 5))->assertOk();   // tepat 5 dus = 720 sachet
        $this->assertEquals(0, $this->stockOf($product));
        $this->assertSame([720000], $this->nilaiItem());
    }

    public function test_batas_kumulatif_lintas_satuan_dan_sisa_mengambil_sisa_nilai(): void
    {
        [$po, $item, $product] = $this->poDiterima();
        $sachet = $this->unitId($product, 'sachet');

        $this->retur($po, $this->line($item, 3, $sachet))->assertOk();   // sisa 717 sachet

        $this->retur($po, $this->line($item, 5))->assertJsonValidationErrors([   // 5 dus = 720 > 717
            'items' => "Qty retur \"{$product->name}\" melebihi sisa yang bisa diretur (717 sachet).",
        ]);
        $this->assertSame(1, PurchaseReturn::count());

        $this->retur($po, $this->line($item, 717, $sachet))->assertOk();  // tepat sisa
        $this->assertSame([3000, 717000], $this->nilaiItem());
        $this->assertSame(720000, array_sum($this->nilaiItem()));
    }

    public function test_batas_pecahan_sepersekian_ditolak(): void
    {
        $units = [
            ['unit_name' => 'karung', 'conversion_to_base' => 25, 'is_purchase_unit' => true],
            ['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 12000, 'is_base_unit' => true],
        ];
        [$po, $item, $product] = $this->poDiterima(2, 250000, 'received',
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 50], $units);
        $kg = $this->unitId($product, 'kg');

        $this->retur($po, $this->line($item, '50.001', $kg))->assertJsonValidationErrors([
            'items' => "Qty retur \"{$product->name}\" melebihi sisa yang bisa diretur (50 kg).",
        ]);
        $this->retur($po, $this->line($item, '50.000', $kg))->assertOk();
    }

    public function test_qty_diterima_yang_bertambah_kemudian_menambah_sisa_yang_bisa_diretur(): void
    {
        // Retur habis (5 dus). Lalu penerimaan susulan membuat diterima jadi 6 dus (864 sachet)
        // -> sisa 144 sachet, nilai sisa = 864.000 - 720.000 = 144.000.
        [$po, $item, $product] = $this->poDiterima();
        $this->retur($po, $this->line($item, 5))->assertOk();

        PurchaseOrderItem::whereKey($item->id)->update(['quantity_received' => 6]);
        Product::whereKey($product->id)->update(['stock' => 144]);

        $this->retur($po, $this->line($item, 2))->assertJsonValidationErrors([
            'items' => "Qty retur \"{$product->name}\" melebihi sisa yang bisa diretur (144 sachet).",
        ]);
        $this->retur($po, $this->line($item, 1))->assertOk();

        $this->assertSame([720000, 144000], $this->nilaiItem());
    }

    // -------------------------------------------------------------------- stok

    public function test_stok_tidak_cukup_ditolak_dengan_pesan_menyebut_produk(): void
    {
        // Sebagian sudah terjual: stok tinggal 100 sachet, retur 2 dus = 288 sachet.
        [$po, $item, $product] = $this->poDiterima(5, 144000, 'received', ['stock' => 100]);

        $this->retur($po, $this->line($item, 2))->assertJsonValidationErrors([
            'items' => "Stok \"{$product->name}\" tidak cukup untuk diretur (stok saat ini 100 sachet, diminta 288 sachet). Barang yang sudah terjual tidak bisa dikembalikan ke supplier.",
        ]);
        $this->assertNothingCreated();
        $this->assertEquals(100, $this->stockOf($product));

        // Batas: stok tepat 288 -> lolos.
        Product::whereKey($product->id)->update(['stock' => 288]);
        $this->retur($po, $this->line($item, 2))->assertOk();
        $this->assertEquals(0, $this->stockOf($product));
    }

    public function test_stok_dicek_kumulatif_untuk_produk_yang_sama_di_beberapa_baris(): void
    {
        // Produk sama di dua item PO (144 sachet masing-masing); stok 200: tiap baris sendiri lolos
        // (144 <= 200) tapi bersama 288 > 200.
        $product = $this->makeProduct(['stock' => 200]);
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $product, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
            ['product' => $product, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
        ]);
        [$i1, $i2] = $po->items->all();

        $this->retur($po, $this->line($i1, 1) + $this->line($i2, 1))->assertJsonValidationErrors([
            'items' => "Stok \"{$product->name}\" tidak cukup untuk diretur (stok saat ini 200 sachet, diminta 288 sachet). Barang yang sudah terjual tidak bisa dikembalikan ke supplier.",
        ]);
        $this->assertNothingCreated();
    }

    // ------------------------------------------------------------- status PO

    public function test_po_yang_belum_ada_barang_diterima_tidak_bisa_diretur(): void
    {
        foreach (['draft', 'ordered', 'cancelled'] as $status) {
            [$po, $item] = $this->poDiterima(5, 144000, $status);

            $this->retur($po, $this->line($item, 1))->assertJsonValidationErrors([
                'items' => 'PO ini belum ada barang yang diterima, tidak bisa diretur.',
            ]);

            $this->actingAs($this->gudang)->getJson(route('gudang.pembelian.retur.form', $po))
                ->assertStatus(422)
                ->assertJsonPath('message', 'PO ini belum ada barang yang diterima, tidak bisa diretur.');
        }
        $this->assertNothingCreated();
    }

    // ------------------------------------------------------------ validasi input

    public function test_item_dari_po_lain_ditolak(): void
    {
        [$po] = $this->poDiterima();
        [, $itemLain] = $this->poDiterima();

        $this->retur($po, $this->line($itemLain, 1))->assertJsonValidationErrors([
            'items' => 'Ada barang yang bukan bagian dari PO ini atau belum diterima.',
        ]);
        $this->assertNothingCreated();
    }

    public function test_satuan_harus_milik_produk_itu_sendiri(): void
    {
        [$po, $item, $product] = $this->poDiterima();
        $produkLain = $this->makeProduct();
        $label = "Pilih satuan retur yang valid untuk \"{$product->name}\".";

        $this->retur($po, $this->line($item, 1, $produkLain->units->first()->id))
            ->assertJsonValidationErrors(['items' => $label]);
        $this->retur($po, [$item->id => ['qty' => '1']])
            ->assertJsonValidationErrors(['items' => $label]);
        $this->retur($po, [$item->id => ['qty' => '1', 'unit_id' => '999999']])
            ->assertJsonValidationErrors(['items' => $label]);
        $this->retur($po, [$item->id => ['qty' => '1', 'unit_id' => 'abc']])
            ->assertJsonValidationErrors(["items.{$item->id}.unit_id" => 'Satuan retur tidak valid.']);
        $this->assertNothingCreated();
    }

    public function test_tidak_ada_qty_sama_sekali_ditolak(): void
    {
        [$po, $item] = $this->poDiterima();

        $this->retur($po, $this->line($item, 0))->assertJsonValidationErrors(['items' => 'Isi qty retur minimal pada satu barang.']);
        $this->retur($po, [$item->id => ['qty' => '', 'unit_id' => $item->product_unit_id]])
            ->assertJsonValidationErrors(['items' => 'Isi qty retur minimal pada satu barang.']);
        $this->retur($po, [], ['items' => null])->assertJsonValidationErrors('items');
        $this->assertNothingCreated();
    }

    public function test_qty_negatif_bukan_angka_lebih_3_desimal_dan_raksasa_ditolak(): void
    {
        [$po, $item] = $this->poDiterima();
        $key = "items.{$item->id}.qty";

        $this->retur($po, $this->line($item, '-1'))->assertJsonValidationErrors([$key => 'Qty retur tidak boleh negatif.']);
        $this->retur($po, $this->line($item, 'abc'))->assertJsonValidationErrors([$key => 'Qty retur harus berupa angka.']);
        $this->retur($po, $this->line($item, '0.0001'))->assertJsonValidationErrors([$key => 'Qty retur maksimal 3 angka di belakang koma.']);
        $this->retur($po, $this->line($item, str_repeat('9', 25)))->assertJsonValidationErrors([$key => 'Qty retur terlalu besar.']);
        $this->assertNothingCreated();
    }

    public function test_alasan_dan_kunci_idempotensi_divalidasi(): void
    {
        [$po, $item] = $this->poDiterima();
        $items = $this->line($item, 1);

        $this->retur($po, $items, ['reason' => ''])->assertJsonValidationErrors(['reason' => 'Alasan retur wajib diisi.']);
        $this->retur($po, $items, ['reason' => str_repeat('a', 256)])->assertJsonValidationErrors(['reason' => 'Alasan retur maksimal 255 karakter.']);
        $this->retur($po, $items, ['idempotency_key' => 'bukan-uuid'])->assertJsonValidationErrors('idempotency_key');
        $this->assertNothingCreated();

        $this->retur($po, $items, ['reason' => str_repeat('a', 255)])->assertOk();   // batas 255 lolos
    }

    // -------------------------------------------------------------- idempotency

    public function test_kirim_ulang_dengan_kunci_sama_tidak_membuat_retur_ganda_walau_sisa_sudah_habis(): void
    {
        [$po, $item, $product] = $this->poDiterima();
        $key = (string) Str::uuid();

        // Retur PENUH: setelahnya sisa = 0, jadi tanpa jalur replay request kedua gagal "melebihi sisa".
        $first = $this->retur($po, $this->line($item, 5), ['idempotency_key' => $key]);
        $return = PurchaseReturn::firstOrFail();
        $first->assertOk()->assertJsonPath('created', true)
            ->assertSessionHas('success', "Retur {$return->return_number} berhasil dicatat. Stok sudah dikurangi.");

        $second = $this->retur($po, $this->line($item, 5), ['idempotency_key' => $key]);
        $second->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('return_number', $return->return_number)
            ->assertJsonPath('message', "Retur {$return->return_number} sudah tercatat sebelumnya (tidak dibuat ganda).");

        $this->assertSame(1, PurchaseReturn::count());
        $this->assertSame(1, PurchaseReturnItem::count());
        $this->assertSame(1, StockMovement::where('type', 'return_out')->count());
        $this->assertEquals(0, $this->stockOf($product));   // turun SEKALI saja
    }

    public function test_kunci_yang_sama_untuk_po_lain_atau_pelaku_lain_ditolak(): void
    {
        [$po1, $item1] = $this->poDiterima();
        [$po2, $item2] = $this->poDiterima();
        $key = (string) Str::uuid();
        $msg = ['idempotency_key' => 'Kode retur ini sudah dipakai. Muat ulang halaman lalu coba lagi.'];

        $this->retur($po1, $this->line($item1, 1), ['idempotency_key' => $key])->assertOk();

        $this->retur($po2, $this->line($item2, 1), ['idempotency_key' => $key])->assertJsonValidationErrors($msg);
        $this->retur($po1, $this->line($item1, 1), ['idempotency_key' => $key], $this->admin)->assertJsonValidationErrors($msg);
        $this->assertSame(1, PurchaseReturn::count());
    }

    public function test_request_kembar_yang_menunggu_lock_mengembalikan_retur_pertama_bukan_error(): void
    {
        // Simulasi race deterministik: tepat saat baris PO dikunci (retrieval ke-2; ke-1 = route-model
        // binding), "request lain" sudah commit retur PENUH dengan kunci yang sama. Cek replay
        // pasca-lock harus mengembalikannya. Catatan: ini membuktikan LOGIKA lapis pasca-lock;
        // serialisasi lock antar-koneksi MySQL tetap belum teruji di sandbox (QA-014).
        [$po, $item, $product] = $this->poDiterima();
        $key = (string) Str::uuid();
        $kembar = null;
        $n = 0;

        PurchaseOrder::retrieved(function () use (&$n, &$kembar, $po, $item, $product, $key) {
            if (++$n === 2) {
                $kembar = PurchaseReturn::forceCreate([
                    'return_number' => 'RTP-KEMBAR', 'idempotency_key' => $key,
                    'purchase_order_id' => $po->id, 'user_id' => $this->gudang->id,
                    'reason' => 'kembar', 'total_value' => 720000, 'status' => 'pending',
                ]);
                PurchaseReturnItem::forceCreate([
                    'purchase_return_id' => $kembar->id, 'purchase_order_item_id' => $item->id,
                    'product_id' => $product->id, 'product_name' => $product->name,
                    'unit_name' => 'dus', 'unit_conversion' => 144, 'quantity' => 5,
                    'quantity_base' => 720, 'value' => 720000,
                ]);
            }
        });

        $this->retur($po, $this->line($item, 5), ['idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('return_number', 'RTP-KEMBAR');

        $this->assertSame(1, PurchaseReturn::count());
        $this->assertSame(0, StockMovement::where('type', 'return_out')->count());
        $this->assertEquals(720, $this->stockOf($product));
    }

    // ------------------------------------------------------------------ penomoran

    public function test_nomor_retur_berurutan_per_hari_dan_reset_di_hari_berikutnya(): void
    {
        [$po, $item] = $this->poDiterima();

        $this->retur($po, $this->line($item, 1));
        $this->retur($po, $this->line($item, 1));
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00'));
        $this->retur($po, $this->line($item, 1));

        $this->assertSame(
            ['RTP-20261014-0001', 'RTP-20261014-0002', 'RTP-20261015-0001'],
            PurchaseReturn::orderBy('id')->pluck('return_number')->all()
        );
    }

    // ------------------------------------------------------------------ atomisitas

    public function test_kegagalan_di_tengah_proses_membatalkan_seluruh_retur(): void
    {
        $p1 = $this->makeProduct(['stock' => 144]);
        $p2 = $this->makeProduct(['stock' => 144]);
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $p1, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
            ['product' => $p2, 'quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
        ]);
        [$i1, $i2] = $po->items->all();

        // DB gagal saat mengurangi stok produk ke-2 (SETELAH produk ke-1 berhasil).
        Product::saving(function (Product $p) use ($p2) {
            if ($p->id === $p2->id && $p->isDirty('stock')) {
                throw new \RuntimeException('simulasi gagal');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->retur($po, $this->line($i1, 1) + $this->line($i2, 1));
            $this->fail('Seharusnya melempar exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulasi gagal', $e->getMessage());
        }

        $this->assertNothingCreated();
        $this->assertEquals(144, $this->stockOf($p1), 'Stok produk ke-1 harus ikut ter-rollback');
        $this->assertEquals(144, $this->stockOf($p2));
    }

    // ------------------------------------------------------------ akses (role)

    public function test_kasir_tidak_boleh_memproses_retur_ke_supplier(): void
    {
        [$po, $item] = $this->poDiterima();
        $kasir = User::factory()->kasir()->create();

        $this->actingAs($kasir)->getJson(route('gudang.pembelian.retur.form', $po))->assertForbidden();
        $this->retur($po, $this->line($item, 1), [], $kasir)->assertForbidden();
        $this->assertNothingCreated();
    }

    public function test_gudang_dan_admin_boleh_membuat_retur_dan_tercatat_atas_namanya(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        foreach ([$this->gudang, $this->admin] as $user) {
            $this->actingAs($user)->getJson(route('gudang.pembelian.retur.form', $po))->assertOk()->assertJsonStructure(['html']);
            $this->retur($po, $this->line($item, 1), [], $user)->assertOk();
        }

        $this->assertSame(
            [$this->gudang->id, $this->admin->id],
            PurchaseReturn::orderBy('id')->pluck('user_id')->all()
        );
        $this->assertSame(
            [$this->gudang->id, $this->admin->id],
            StockMovement::where('type', 'return_out')->orderBy('id')->pluck('user_id')->all()
        );
    }

    public function test_halaman_admin_retur_pembelian_hanya_untuk_admin(): void
    {
        [$po, $item] = $this->poDiterima();
        $this->retur($po, $this->line($item, 1))->assertOk();
        $return = PurchaseReturn::firstOrFail();

        foreach (['kasir', 'gudang'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->get(route('admin.retur-pembelian.index'))->assertForbidden();
            $this->get(route('admin.retur-pembelian.show', $return))->assertForbidden();
            $this->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => 'refund'])->assertForbidden();
        }

        $this->assertSame('pending', $return->fresh()->status, 'Percobaan terlarang tidak boleh mengubah status');
    }

    public function test_tamu_ditolak(): void
    {
        [$po] = $this->poDiterima();

        $this->getJson(route('gudang.pembelian.retur.form', $po))->assertUnauthorized();
        $this->postJson(route('gudang.pembelian.retur.store', $po), [])->assertUnauthorized();
        $this->get(route('admin.retur-pembelian.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------- kerahasiaan nilai: Gudang tidak boleh lihat uang

    public function test_form_untuk_gudang_tidak_memuat_harga_atau_nilai_sama_sekali(): void
    {
        // Harga SENGAJA unik (Rp 123.457/dus; total 5 dus = Rp 617.285) supaya tidak bertabrakan dengan
        // angka non-uang di HTML (mis. konversi 144 x 1000 = 144000, sisa dasar 720000): tiap kemunculan
        // angka di bawah pasti kebocoran harga/nilai.
        [$po] = $this->poDiterima(5, 123457);

        $html = $this->actingAs($this->gudang)->getJson(route('gudang.pembelian.retur.form', $po))->assertOk()->json('html');

        foreach (['Rp', '123.457', '123457', '617.285', '617285', 'harga beli'] as $bocor) {
            $this->assertStringNotContainsString($bocor, $html, "Form Gudang membocorkan: {$bocor}");
        }
        foreach (['data-total-value', 'data-remaining-value', 'data-received-base', 'data-retur-total'] as $attr) {
            $this->assertStringNotContainsString($attr, $html);
        }
        // ...tetapi qty dan satuan tetap ada.
        $this->assertStringContainsString('data-remaining-base="720000"', $html);
        $this->assertStringContainsString('data-retur-unit', $html);
    }

    public function test_form_untuk_admin_memuat_harga_dan_data_nilai_untuk_estimasi(): void
    {
        [$po] = $this->poDiterima(5, 123457);   // fixture yang sama dengan test Gudang di atas

        $html = $this->actingAs($this->admin)->getJson(route('gudang.pembelian.retur.form', $po))->assertOk()->json('html');

        $this->assertStringContainsString('harga beli Rp 123.457/dus', $html);
        $this->assertStringContainsString('data-total-value="617285"', $html);
        $this->assertStringContainsString('data-remaining-value="617285"', $html);
        $this->assertStringContainsString('data-received-base="720000"', $html);
        $this->assertStringContainsString('data-retur-total', $html);
    }

    public function test_respons_simpan_tidak_pernah_memuat_nilai(): void
    {
        [$po, $item] = $this->poDiterima();

        $json = $this->retur($po, $this->line($item, 2))->assertOk()->json();

        $this->assertEqualsCanonicalizing(['message', 'return_number', 'created'], array_keys($json));
        $this->assertStringNotContainsString('288', json_encode($json));
    }

    public function test_halaman_po_gudang_tidak_menampilkan_nilai_retur_tetapi_admin_ya(): void
    {
        [$po, $item] = $this->poDiterima();
        $this->retur($po, $this->line($item, 2))->assertOk();
        $return = PurchaseReturn::firstOrFail();

        $this->actingAs($this->gudang)->get(route('gudang.pembelian.show', $po))->assertOk()
            ->assertSee($return->return_number)
            ->assertSee('2 dus')
            ->assertSee('Menunggu penyelesaian')
            ->assertDontSee('Rp')
            ->assertDontSee('288.000')
            ->assertDontSee(route('admin.retur-pembelian.show', $return), false);

        $this->actingAs($this->admin)->get(route('admin.pembelian.show', $po))->assertOk()
            ->assertSee($return->return_number)
            ->assertSee('Rp 288.000')
            ->assertSee(route('admin.retur-pembelian.show', $return), false);
    }

    // ------------------------------------------------------------- penyelesaian

    private function returPending(): PurchaseReturn
    {
        [$po, $item] = $this->poDiterima();
        $this->retur($po, $this->line($item, 2))->assertOk();

        return PurchaseReturn::latest('id')->firstOrFail();
    }

    public function test_admin_menyelesaikan_retur_sebagai_refund_atau_potong_tagihan(): void
    {
        foreach (['refund' => 'refund dari supplier', 'credit' => 'potong tagihan'] as $type => $label) {
            $return = $this->returPending();
            $stokSebelum = Product::find($return->items->first()->product_id)->stock;

            $this->actingAs($this->admin)
                ->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => $type, 'settlement_note' => 'Transfer 15 Okt'])
                ->assertRedirect(route('admin.retur-pembelian.show', $return))
                ->assertSessionHas('success', "Retur {$return->return_number} ditandai selesai ({$label}).");

            $fresh = $return->fresh();
            $this->assertSame('settled', $fresh->status);
            $this->assertSame($type, $fresh->settlement_type);
            $this->assertSame('Transfer 15 Okt', $fresh->settlement_note);
            $this->assertSame($this->admin->id, $fresh->settled_by);
            $this->assertTrue($fresh->settled_at->equalTo(Carbon::parse('2026-10-14 12:00:00')));
            $this->assertSame(288000, (int) $fresh->total_value, 'Penyelesaian tidak mengubah nilai');
            $this->assertEquals($stokSebelum, Product::find($return->items->first()->product_id)->stock, 'Penyelesaian tidak menyentuh stok');
        }
    }

    public function test_catatan_penyelesaian_boleh_kosong(): void
    {
        $return = $this->returPending();

        $this->actingAs($this->admin)->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => 'refund'])
            ->assertSessionHasNoErrors();

        $this->assertNull($return->fresh()->settlement_note);
        $this->assertSame('settled', $return->fresh()->status);
    }

    public function test_retur_yang_sudah_selesai_tidak_bisa_diselesaikan_lagi(): void
    {
        $return = $this->returPending();
        $this->actingAs($this->admin)->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => 'refund', 'settlement_note' => 'pertama']);

        $this->actingAs($this->admin)
            ->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => 'credit', 'settlement_note' => 'kedua'])
            ->assertSessionHasErrors(['status' => 'Retur ini sudah diselesaikan sebelumnya.']);

        $fresh = $return->fresh();
        $this->assertSame('refund', $fresh->settlement_type, 'Jenis pertama tidak boleh tertimpa');
        $this->assertSame('pertama', $fresh->settlement_note);
    }

    public function test_service_menolak_jenis_penyelesaian_tidak_valid_walau_dipanggil_tanpa_http(): void
    {
        // Guard ini tertutupi validasi controller lewat HTTP, tapi service adalah pintu tunggal yang
        // bisa dipanggil dari mana pun (mis. Tahap 2b), jadi harus menjaga dirinya sendiri.
        $return = $this->returPending();

        foreach (['replacement', 'bebas', ''] as $type) {
            try {
                app(PurchaseReturnService::class)->settle($return, $type, null, $this->admin->id);
                $this->fail("Jenis '{$type}' seharusnya ditolak");
            } catch (ValidationException $e) {
                $this->assertSame(['settlement_type' => ['Jenis penyelesaian tidak valid.']], $e->errors());
            }
        }

        $fresh = $return->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->settlement_type);
    }

    public function test_validasi_penyelesaian(): void
    {
        $return = $this->returPending();
        $url = route('admin.retur-pembelian.settle', $return);

        $this->actingAs($this->admin)->put($url, [])
            ->assertSessionHasErrors(['settlement_type' => 'Pilih jenis penyelesaian.']);
        $this->actingAs($this->admin)->put($url, ['settlement_type' => 'replacement'])   // baru tersedia di Tahap 2b
            ->assertSessionHasErrors(['settlement_type' => 'Jenis penyelesaian tidak valid.']);
        $this->actingAs($this->admin)->put($url, ['settlement_type' => 'bebas'])
            ->assertSessionHasErrors(['settlement_type' => 'Jenis penyelesaian tidak valid.']);
        $this->actingAs($this->admin)->put($url, ['settlement_type' => 'refund', 'settlement_note' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['settlement_note' => 'Catatan maksimal 255 karakter.']);

        $this->assertSame('pending', $return->fresh()->status);

        $this->actingAs($this->admin)->put($url, ['settlement_type' => 'refund', 'settlement_note' => str_repeat('a', 255)])
            ->assertSessionHasNoErrors();   // batas 255 lolos
        $this->assertSame('settled', $return->fresh()->status);
    }

    // ------------------------------------------------------------- halaman admin

    public function test_daftar_retur_admin_ringkasan_tab_dan_pencarian(): void
    {
        [$poA, $itemA] = $this->poDiterima();
        [$poB, $itemB, $productB] = $this->poDiterima();
        $this->retur($poA, $this->line($itemA, 2))->assertOk();                                               // 288.000 pending
        $this->retur($poA, $this->line($itemA, 3, $this->unitId($itemA->product, 'sachet')))->assertOk();    // 3.000 pending
        $this->retur($poB, $this->line($itemB, 1))->assertOk();                                               // 144.000 -> diselesaikan
        $settled = PurchaseReturn::where('purchase_order_id', $poB->id)->firstOrFail();
        $this->actingAs($this->admin)->put(route('admin.retur-pembelian.settle', $settled), ['settlement_type' => 'credit']);
        [$r1, $r2] = PurchaseReturn::where('purchase_order_id', $poA->id)->orderBy('id')->get()->all();

        // Ringkasan: hanya yang pending (288.000 + 3.000 = 291.000, 2 retur), apa pun tab-nya.
        foreach (['menunggu', 'selesai', 'semua'] as $tab) {
            $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => $tab]))->assertOk()
                ->assertSee('Rp 291.000')->assertSee('2 retur');
        }

        $menunggu = $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index'))->assertOk();   // default = menunggu
        $menunggu->assertSee($r1->return_number)->assertSee($r2->return_number)->assertDontSee($settled->return_number);

        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'selesai']))->assertOk()
            ->assertSee($settled->return_number)->assertSee('Potong tagihan')
            ->assertDontSee($r1->return_number)->assertDontSee($r2->return_number);

        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua']))->assertOk()
            ->assertSee($r1->return_number)->assertSee($settled->return_number);

        // Pencarian: nomor PO & nomor retur.
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua', 'cari' => $poB->po_number]))->assertOk()
            ->assertSee($settled->return_number)->assertDontSee($r1->return_number);
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua', 'cari' => $r2->return_number]))->assertOk()
            ->assertSee($r2->return_number)->assertDontSee($settled->return_number);
        // Wildcard LIKE dicari apa adanya.
        foreach (['%', '_'] as $needle) {
            $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua', 'cari' => $needle]))->assertOk()
                ->assertSee('Tidak ada retur')->assertDontSee($r1->return_number);
        }
        // Tab tidak dikenal ditolak validasi.
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'aneh']))->assertSessionHasErrors('tab');
    }

    public function test_halaman_detail_retur_menampilkan_form_penyelesaian_hanya_saat_pending(): void
    {
        $return = $this->returPending();
        $show = route('admin.retur-pembelian.show', $return);

        $this->actingAs($this->admin)->get($show)->assertOk()
            ->assertSee($return->return_number)->assertSee('Kemasan rusak')->assertSee('Rp 288.000')
            ->assertSee('Selesaikan retur')->assertSee('Tandai Selesai')
            ->assertSee(route('admin.retur-pembelian.settle', $return), false);

        $this->actingAs($this->admin)->put(route('admin.retur-pembelian.settle', $return), ['settlement_type' => 'refund', 'settlement_note' => 'Lunas via transfer']);

        $this->actingAs($this->admin)->get($show)->assertOk()
            ->assertSee('Selesai')->assertSee('Refund dari supplier')->assertSee('Lunas via transfer')
            ->assertDontSee('Tandai Selesai');
    }

    // --------------------------------------------------------- integrasi halaman PO

    public function test_tombol_retur_di_halaman_po_hanya_saat_ada_barang_yang_bisa_diretur(): void
    {
        [$po, $item] = $this->poDiterima();
        $formUrl = route('gudang.pembelian.retur.form', $po);

        foreach ([[$this->gudang, 'gudang.pembelian.show'], [$this->admin, 'admin.pembelian.show']] as [$user, $route]) {
            $res = $this->actingAs($user)->get(route($route, $po))->assertOk();
            $res->assertSee('data-retur-open="' . $formUrl . '"', false)
                ->assertSee('Barang rusak atau salah kirim')
                ->assertSee('data-retur-title="Retur ke Supplier"', false)
                ->assertSee('id="modal-retur"', false)
                ->assertSee('Belum ada retur untuk PO ini.');
        }

        // Diretur habis: tombol hilang, riwayat tetap tampil.
        $this->retur($po, $this->line($item, 5))->assertOk();
        foreach ([[$this->gudang, 'gudang.pembelian.show'], [$this->admin, 'admin.pembelian.show']] as [$user, $route]) {
            $this->actingAs($user)->get(route($route, $po))->assertOk()
                ->assertDontSee('data-retur-open=', false)
                ->assertDontSee('Belum ada retur untuk PO ini.')
                ->assertSee('RTP-20261014-0001');
        }
    }

    public function test_kartu_retur_tidak_muncul_untuk_po_yang_belum_ada_barang_diterima(): void
    {
        [$po] = $this->poDiterima(5, 144000, 'ordered');

        // Catatan: teks "Retur ke Supplier" juga ada di menu sidebar Admin, jadi yang dicek adalah teks khas kartu.
        $this->actingAs($this->admin)->get(route('admin.pembelian.show', $po))->assertOk()
            ->assertDontSee('Barang rusak atau salah kirim')->assertDontSee('data-retur-open=', false);
        $this->actingAs($this->gudang)->get(route('gudang.pembelian.show', $po))->assertOk()
            ->assertDontSee('Barang rusak atau salah kirim')->assertDontSee('data-retur-open=', false);
    }

    public function test_form_modal_menampilkan_sisa_satuan_dan_pesan_bila_diretur_penuh(): void
    {
        [$po, $item, $product] = $this->poDiterima();

        $html = $this->actingAs($this->admin)->getJson(route('gudang.pembelian.retur.form', $po))->json('html');
        $this->assertStringContainsString($product->name, $html);
        // Form harus mengirim ke endpoint RETUR (bukan, mis., endpoint penerimaan barang di URL PO yang sama).
        $this->assertStringContainsString('action="' . route('gudang.pembelian.retur.store', $po) . '"', $html);
        $this->assertStringContainsString('data-remaining-base="720000"', $html);
        $this->assertStringContainsString('dus (= 144 sachet)', $html);
        $this->assertStringContainsString('renceng (= 12 sachet)', $html);
        $this->assertStringContainsString('data-name="sachet"', $html);
        $this->assertStringContainsString('data-conv="144000"', $html);
        $this->assertMatchesRegularExpression('/value="' . $item->product_unit_id . '"[^>]*selected|selected[^>]*value="' . $item->product_unit_id . '"/s', $html);

        $this->retur($po, $this->line($item, 5))->assertOk();
        $html = $this->actingAs($this->admin)->getJson(route('gudang.pembelian.retur.form', $po))->json('html');
        $this->assertStringContainsString('Semua barang yang diterima pada PO ini sudah diretur penuh', $html);
        $this->assertStringNotContainsString('data-retur-submit', $html);
    }

    public function test_kunci_idempotensi_form_unik_di_setiap_pembukaan(): void
    {
        [$po] = $this->poDiterima();
        $ambil = fn () => preg_match(
            '/name="idempotency_key" value="([0-9a-f-]{36})"/',
            $this->actingAs($this->admin)->getJson(route('gudang.pembelian.retur.form', $po))->json('html'),
            $m
        ) ? $m[1] : null;

        $a = $ambil();
        $b = $ambil();

        $this->assertNotNull($a);
        $this->assertNotSame($a, $b);
    }

    public function test_riwayat_stok_produk_menampilkan_label_retur_ke_supplier(): void
    {
        [$po, $item, $product] = $this->poDiterima();
        $this->retur($po, $this->line($item, 1))->assertOk();

        $this->actingAs($this->admin)->get(route('gudang.stok.show', $product))->assertOk()->assertSee('Retur ke Supplier');
    }

    public function test_semua_halaman_render_tanpa_lazy_loading_dengan_banyak_data(): void
    {
        // Model::preventLazyLoading() aktif di testing tapi HANYA melempar bila hasil query berisi >1 model,
        // jadi tiap daftar di sini sengaja berisi >=2.
        $p1 = $this->makeProduct(['stock' => 720]);
        $p2 = $this->makeProduct(['stock' => 720]);
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $p1, 'quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
            ['product' => $p2, 'quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
        ]);
        [$i1, $i2] = $po->items->all();

        $this->retur($po, $this->line($i1, 1) + $this->line($i2, 1))->assertOk();
        $this->retur($po, $this->line($i1, 1))->assertOk();
        $last = PurchaseReturn::latest('id')->first();

        $this->actingAs($this->gudang)->getJson(route('gudang.pembelian.retur.form', $po))->assertOk();
        $this->actingAs($this->admin)->getJson(route('gudang.pembelian.retur.form', $po))->assertOk();
        $this->actingAs($this->gudang)->get(route('gudang.pembelian.show', $po))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.pembelian.show', $po))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.index', ['tab' => 'semua', 'cari' => 'RTP']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.show', $last))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-pembelian.show', PurchaseReturn::first()))->assertOk();
    }
}