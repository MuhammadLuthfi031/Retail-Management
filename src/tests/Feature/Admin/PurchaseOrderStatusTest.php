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

    /** @var list<string> file sementara yang dibuat fileAsli(), dihapus di tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hindariIdUserPertama();
        $this->admin = User::factory()->admin()->create();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** File SUNGGUHAN di disk (isi nyata, mime dideteksi dari isi) — beda dari UploadedFile::fake(). */
    private function fileAsli(string $namaClient, string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po-proof-');
        file_put_contents($path, $isi);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $namaClient, null, null, true);
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
     * PENTING (keamanan): rule bukti harus whitelist eksplisit
     * `mimes:jpeg,jpg,png,webp`.
     *
     * Catatan teknis (diverifikasi lewat mutation testing, Laravel 13.x):
     * rule `image` generik di versi ini SUDAH TIDAK meloloskan SVG (butuh
     * `image:allow_svg`), jadi test "SVG ditolak" saja TIDAK bisa
     * membedakan `mimes:` eksplisit dari `image`. Yang membedakan keduanya
     * adalah format gambar lain yang diloloskan `image` tapi bukan bagian
     * whitelist (gif, bmp, avif, heic) — itu yang diuji di sini. SVG dan
     * file bukan-gambar diuji terpisah di bawah sebagai regresi-guard.
     */
    public function test_bukti_pembayaran_format_gambar_di_luar_whitelist_ditolak(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        foreach ([['bukti.gif', 'image/gif'], ['bukti.bmp', 'image/bmp']] as [$nama, $mime]) {
            $this->bayar($po, 'partial', UploadedFile::fake()->create($nama, 10, $mime))
                ->assertSessionHasErrors('proof');
        }

        $this->assertSame('unpaid', $po->fresh()->payment_status);
        $this->assertSame(0, PurchaseOrderPayment::count());
    }

    public function test_bukti_pembayaran_svg_dan_file_bukan_gambar_ditolak(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.svg', 10, 'image/svg+xml'))
            ->assertSessionHasErrors('proof');
        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'))
            ->assertSessionHasErrors('proof');

        $this->assertSame('unpaid', $po->fresh()->payment_status);
    }

    /**
     * Serangan sungguhan: nama file bilang .png tapi ISI-nya SVG berskrip
     * (atau PHP). Rule `mimes` menilai dari ISI file (finfo), bukan dari
     * nama/klaim client — jadi test ini memakai file asli di disk, BUKAN
     * UploadedFile::fake() (yang mime-nya cuma ikut label yang kita berikan).
     */
    public function test_bukti_pembayaran_isi_berbahaya_yang_menyamar_sebagai_png_ditolak(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $svgBerskrip = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"><rect width="1" height="1"/></svg>';
        $kodePhp = '<?php system($_GET["c"]); ?>';

        foreach ([$svgBerskrip, $kodePhp] as $isi) {
            $this->bayar($po, 'partial', $this->fileAsli('bukti.png', $isi))
                ->assertSessionHasErrors('proof');
        }

        $this->assertSame('unpaid', $po->fresh()->payment_status);
        $this->assertSame(0, PurchaseOrderPayment::count());
        $this->assertSame([], Storage::disk('public')->allFiles()); // tidak ada file nyasar tersimpan
    }

    /** Kontrol positif: file PNG asli HARUS lolos (supaya test-test di atas tidak lulus karena semuanya ditolak). */
    public function test_bukti_pembayaran_png_asli_diterima(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->bayar($po, 'partial', $this->fileAsli('bukti.png', $png))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('partial', $po->fresh()->payment_status);
    }

    public function test_bukti_pembayaran_batas_ukuran_2048_kb(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        // 2049 KB (1 KB di atas batas) -> ditolak, tidak ada yang berubah.
        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.jpg', 2049, 'image/jpeg'))
            ->assertSessionHasErrors('proof');
        $this->assertSame('unpaid', $po->fresh()->payment_status);
        $this->assertSame(0, PurchaseOrderPayment::count());

        // Tepat 2048 KB -> masih boleh (batas inklusif).
        $this->bayar($po, 'partial', UploadedFile::fake()->create('bukti.jpg', 2048, 'image/jpeg'))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('partial', $po->fresh()->payment_status);
    }

    public function test_dua_transisi_status_bayar_menghasilkan_dua_baris_riwayat_terpisah(): void
    {
        $po = $this->makePurchaseOrder(['payment_status' => 'unpaid']);

        $this->bayar($po, 'partial')->assertSessionHas('success');
        $this->bayar($po, 'paid')->assertSessionHas('success');

        $riwayat = PurchaseOrderPayment::where('purchase_order_id', $po->id)->orderBy('id')->get();

        // 2 baris, BUKAN 1 baris yang ditimpa.
        $this->assertCount(2, $riwayat);
        $this->assertSame(['unpaid', 'partial'], [$riwayat[0]->from_status, $riwayat[0]->to_status]);
        $this->assertSame(['partial', 'paid'], [$riwayat[1]->from_status, $riwayat[1]->to_status]);

        // Bukti tiap transisi file sendiri-sendiri — bukti pertama tidak tertimpa/terhapus.
        $this->assertNotSame($riwayat[0]->proof_path, $riwayat[1]->proof_path);
        Storage::disk('public')->assertExists($riwayat[0]->proof_path);
        Storage::disk('public')->assertExists($riwayat[1]->proof_path);
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