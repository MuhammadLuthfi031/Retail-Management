<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Laporan Laba/Rugi) — korektivitas total omzet, HPP, laba,
 * dan margin, berbasis `transaction_details.unit_cost` (snapshot average_cost
 * SAAT terjual — lihat PosController::processCheckout()), BUKAN average_cost
 * produk saat ini. Ini laporan finansial paling kritis di sistem: bug
 * `StockMovement` yang baru diperbaiki (urutan hitung average_cost vs update
 * stock) akan langsung tercermin di sini kalau sampai terulang.
 *
 * CATATAN: query laporan ini JOIN ke tabel `products` (untuk fallback
 * average_cost di baris legacy) — `product_id` di transaction_details juga
 * foreign key NOT NULL. Jadi SETIAP baris di sini harus menunjuk produk
 * SUNGGUHAN (makeProduct()), bukan angka id sembarangan.
 */
class LaporanLabaRugiTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function labaRugi(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.laba-rugi', $query));
    }

    public function test_total_omzet_hpp_laba_dan_margin_dihitung_benar(): void
    {
        $a = $this->makeProduct();
        $b = $this->makeProduct();

        // Baris 1: omzet 50.000, hpp = 2.000 x 10 x 1 = 20.000 -> laba 30.000
        // Baris 2: omzet 30.000, hpp = 1.500 x 5  x 2 = 15.000 -> laba 15.000
        // Total: omzet 80.000, hpp 35.000, laba 45.000, margin 45.000/80.000 = 56,25% -> 56,3 (1 desimal)
        $this->makeTransaction([], [
            ['product_id' => $a->id, 'unit_cost' => 2000, 'quantity' => 10, 'unit_conversion' => 1, 'subtotal' => 50000],
            ['product_id' => $b->id, 'unit_cost' => 1500, 'quantity' => 5, 'unit_conversion' => 2, 'subtotal' => 30000],
        ]);

        $response = $this->labaRugi()->assertOk();

        $this->assertSame(80000, $response->viewData('totalOmzet'));
        $this->assertSame(35000, $response->viewData('totalHpp'));
        $this->assertSame(45000, $response->viewData('totalLaba'));
        $this->assertSame(56.3, $response->viewData('margin'));
    }

    public function test_margin_nol_bukan_error_saat_omzet_nol(): void
    {
        // subtotal 0 valid secara skema (mis. diskon 100%) — lihat migration
        // transaction_details: unsignedBigInteger, jadi TIDAK BISA negatif,
        // tapi 0 tetap sah.
        $this->makeTransaction([], [
            ['unit_cost' => 0, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 0],
        ]);

        $response = $this->labaRugi()->assertOk();

        $this->assertSame(0, $response->viewData('totalOmzet'));
        $this->assertSame(0.0, $response->viewData('margin')); // pembagian 0/0 TIDAK boleh error
    }

    /**
     * Kasus arsitektur PALING PENTING di laporan ini: total_hpp HARUS
     * dibulatkan SEKALI di akhir (SUM dulu baru round), BUKAN dijumlah dari
     * hpp yang sudah dibulatkan per baris — dua cara itu bisa beda hasil.
     *
     * 3 baris, masing-masing hpp EXACT = 333,5 (unit_cost=667, qty=0,5,
     * konversi=1 -> 667 x 0,5 = 333,5). SUM exact = 1000,5 -> round SEKALI =
     * 1001 (round half away from zero). Kalau (keliru) dibulatkan PER BARIS
     * dulu: round(333,5)=334 x 3 = 1002. Beda 1 rupiah ini yang membuktikan
     * urutan operasinya benar.
     */
    public function test_total_hpp_dibulatkan_sekali_di_akhir_bukan_per_baris(): void
    {
        $this->makeTransaction([], [
            ['unit_cost' => 667, 'quantity' => 0.5, 'unit_conversion' => 1, 'subtotal' => 1000],
            ['unit_cost' => 667, 'quantity' => 0.5, 'unit_conversion' => 1, 'subtotal' => 1000],
            ['unit_cost' => 667, 'quantity' => 0.5, 'unit_conversion' => 1, 'subtotal' => 1000],
        ]);

        $this->assertSame(1001, $this->labaRugi()->viewData('totalHpp'));
    }

    public function test_hpp_memakai_snapshot_unit_cost_saat_terjual_bukan_average_cost_produk_sekarang(): void
    {
        $product = $this->makeProduct(['average_cost' => 5000]); // harga pokok SEKARANG (sudah berubah sejak terjual)

        $this->makeTransaction([], [
            ['product_id' => $product->id, 'unit_cost' => 2000, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 10000], // snapshot SAAT terjual
        ]);

        // HPP harus pakai 2.000 (snapshot), BUKAN 5.000 (average_cost sekarang).
        $this->assertSame(2000, $this->labaRugi()->viewData('totalHpp'));
    }

    public function test_baris_data_lama_tanpa_unit_cost_snapshot_fallback_ke_average_cost_sekarang_dan_flag_legacy_aktif(): void
    {
        $product = $this->makeProduct(['average_cost' => 3000]);

        $this->makeTransaction([], [
            // unit_cost TIDAK diisi (default null di helper) = simulasi baris SEBELUM kolom unit_cost ada.
            ['product_id' => $product->id, 'quantity' => 2, 'unit_conversion' => 1, 'subtotal' => 10000],
        ]);

        $response = $this->labaRugi();

        $this->assertSame(6000, $response->viewData('totalHpp')); // fallback: 3.000 x 2 x 1
        $this->assertTrue($response->viewData('adaDataLegacy'));
    }

    public function test_flag_legacy_tidak_aktif_kalau_semua_baris_punya_unit_cost(): void
    {
        $this->makeTransaction([], [
            ['unit_cost' => 1000, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 5000],
        ]);

        $this->assertFalse($this->labaRugi()->viewData('adaDataLegacy'));
    }

    public function test_rincian_per_produk_dikelompokkan_benar_dan_totalnya_cocok_dengan_grand_total(): void
    {
        $indomie = $this->makeProduct();
        $gula = $this->makeProduct();

        // 2 baris PRODUK YANG SAMA (mis. dijual 2x dalam periode, transaksi
        // berbeda) harus DIGABUNG jadi 1 baris di rincian, bukan 2 baris terpisah.
        $this->makeTransaction([], [
            ['product_id' => $indomie->id, 'unit_cost' => 2000, 'quantity' => 10, 'unit_conversion' => 1, 'subtotal' => 50000],
        ]);
        $this->makeTransaction([], [
            ['product_id' => $indomie->id, 'unit_cost' => 2000, 'quantity' => 5, 'unit_conversion' => 1, 'subtotal' => 25000],
            ['product_id' => $gula->id, 'unit_cost' => 8000, 'quantity' => 2, 'unit_conversion' => 1, 'subtotal' => 20000],
        ]);

        $perProduk = $this->labaRugi()->viewData('perProduk')->keyBy('product_id');

        $this->assertCount(2, $perProduk); // Indomie digabung, bukan 2 baris

        $rowIndomie = $perProduk->get($indomie->id);
        $this->assertEquals(15.0, (float) $rowIndomie->qty_terjual); // 10 + 5
        $this->assertSame(75000, (int) $rowIndomie->omzet); // 50.000 + 25.000
        $this->assertSame(30000, $rowIndomie->hpp); // (2.000x10) + (2.000x5)
        $this->assertSame(45000, $rowIndomie->laba);

        $rowGula = $perProduk->get($gula->id);
        $this->assertSame(20000, (int) $rowGula->omzet);
        $this->assertSame(16000, $rowGula->hpp);

        // Jumlah tiap baris rincian HARUS sama dengan angka total di atasnya
        // — kalau grouping-nya bocor (mis. produk lain ikut campur), ini ketahuan.
        $this->assertSame($this->labaRugi()->viewData('totalOmzet'), (int) $perProduk->sum('omzet'));
        $this->assertSame($this->labaRugi()->viewData('totalHpp'), (int) $perProduk->sum('hpp'));
    }

    public function test_hanya_transaksi_completed_dalam_rentang_yang_dihitung(): void
    {
        $this->makeTransaction(['status' => 'completed', 'created_at' => '2026-03-15 10:00:00'], [
            ['unit_cost' => 1000, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 5000],
        ]);
        $this->makeTransaction(['status' => 'cancelled', 'created_at' => '2026-03-15 10:00:00'], [
            ['unit_cost' => 1000, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 999999],
        ]);
        $this->makeTransaction(['status' => 'completed', 'created_at' => '2026-04-01 00:00:00'], [ // di luar rentang
            ['unit_cost' => 1000, 'quantity' => 1, 'unit_conversion' => 1, 'subtotal' => 999999],
        ]);

        $response = $this->labaRugi(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame(5000, $response->viewData('totalOmzet'));
    }

    public function test_pdf_memakai_angka_yang_sama_dengan_tampilan_html(): void
    {
        $this->makeTransaction([], [
            ['unit_cost' => 1000, 'quantity' => 3, 'unit_conversion' => 1, 'subtotal' => 9000],
        ]);

        $html = $this->labaRugi();
        $pdf = $this->actingAs($this->admin)->get(route('admin.laporan.laba-rugi.pdf'));

        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertSame($html->viewData('totalOmzet'), 9000);
        $this->assertSame($html->viewData('totalHpp'), 3000);
    }
}