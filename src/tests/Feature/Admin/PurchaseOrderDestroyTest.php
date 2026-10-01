<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian PO) — korektivitas PurchaseOrderController::destroy():
 * hanya PO berstatus draft yang boleh dihapus; PO yang sudah masuk alur
 * (dipesan/diterima/dibatalkan) adalah jejak audit pembelian dan harus
 * tetap utuh — untuk koreksi tersedia "Batalkan" (cancel()).
 */
class PurchaseOrderDestroyTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Storage::fake('public');
    }

    private function hapus(PurchaseOrder $po, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->delete(route('admin.pembelian.destroy', $po));
    }

    /** Catat pembayaran lewat endpoint SUNGGUHAN (bukan fixture) — riwayat + file bukti tercipta seperti di produksi. */
    private function bayar(PurchaseOrder $po, string $status): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'po-proof-');
        file_put_contents($path, $png);

        $this->actingAs($this->admin)->put(route('admin.pembelian.payment-status', $po), [
            'payment_status' => $status,
            'proof' => new UploadedFile($path, 'bukti.png', null, null, true),
        ])->assertSessionHas('success');

        @unlink($path);
    }

    public function test_po_draft_terhapus_beserta_semua_itemnya(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [[], []]);
        $this->assertSame(2, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());

        $this->hapus($po)
            ->assertRedirect(route('admin.pembelian.index'))
            ->assertSessionHas('success');

        $this->assertNull(PurchaseOrder::find($po->id));
        $this->assertSame(0, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    public function test_menghapus_po_draft_tidak_menyentuh_supplier_produk_atau_po_lain(): void
    {
        $produk = $this->makeProduct();
        $target = $this->makePurchaseOrder(['status' => 'draft'], [['product' => $produk]]);
        $lain = $this->makePurchaseOrder(['status' => 'draft'], [['product' => $produk]]);

        $this->hapus($target)->assertSessionHas('success');

        // Master data tidak ikut terhapus oleh cascade.
        $this->assertNotNull(Supplier::find($target->supplier_id));
        $this->assertNotNull(Product::find($produk->id));

        // PO lain (bahkan yang memuat produk yang sama) tetap utuh.
        $this->assertNotNull(PurchaseOrder::find($lain->id));
        $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $lain->id)->count());
    }

    public function test_po_yang_sudah_masuk_alur_tidak_bisa_dihapus(): void
    {
        foreach (['ordered', 'partially_received', 'received', 'cancelled'] as $status) {
            $po = $this->makePurchaseOrder(['status' => $status], [[]]);

            $this->hapus($po)->assertRedirect()->assertSessionHas('error');

            $this->assertNotNull(PurchaseOrder::find($po->id), "PO berstatus {$status} tidak boleh terhapus");
            $this->assertSame($status, $po->fresh()->status);
            $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count(), "item PO {$status} harus tetap ada");
        }
    }

    public function test_penghapusan_yang_ditolak_tidak_mengubah_jejak_penerimaan(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'partially_received'], [['quantity_received' => 4]]);
        $item = $po->items->first();

        $this->hapus($po)->assertSessionHas('error');

        $this->assertEqualsWithDelta(4, (float) $item->fresh()->quantity_received, 0.0005);
    }

    public function test_kasir_dan_gudang_tidak_bisa_menghapus_po(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [[]]);

        foreach (['kasir', 'gudang'] as $role) {
            $this->hapus($po, User::factory()->{$role}()->create())->assertForbidden();
        }

        $this->assertNotNull(PurchaseOrder::find($po->id));
        $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    public function test_tamu_diarahkan_ke_login_dan_po_tetap_ada(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft']);

        $this->delete(route('admin.pembelian.destroy', $po))->assertRedirect(route('login'));

        $this->assertNotNull(PurchaseOrder::find($po->id));
    }

    public function test_po_yang_tidak_ada_menghasilkan_404_bukan_error_server(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.pembelian.destroy', PurchaseOrder::max('id') + 999))
            ->assertNotFound();
    }

    // === Jejak keuangan: PO yang sudah punya riwayat bayar tidak boleh terhapus ===

    public function test_po_draft_yang_sudah_dibayar_sebagian_atau_lunas_tidak_bisa_dihapus(): void
    {
        foreach (['partial', 'paid'] as $statusBayar) {
            $po = $this->makePurchaseOrder(['status' => 'draft'], [[]]);
            $this->bayar($po, $statusBayar);

            $this->hapus($po)->assertRedirect()->assertSessionHas('error');

            $this->assertNotNull(PurchaseOrder::find($po->id), "PO draft berstatus bayar {$statusBayar} tidak boleh terhapus");
            $this->assertSame($statusBayar, $po->fresh()->payment_status);
            $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
        }
    }

    public function test_penghapusan_po_berbayar_yang_ditolak_tidak_menghilangkan_riwayat_maupun_file_bukti(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [[]]);
        $this->bayar($po, 'partial');
        $this->bayar($po, 'paid'); // 2 baris riwayat, 2 file bukti

        $this->assertSame(2, PurchaseOrderPayment::where('purchase_order_id', $po->id)->count());
        $this->assertCount(2, Storage::disk('public')->allFiles());

        $this->hapus($po)->assertSessionHas('error');

        $this->assertSame(2, PurchaseOrderPayment::where('purchase_order_id', $po->id)->count());
        $this->assertCount(2, Storage::disk('public')->allFiles());
        foreach (PurchaseOrderPayment::where('purchase_order_id', $po->id)->get() as $riwayat) {
            Storage::disk('public')->assertExists($riwayat->proof_path); // tidak ada file yatim / riwayat tanpa file
        }
    }

    public function test_po_berbayar_tetap_bisa_dibatalkan_sebagai_jalan_keluar_yang_diarahkan_pesan_error(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [[]]);
        $this->bayar($po, 'paid');

        $this->hapus($po)->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'Batalkan PO'));

        // Jalan keluar yang ditawarkan pesan itu benar-benar berfungsi, dan jejak bayar tetap utuh.
        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))->assertSessionHas('success');

        $this->assertSame('cancelled', $po->fresh()->status);
        $this->assertSame(1, PurchaseOrderPayment::where('purchase_order_id', $po->id)->count());
    }

    /**
     * Pertahanan cadangan: kalau data tidak konsisten (ada baris riwayat bayar
     * tapi payment_status masih 'unpaid' — mis. hasil edit manual di database),
     * yang menentukan tetap keberadaan riwayatnya, bukan labelnya.
     */
    public function test_po_yang_punya_baris_riwayat_bayar_ditolak_walau_payment_status_masih_unpaid(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft', 'payment_status' => 'unpaid'], [[]]);
        PurchaseOrderPayment::create([
            'purchase_order_id' => $po->id,
            'from_status' => 'unpaid',
            'to_status' => 'partial',
            'proof_path' => 'purchase-payments/legacy.png',
            'uploaded_by' => $this->admin->id,
        ]);

        $this->hapus($po)->assertSessionHas('error');

        $this->assertNotNull(PurchaseOrder::find($po->id));
        $this->assertSame(1, PurchaseOrderPayment::where('purchase_order_id', $po->id)->count());
    }

    /**
     * Sisi sebaliknya dari test di atas: status bayar sudah 'partial'/'paid'
     * tetapi TIDAK ada baris riwayat — kasus nyata untuk PO lama yang dibayar
     * sebelum tabel purchase_order_payments ada (atau diubah manual di DB).
     * Statusnya tetap harus melindungi PO dari penghapusan.
     */
    public function test_po_berstatus_bayar_tanpa_baris_riwayat_tetap_ditolak(): void
    {
        foreach (['partial', 'paid'] as $statusBayar) {
            $po = $this->makePurchaseOrder(['status' => 'draft', 'payment_status' => $statusBayar], [[]]);
            $this->assertSame(0, PurchaseOrderPayment::where('purchase_order_id', $po->id)->count());

            $this->hapus($po)->assertSessionHas('error');

            $this->assertNotNull(PurchaseOrder::find($po->id), "status bayar {$statusBayar} tanpa riwayat tetap harus melindungi PO");
        }
    }

    public function test_po_draft_yang_belum_pernah_dibayar_tetap_bisa_dihapus(): void
    {
        // Kontrol positif: aturan baru tidak boleh memblokir kasus normal.
        $po = $this->makePurchaseOrder(['status' => 'draft', 'payment_status' => 'unpaid'], [[]]);

        $this->hapus($po)->assertSessionHas('success');

        $this->assertNull(PurchaseOrder::find($po->id));
    }

    // === Tampilan: tombol Hapus hanya muncul kalau server memang akan mengizinkan ===

    public function test_tombol_hapus_muncul_untuk_draft_belum_dibayar_dan_hilang_setelah_dibayar(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft'], [[]]);

        $this->actingAs($this->admin)->get(route('admin.pembelian.show', $po))
            ->assertOk()
            ->assertSee('data-modal-open="delete-po"', false)
            ->assertSee('Ya, Hapus');

        $this->bayar($po, 'partial');

        $this->actingAs($this->admin)->get(route('admin.pembelian.show', $po))
            ->assertOk()
            ->assertDontSee('data-modal-open="delete-po"', false)
            ->assertDontSee('Ya, Hapus')
            ->assertSee('Batalkan PO'); // jalan keluar tetap ditawarkan di UI
    }
}