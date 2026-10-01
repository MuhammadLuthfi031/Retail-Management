<?php

namespace Tests\Feature\Gudang;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Penerimaan Barang) — korektivitas
 * Gudang/PurchaseReceiptController::store(): akumulasi qty lintas beberapa
 * kali terima, transisi status PO, konversi satuan beli -> satuan dasar,
 * perhitungan ulang average_cost, dan atomisitas (gagal = tidak ada yang
 * berubah sama sekali).
 *
 * Produk bawaan makeProduct(): dus = 144 sachet (satuan beli), sachet =
 * satuan dasar. Semua angka ekspektasi di bawah dihitung TANGAN (lihat
 * komentar di tiap test), bukan disalin dari output kode.
 */
class PurchaseReceiptTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $gudang;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = User::factory()->gudang()->create();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // === Helper ===

    /** File SUNGGUHAN di disk (mime dideteksi dari ISI) — beda dari UploadedFile::fake(). */
    private function fileAsli(string $namaClient, string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po-receipt-');
        file_put_contents($path, $isi);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $namaClient, null, null, true);
    }

    private function fotoBukti(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return $this->fileAsli('bukti.png', $png);
    }

    /**
     * Kirim form konfirmasi penerimaan.
     *
     * @param  array<int, float|int|string>  $received  [po_item_id => qty diterima sekarang]
     */
    private function terima(PurchaseOrder $po, array $received, ?UploadedFile $proof = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->post(
            route('gudang.pembelian.store', $po),
            ['received' => $received, 'proof' => $proof ?? $this->fotoBukti()]
        );
    }

    /** Stok awal terkontrol lewat jalur sah (StockMovement::record), BUKAN assignment langsung. */
    private function stokAwal(Product $product, float $qtyBase, int $hargaPerBase): void
    {
        StockMovement::record(
            product: $product,
            type: 'in',
            quantity: $qtyBase,
            userId: $this->gudang->id,
            unitCost: $hargaPerBase,
            totalCost: (float) ($qtyBase * $hargaPerBase),
        );
    }

    /** PO berstatus 'ordered' dengan 1 item: pesan $qty dus @ $harga per dus. */
    private function poSatuItem(float $qty = 10, int $harga = 144000, ?Product $product = null, string $status = 'ordered'): PurchaseOrder
    {
        $product ??= $this->makeProduct();

        return $this->makePurchaseOrder(['status' => $status], [[
            'product' => $product,
            'quantity_ordered' => $qty,
            'unit_price' => $harga,
            'subtotal' => (int) round($qty * $harga),
        ]]);
    }

    /** Pastikan TIDAK ADA jejak apa pun dari upaya penerimaan (dipakai di semua test "ditolak"). */
    private function assertTidakAdaYangBerubah(PurchaseOrder $po, string $statusSemula, array $stokSemula = []): void
    {
        $this->assertSame($statusSemula, $po->fresh()->status);
        $this->assertSame(0.0, (float) PurchaseOrderItem::where('purchase_order_id', $po->id)->sum('quantity_received'));
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $this->assertSame(0, StockMovement::where('type', 'in')->where('reference', "PO:{$po->po_number}")->count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'tidak boleh ada file bukti nyasar tersimpan');

        foreach ($stokSemula as $productId => $stok) {
            $this->assertEqualsWithDelta($stok, $this->stockOf($productId), 0.0005);
        }
    }

    // === Terima sebagian & lengkap: status PO ===

    public function test_terima_sebagian_menjadikan_status_partially_received_bukan_received(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 4])
            ->assertRedirect(route('gudang.pembelian.show', $po))
            ->assertSessionHas('success');

        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertEqualsWithDelta(4, (float) $item->fresh()->quantity_received, 0.0005);
    }

    public function test_terima_sisanya_di_penerimaan_kedua_menjadikan_status_received(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 4]);
        $this->assertSame('partially_received', $po->fresh()->status);

        // Akumulasi lintas penerimaan: 4 + 6 = 10 = qty dipesan -> lengkap.
        $this->terima($po, [$item->id => 6])->assertSessionHas('success');

        $this->assertSame('received', $po->fresh()->status);
        $this->assertEqualsWithDelta(10, (float) $item->fresh()->quantity_received, 0.0005);
        $this->assertEqualsWithDelta(10 * 144, $this->stockOf($item->product_id), 0.0005);
        $this->assertSame(2, PurchaseOrderReceipt::where('purchase_order_id', $po->id)->count());
    }

    public function test_dua_item_satu_lengkap_satu_masih_sebagian_status_tetap_partially_received(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [
            ['quantity_ordered' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
            ['quantity_ordered' => 8, 'unit_price' => 144000, 'subtotal' => 1152000],
        ]);
        [$a, $b] = [$po->items[0], $po->items[1]];

        // A diterima LENGKAP (5/5), B baru sebagian (3/8) — dalam SATU kali submit.
        $this->terima($po, [$a->id => 5, $b->id => 3])->assertSessionHas('success');

        $this->assertTrue($a->fresh()->isFullyReceived());
        $this->assertFalse($b->fresh()->isFullyReceived());
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    // === Validasi qty ===

    public function test_terima_melebihi_sisa_ditolak_dan_tidak_ada_yang_berubah(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 11])->assertSessionHasErrors('received');

        $this->assertTidakAdaYangBerubah($po, 'ordered', [$item->product_id => 0]);
    }

    public function test_sisa_dihitung_dari_akumulasi_penerimaan_sebelumnya(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 6]); // sisa tinggal 4
        $stokSetelahPertama = $this->stockOf($item->product_id);

        // 5 > sisa 4 -> harus ditolak, padahal 5 < 10 (qty dipesan penuh).
        $this->terima($po, [$item->id => 5])->assertSessionHasErrors('received');

        $this->assertEqualsWithDelta(6, (float) $item->fresh()->quantity_received, 0.0005);
        $this->assertEqualsWithDelta($stokSetelahPertama, $this->stockOf($item->product_id), 0.0005);
        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_satu_item_melebihi_sisa_membatalkan_seluruh_submit_termasuk_item_lain_yang_valid(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [
            ['quantity_ordered' => 10, 'unit_price' => 144000, 'subtotal' => 1440000],
            ['quantity_ordered' => 2, 'unit_price' => 144000, 'subtotal' => 288000],
        ]);
        [$valid, $berlebih] = [$po->items[0], $po->items[1]];

        // Item pertama valid (3 <= 10) diproses LEBIH DULU di loop, item kedua
        // melebihi sisa (5 > 2) -> seluruh DB::transaction harus rollback,
        // termasuk stok item pertama yang sudah sempat dinaikkan di loop.
        $this->terima($po, [$valid->id => 3, $berlebih->id => 5])->assertSessionHasErrors('received');

        $this->assertTidakAdaYangBerubah($po, 'ordered', [
            $valid->product_id => 0,
            $berlebih->product_id => 0,
        ]);
    }

    public function test_qty_nol_pada_satu_item_dilewati_bukan_diproses(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [
            ['quantity_ordered' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
            ['quantity_ordered' => 5, 'unit_price' => 144000, 'subtotal' => 720000],
        ]);
        [$diterima, $nol] = [$po->items[0], $po->items[1]];

        $this->terima($po, [$diterima->id => 2, $nol->id => 0])->assertSessionHas('success');

        $this->assertEqualsWithDelta(2, (float) $diterima->fresh()->quantity_received, 0.0005);

        // Item ber-qty 0: tidak ada stok, tidak ada movement, tidak ada jejak penerima.
        $this->assertEqualsWithDelta(0, (float) $nol->fresh()->quantity_received, 0.0005);
        $this->assertNull($nol->fresh()->received_by);
        $this->assertNull($nol->fresh()->received_at);
        $this->assertEqualsWithDelta(0, $this->stockOf($nol->product_id), 0.0005);
        $this->assertSame(0, StockMovement::where('product_id', $nol->product_id)->count());
    }

    public function test_semua_qty_kosong_atau_nol_ditolak_dan_tidak_ada_yang_tersimpan(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        foreach ([[], [$item->id => 0], [$item->id => '']] as $received) {
            $this->terima($po, $received)->assertSessionHasErrors('received');
        }

        $this->assertTidakAdaYangBerubah($po, 'ordered', [$item->product_id => 0]);
    }

    // === Status PO yang boleh menerima ===

    public function test_po_yang_bukan_ordered_atau_partially_received_ditolak(): void
    {
        foreach (['draft', 'cancelled', 'received'] as $status) {
            $po = $this->poSatuItem(qty: 10, status: $status);
            $item = $po->items->first();

            $this->terima($po, [$item->id => 1])
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertTidakAdaYangBerubah($po, $status, [$item->product_id => 0]);
        }
    }

    // === Stok & konversi satuan ===

    public function test_stok_bertambah_dalam_satuan_dasar_sesuai_konversi_satuan_beli(): void
    {
        $po = $this->poSatuItem(qty: 10, harga: 144000);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 3]);

        // 3 dus x 144 sachet/dus = 432 sachet (BUKAN 3).
        $this->assertEqualsWithDelta(432, $this->stockOf($item->product_id), 0.0005);

        $movement = StockMovement::where('product_id', $item->product_id)->sole();
        $this->assertSame('in', $movement->type);
        $this->assertEqualsWithDelta(432, (float) $movement->quantity, 0.0005);
        $this->assertEqualsWithDelta(0, (float) $movement->stock_before, 0.0005);
        $this->assertEqualsWithDelta(432, (float) $movement->stock_after, 0.0005);
        $this->assertSame("PO:{$po->po_number}", $movement->reference);
        $this->assertSame($this->gudang->id, $movement->user_id);
        // Harga pokok per sachet untuk jejak audit: 144.000 / 144 = 1.000.
        $this->assertSame(1000, (int) $movement->unit_cost);
    }

    public function test_barang_curah_menerima_qty_pecahan(): void
    {
        $curah = $this->makeProduct(['tracking_mode' => 'weight'], [
            ['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 25000, 'is_base_unit' => true, 'is_purchase_unit' => true],
        ]);
        $po = $this->poSatuItem(qty: 10, harga: 20000, product: $curah);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 2.5])->assertSessionHas('success');

        $this->assertEqualsWithDelta(2.5, $this->stockOf($curah), 0.0005);
        $this->assertEqualsWithDelta(2.5, (float) $item->fresh()->quantity_received, 0.0005);
        // Stok awal 0 -> average_cost = total biaya / qty = (20.000 x 2,5) / 2,5 = 20.000.
        $this->assertSame(20000, (int) Product::find($curah->id)->average_cost);
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    // === average_cost ===

    public function test_penerimaan_pertama_pada_stok_nol_menetapkan_average_cost_dari_harga_beli(): void
    {
        $po = $this->poSatuItem(qty: 10, harga: 144000);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 4]);

        // (4 x 144.000) / (4 x 144) = 576.000 / 576 = 1.000 per sachet.
        $this->assertSame(1000, (int) Product::find($item->product_id)->average_cost);
    }

    /**
     * Penjaga regresi QA-007: recalculateAverageCost() WAJIB membaca stok
     * LAMA. Stok lama 100 sachet @ Rp 2.000, masuk 1 dus (144) @ Rp 144.000:
     *   benar   : (100 x 2.000 + 144.000) / (100 + 144) = 344.000 / 244 = 1.409,8 -> 1.410
     *   urutan tertukar (stok sudah naik 244 sebelum dihitung):
     *             (244 x 2.000 + 144.000) / (244 + 144) = 632.000 / 388 = 1.628,9 -> 1.629
     */
    public function test_average_cost_dihitung_dari_stok_lama_dan_harga_lama(): void
    {
        $product = $this->makeProduct();
        $this->stokAwal($product, qtyBase: 100, hargaPerBase: 2000);
        $this->assertSame(2000, (int) Product::find($product->id)->average_cost); // fixture benar

        $po = $this->poSatuItem(qty: 5, harga: 144000, product: $product);

        $this->terima($po, [$po->items->first()->id => 1]);

        $this->assertEqualsWithDelta(244, $this->stockOf($product), 0.0005);
        $this->assertSame(1410, (int) Product::find($product->id)->average_cost);
    }

    /**
     * Total biaya batch harus EXACT (unit_price x qty beli), bukan
     * unitCostPerBase (yang sudah dibulatkan) x qtyBase. 1.000 dus @ Rp 100.000
     * (1 dus = 144 sachet), stok lama 100 sachet @ Rp 1.000:
     *   exact   : (100 x 1.000 + 1.000 x 100.000) / (100 + 144.000)
     *           = 100.100.000 / 144.100 = 694,66 -> 695
     *   dibulatkan dulu: unitCostPerBase = round(100.000/144) = 694
     *           (100.000 + 694 x 144.000) / 144.100 = 100.036.000 / 144.100 = 694,2 -> 694
     */
    public function test_average_cost_memakai_total_biaya_exact_bukan_harga_per_satuan_dasar_yang_dibulatkan(): void
    {
        $product = $this->makeProduct();
        $this->stokAwal($product, qtyBase: 100, hargaPerBase: 1000);

        $po = $this->poSatuItem(qty: 1000, harga: 100000, product: $product);

        $this->terima($po, [$po->items->first()->id => 1000]);

        $this->assertEqualsWithDelta(144100, $this->stockOf($product), 0.0005);
        $this->assertSame(695, (int) Product::find($product->id)->average_cost);
        // Jejak audit per satuan dasar tetap angka bulat yang enak dibaca.
        $this->assertSame(694, (int) StockMovement::where('reference', "PO:{$po->po_number}")->sole()->unit_cost);
    }

    public function test_dua_item_produk_sama_dalam_satu_po_average_cost_berantai_benar(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [
            ['product' => $product, 'quantity_ordered' => 1, 'unit_price' => 144000, 'subtotal' => 144000],
            ['product' => $product, 'quantity_ordered' => 1, 'unit_price' => 288000, 'subtotal' => 288000],
        ]);

        $this->terima($po, [$po->items[0]->id => 1, $po->items[1]->id => 1]);

        // Baris 1: stok 0 -> avg 144.000/144 = 1.000, stok 144.
        // Baris 2: (144 x 1.000 + 288.000) / (144 + 144) = 432.000 / 288 = 1.500.
        $this->assertEqualsWithDelta(288, $this->stockOf($product), 0.0005);
        $this->assertSame(1500, (int) Product::find($product->id)->average_cost);
        $this->assertSame('received', $po->fresh()->status);
    }

    // === Bukti foto & riwayat ===

    public function test_bukti_foto_wajib_dan_tanpa_bukti_tidak_ada_yang_berubah(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->actingAs($this->gudang)
            ->post(route('gudang.pembelian.store', $po), ['received' => [$item->id => 2]])
            ->assertSessionHasErrors('proof');

        $this->assertTidakAdaYangBerubah($po, 'ordered', [$item->product_id => 0]);
    }

    public function test_bukti_format_di_luar_whitelist_atau_menyamar_ditolak(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $svgBerskrip = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"><rect width="1" height="1"/></svg>';
        $kodePhp = '<?php system($_GET["c"]); ?>';

        $percobaan = [
            UploadedFile::fake()->create('bukti.gif', 10, 'image/gif'),   // gambar sah, tapi bukan whitelist
            UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'),
            $this->fileAsli('bukti.png', $svgBerskrip),                   // nama .png, isi SVG berskrip
            $this->fileAsli('bukti.png', $kodePhp),                       // nama .png, isi PHP
        ];

        foreach ($percobaan as $file) {
            $this->terima($po, [$item->id => 2], $file)->assertSessionHasErrors('proof');
        }

        $this->assertTidakAdaYangBerubah($po, 'ordered', [$item->product_id => 0]);
    }

    public function test_setiap_penerimaan_tercatat_sebagai_receipt_terpisah_dengan_file_tersimpan(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 4]);
        $this->terima($po, [$item->id => 2]);

        $receipts = PurchaseOrderReceipt::where('purchase_order_id', $po->id)->get();
        $this->assertCount(2, $receipts);
        $this->assertNotSame($receipts[0]->proof_path, $receipts[1]->proof_path);

        foreach ($receipts as $receipt) {
            $this->assertSame($this->gudang->id, $receipt->received_by);
            Storage::disk('public')->assertExists($receipt->proof_path);
        }

        $this->assertSame($this->gudang->id, $item->fresh()->received_by);
        $this->assertNotNull($item->fresh()->received_at);
    }

    // === Akses ===

    public function test_kasir_tidak_bisa_menerima_barang(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 2], as: User::factory()->kasir()->create())->assertForbidden();

        $this->assertTidakAdaYangBerubah($po, 'ordered', [$item->product_id => 0]);
    }

    public function test_admin_boleh_menerima_barang_akses_penuh_sesuai_spesifikasi(): void
    {
        $po = $this->poSatuItem(qty: 10);
        $item = $po->items->first();

        $this->terima($po, [$item->id => 2], as: User::factory()->admin()->create())
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(288, $this->stockOf($item->product_id), 0.0005);
    }

    // === Tampilan: PO selesai tetap bisa ditelusuri (read-only) ===

    public function test_po_received_tetap_bisa_dibuka_dan_muncul_di_tab_selesai_bukan_di_tab_menunggu(): void
    {
        $po = $this->poSatuItem(qty: 10, status: 'received');

        $this->actingAs($this->gudang)->get(route('gudang.pembelian.show', $po))->assertOk();

        $this->actingAs($this->gudang)->get(route('gudang.pembelian.index', ['tab' => 'selesai']))
            ->assertOk()->assertSee($po->po_number);

        $this->actingAs($this->gudang)->get(route('gudang.pembelian.index'))
            ->assertOk()->assertDontSee($po->po_number);
    }
}