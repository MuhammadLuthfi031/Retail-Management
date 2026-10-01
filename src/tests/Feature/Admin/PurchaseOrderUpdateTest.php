<?php

namespace Tests\Feature\Admin;

use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian PO) — korektivitas PurchaseOrderController::update():
 * total_amount dihitung ULANG dari item (bukan dipercaya dari client), edit
 * hanya boleh saat draft, dan sinkronisasi item (update existing / tambah
 * baru / hapus yang tidak disertakan lagi) berjalan benar — termasuk batas
 * keamanannya (item milik PO lain tidak boleh ikut ter-edit).
 */
class PurchaseOrderUpdateTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function update($po, array $items, array $extra = [])
    {
        return $this->actingAs($this->admin)->put(route('admin.pembelian.update', $po), array_merge([
            'supplier_id' => $po->supplier_id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDay()->toDateString(),
            'items' => $items,
        ], $extra));
    }

    public function test_total_amount_dihitung_ulang_dari_item_bukan_dari_input_client(): void
    {
        $product = $this->makeProduct();
        $unit = $product->units->firstWhere('unit_name', 'renceng');
        $po = $this->makePurchaseOrder(['status' => 'draft'], [
            ['product' => $product, 'unit' => $unit, 'quantity_ordered' => 5, 'unit_price' => 10000, 'subtotal' => 50000],
        ]);
        $itemId = $po->items->first()->id;

        // Qty SENGAJA diubah (5 -> 8) supaya total_amount hasil akhir (80.000)
        // BEDA dari total_amount fixture semula (50.000) — kalau baris
        // recompute di update() sampai hilang lagi, angka lama (50.000) akan
        // tertinggal dan test ini ketahuan gagal, bukan kebetulan lolos
        // karena angkanya sudah sama dari awal.
        $response = $this->update($po, [
            // Client kirim subtotal 1 -> HARUS diabaikan, dihitung ulang server = 8 x 10.000 = 80.000
            ['id' => $itemId, 'product_id' => $product->id, 'product_unit_id' => $unit->id, 'quantity_ordered' => 8, 'unit_price' => 10000, 'subtotal' => 1],
        ], ['total_amount' => 999999999]); // header pun kirim total ngaco -> tetap diabaikan

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertSame(80000, $po->fresh()->total_amount);
    }

    public function test_hanya_po_berstatus_draft_yang_bisa_diedit(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [['product' => $product]]);
        $itemAsli = $po->items->first();

        $response = $this->update($po, [
            ['id' => $itemAsli->id, 'product_id' => $product->id, 'product_unit_id' => $itemAsli->product_unit_id, 'quantity_ordered' => 999, 'unit_price' => 1, 'subtotal' => 999],
        ]);

        $response->assertRedirect()->assertSessionHas('error');
        // Tidak ada satu pun kolom yang berubah — bukan cuma qty-nya saja.
        $this->assertEquals(10.0, (float) $itemAsli->fresh()->quantity_ordered);
        $this->assertSame(110000, $po->fresh()->total_amount);
    }

    public function test_item_existing_diupdate_item_baru_ditambah_item_yang_dihapus_dari_form_ikut_terhapus(): void
    {
        $produkA = $this->makeProduct();
        $produkB = $this->makeProduct();
        $produkC = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'draft'], [
            ['product' => $produkA, 'quantity_ordered' => 5, 'unit_price' => 1000, 'subtotal' => 5000],
            ['product' => $produkB, 'quantity_ordered' => 5, 'unit_price' => 1000, 'subtotal' => 5000],
        ]);
        [$itemA, $itemB] = $po->items;
        $unitDus = fn ($p) => $p->units->firstWhere('is_purchase_unit', true);

        $this->update($po, [
            // A: diupdate (qty berubah)
            ['id' => $itemA->id, 'product_id' => $produkA->id, 'product_unit_id' => $itemA->product_unit_id, 'quantity_ordered' => 20, 'unit_price' => 1000, 'subtotal' => 20000],
            // B: TIDAK disertakan lagi -> harus terhapus
            // C: baru (tanpa 'id') -> harus dibuat
            ['product_id' => $produkC->id, 'product_unit_id' => $unitDus($produkC)->id, 'quantity_ordered' => 3, 'unit_price' => 2000, 'subtotal' => 6000],
        ])->assertSessionHas('success');

        $po->refresh();
        $this->assertCount(2, $po->items); // B hilang, A tetap, C baru -> total 2
        $this->assertEquals(20.0, (float) $itemA->fresh()->quantity_ordered);
        $this->assertNull(PurchaseOrderItem::find($itemB->id)); // B benar-benar terhapus dari DB
        $this->assertTrue($po->items->contains('product_id', $produkC->id));
        $this->assertSame(26000, $po->total_amount); // 20.000 + 6.000
    }

    /**
     * Regresi bug yang ditemukan saat menulis test ini (dan sudah diperbaiki
     * di PurchaseOrderController::update() sebelum test ini ditulis):
     * sebelum diperbaiki, baris dengan `id` yang tidak ditemukan di scope PO
     * ini (item PO lain, atau item yang baru saja dihapus di sesi lain)
     * cuma DILEWATI DIAM-DIAM lewat null-safe operator ($poItem?->update) —
     * padahal subtotal baris itu SUDAH KEBURU ikut dijumlahkan ke
     * total_amount (dihitung dari array item mentah SEBELUM loop ini tahu
     * baris mana yang valid). Reproduksi asli: PO berakhir 0 item tapi
     * total_amount 998.999.001. Sekarang loop ini WAJIB menolak (422)
     * seluruh update kalau ini terjadi, bukan menyimpan separuh-konsisten.
     */
    public function test_id_item_yang_tidak_ditemukan_di_po_ini_menolak_seluruh_update(): void
    {
        $produkLain = $this->makeProduct();
        $poLain = $this->makePurchaseOrder(['status' => 'draft'], [
            ['product' => $produkLain, 'quantity_ordered' => 5, 'unit_price' => 1000, 'subtotal' => 5000],
        ]);
        $itemMilikPoLain = $poLain->items->first();

        $produkSaya = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'draft'], [
            ['product' => $produkSaya, 'quantity_ordered' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
        ]);
        $itemSaya = $po->items->first();

        // Form PO SAYA di-tamper: mengirim `id` milik item PO LAIN (efeknya
        // sama dengan form basi yang mengacu item yang sudah dihapus).
        $this->update($po, [
            ['id' => $itemMilikPoLain->id, 'product_id' => $produkLain->id, 'product_unit_id' => $itemMilikPoLain->product_unit_id, 'quantity_ordered' => 999, 'unit_price' => 999999, 'subtotal' => 999999],
        ])->assertSessionHasErrors('items');

        // PO lain sama sekali tidak tersentuh — proteksi scope-nya sendiri sudah benar.
        $this->assertEquals(5.0, (float) $itemMilikPoLain->fresh()->quantity_ordered);
        $this->assertSame(5000, $poLain->fresh()->total_amount);

        // PO SAYA JUGA tidak berubah SAMA SEKALI (DB::transaction rollback
        // total) — bukan cuma "item lama hilang, total salah" seperti
        // sebelum diperbaiki.
        $this->assertNotNull(PurchaseOrderItem::find($itemSaya->id));
        $this->assertSame(1000, $po->fresh()->total_amount);
        $this->assertSame(1, $po->fresh()->items()->count());
    }

    public function test_supplier_yang_tidak_ada_tidak_bisa_dipakai_di_header(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [['product' => $this->makeProduct()]]);
        $item = $po->items->first();

        $this->update($po, [
            ['id' => $item->id, 'product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id, 'quantity_ordered' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
        ], ['supplier_id' => Supplier::max('id') + 999])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_update_tanpa_item_valid_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'draft', 'note' => 'catatan asli'], [['product' => $product]]);
        $item = $po->items->first();

        // Tiga bentuk "tidak ada item valid": array kosong, baris qty 0, baris tanpa produk.
        $kasus = [
            [],
            [['product_id' => $product->id, 'product_unit_id' => $item->product_unit_id, 'quantity_ordered' => 0, 'unit_price' => 1000]],
            [['product_id' => '', 'product_unit_id' => '', 'quantity_ordered' => 5, 'unit_price' => 1000]],
        ];

        foreach ($kasus as $items) {
            $this->update($po, $items, ['note' => 'catatan DIUBAH'])
                ->assertSessionHasErrors('items');
        }

        // Header (note) pun tidak ikut berubah, dan item lama tetap utuh —
        // PO tidak pernah berakhir kosong lewat jalur edit.
        $this->assertSame('catatan asli', $po->fresh()->note);
        $this->assertSame(110000, $po->fresh()->total_amount);
        $this->assertNotNull(PurchaseOrderItem::find($item->id));
        $this->assertSame(1, $po->fresh()->items()->count());
    }
}