<?php

namespace Tests\Feature\Admin;

use App\Models\PurchaseOrderPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian PO) — korektivitas transisi status: markOrdered() (draft
 * saja), updatePaymentStatus() (rank cuma boleh MAJU: unpaid->partial->paid,
 * tidak bisa sama/mundur), dan cancel() (hanya draft/ordered, dan hanya kalau
 * belum ada barang diterima sama sekali).
 */
class PurchaseOrderStatusTest extends TestCase
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

    // === markOrdered() ===

    public function test_draft_bisa_ditandai_ordered(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.mark-ordered', $po))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('ordered', $po->fresh()->status);
    }

    public function test_po_yang_bukan_draft_tidak_bisa_ditandai_ordered_lagi(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'ordered']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.mark-ordered', $po))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('ordered', $po->fresh()->status); // tidak berubah jadi apa pun yang aneh
    }

    // === updatePaymentStatus() ===

    private function bayar($po, string $status, ?UploadedFile $proof = null)
    {
        return $this->actingAs($this->admin)->put(route('admin.pembelian.payment-status', $po), [
            'payment_status' => $status,
            'proof' => $proof ?? UploadedFile::fake()->create('bukti.jpg', 10, 'image/jpeg'),
        ]);
    }

    public function test_status_bayar_bisa_maju_unpaid_ke_partial_ke_paid(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'partial')->assertSessionHas('success');
        $this->assertSame('partial', $po->fresh()->payment_status);

        $this->bayar($po, 'paid')->assertSessionHas('success');
        $this->assertSame('paid', $po->fresh()->payment_status);
    }

    public function test_status_bayar_boleh_lompat_langsung_unpaid_ke_paid(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'paid')->assertSessionHas('success');

        $this->assertSame('paid', $po->fresh()->payment_status);
    }

    public function test_status_bayar_tidak_bisa_mundur(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'paid']);

        $this->bayar($po, 'partial')->assertSessionHasErrors('payment_status');

        $this->assertSame('paid', $po->fresh()->payment_status); // tidak berubah
    }

    public function test_status_bayar_tidak_bisa_diulang_ke_status_yang_sama(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'partial']);

        $this->bayar($po, 'partial')->assertSessionHasErrors('payment_status');
    }

    public function test_status_bayar_mundur_dari_partial_ke_unpaid_ditolak(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'partial']);

        $this->bayar($po, 'unpaid')->assertSessionHasErrors('payment_status');

        $this->assertSame('partial', $po->fresh()->payment_status);
    }

    public function test_bukti_pembayaran_wajib_diisi(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.payment-status', $po), [
            'payment_status' => 'partial',
        ])->assertSessionHasErrors('proof');

        $this->assertSame('unpaid', $po->fresh()->payment_status); // gagal total, bukan tersimpan tanpa bukti
    }

    /**
     * PENTING (keamanan): rule harus `mimes:jpeg,jpg,png,webp` eksplisit,
     * BUKAN rule `image` bawaan Laravel — `image` generik ikut meloloskan
     * SVG yang berpotensi stored-XSS (lihat komentar di kode aslinya).
     */
    public function test_bukti_pembayaran_harus_gambar_bukan_svg_atau_file_lain(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.svg', 10, 'image/svg+xml'))
            ->assertSessionHasErrors('proof');

        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'))
            ->assertSessionHasErrors('proof');

        $this->assertSame('unpaid', $po->fresh()->payment_status);
    }

    public function test_perubahan_status_bayar_tercatat_di_riwayat_dengan_data_yang_benar(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'partial')->assertSessionHas('success');

        $log = PurchaseOrderPayment::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('unpaid', $log->from_status);
        $this->assertSame('partial', $log->to_status);
        $this->assertSame($this->admin->id, $log->uploaded_by);
        $this->assertNotEmpty($log->proof_path);
        Storage::disk('public')->assertExists($log->proof_path);
    }

    // === cancel() ===

    public function test_po_draft_bisa_dibatalkan(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'draft']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $po->fresh()->status);
    }

    public function test_po_ordered_bisa_dibatalkan(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'ordered']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $po->fresh()->status);
    }

    public function test_po_yang_sudah_diterima_sebagian_tidak_bisa_dibatalkan(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'partially_received'], [
            ['product' => $product, 'quantity_ordered' => 10, 'quantity_received' => 4],
        ]);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('error');

        $this->assertSame('partially_received', $po->fresh()->status); // tidak berubah
    }

    public function test_po_yang_sudah_diterima_lengkap_tidak_bisa_dibatalkan(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'received'], [
            ['product' => $product, 'quantity_ordered' => 10, 'quantity_received' => 10],
        ]);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('error');

        $this->assertSame('received', $po->fresh()->status);
    }

    /**
     * Guard "ada barang diterima" dicek TERPISAH dari guard status — kasus
     * pertahanan-berlapis: walau status PO entah bagaimana masih 'ordered'
     * (belum sempat disinkronkan ke partially_received), kalau SALAH SATU
     * item-nya sudah kadung ada yang diterima, tetap tidak boleh dibatalkan
     * (fisiknya sudah datang, tidak masuk akal membatalkan PO-nya).
     */
    public function test_po_ordered_dengan_item_yang_sudah_ada_diterima_tetap_tidak_bisa_dibatalkan(): void
    {
        $product = $this->makeProduct();
        $po = $this->makePurchaseOrder(['status' => 'ordered'], [
            ['product' => $product, 'quantity_ordered' => 10, 'quantity_received' => 3],
        ]);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('error');

        $this->assertSame('ordered', $po->fresh()->status);
    }

    public function test_po_yang_sudah_dibatalkan_tidak_bisa_dibatalkan_lagi(): void
    {
        $po = $this->makePurchaseOrder(['status' => 'cancelled']);

        $this->actingAs($this->admin)->put(route('admin.pembelian.cancel', $po))
            ->assertSessionHas('error');
    }
}