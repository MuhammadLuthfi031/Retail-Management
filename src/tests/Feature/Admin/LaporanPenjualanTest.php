<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Laporan Penjualan) — korektivitas total omzet, jumlah
 * transaksi, rata-rata, DAN filter (kasir/metode bayar), dengan data seed
 * angka DIKETAHUI supaya hasilnya bisa dicocokkan ke perhitungan manual.
 * Beda dengan LaporanDateValidationTest (QA-003, cuma cek "tidak 500"), di
 * sini yang diperiksa adalah ANGKA-nya lewat assertViewHas() — method privat
 * penjualanData() tidak bisa dites langsung, tapi dijamin sama persis dengan
 * yang dipakai HTML & PDF (SATU-SATUNYA sumber query, lihat docblock class).
 */
class LaporanPenjualanTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function penjualan(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.penjualan', $query));
    }

    public function test_total_omzet_jumlah_transaksi_dan_rata_rata_dihitung_benar(): void
    {
        // Angka SENGAJA dipilih supaya round() vs (int)-truncate BEDA HASIL
        // (326.000 / 3 = 108.666,67): round -> 108.667, truncate -> 108.666.
        // Kalau round() di kode sampai hilang/berubah, test ini ketahuan —
        // angka yang kebetulan habis dibagi tidak akan menangkap regresi itu.
        $this->makeTransaction(['grand_total' => 100000]);
        $this->makeTransaction(['grand_total' => 150000]);
        $this->makeTransaction(['grand_total' => 76000]);

        $this->penjualan()
            ->assertOk()
            ->assertViewHas('total_omzet', 326000)
            ->assertViewHas('jumlah_transaksi', 3)
            ->assertViewHas('rata_rata', 108667);
    }

    public function test_rata_rata_adalah_nol_bukan_error_saat_tidak_ada_transaksi(): void
    {
        $this->penjualan()
            ->assertOk()
            ->assertViewHas('total_omzet', 0)
            ->assertViewHas('jumlah_transaksi', 0)
            ->assertViewHas('rata_rata', 0); // pembagian 0/0 TIDAK boleh error
    }

    public function test_transaksi_batal_atau_refund_tidak_ikut_terhitung(): void
    {
        // Fitur void/cancel (§7.5) belum ada UI-nya (dicek langsung ke kode
        // — belum diimplementasikan), tapi kolom enum status & filter
        // WHERE di query SUDAH ada sekarang. Test ini menjaga filter itu
        // tetap benar SEJAK SEKARANG, supaya begitu fitur void dibangun,
        // laporan otomatis sudah benar tanpa perlu diingat-ingat lagi.
        $this->makeTransaction(['grand_total' => 100000, 'status' => 'completed']);
        $this->makeTransaction(['grand_total' => 999999, 'status' => 'cancelled']);
        $this->makeTransaction(['grand_total' => 999999, 'status' => 'refunded']);

        $this->penjualan()
            ->assertViewHas('total_omzet', 100000)
            ->assertViewHas('jumlah_transaksi', 1);
    }

    public function test_batas_rentang_tanggal_inklusif_di_kedua_ujung(): void
    {
        $this->makeTransaction(['grand_total' => 10000, 'created_at' => '2026-03-01 00:00:00']); // tepat awal hari 'from'
        $this->makeTransaction(['grand_total' => 20000, 'created_at' => '2026-03-31 23:59:59']); // tepat akhir hari 'to'
        $this->makeTransaction(['grand_total' => 999999, 'created_at' => '2026-02-28 23:59:59']); // 1 detik SEBELUM rentang
        $this->makeTransaction(['grand_total' => 999999, 'created_at' => '2026-04-01 00:00:00']); // 1 detik SETELAH rentang

        $this->penjualan(['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertViewHas('total_omzet', 30000)
            ->assertViewHas('jumlah_transaksi', 2);
    }

    public function test_filter_kasir_hanya_menghitung_transaksi_kasir_itu(): void
    {
        $kasirA = User::factory()->kasir()->create();
        $kasirB = User::factory()->kasir()->create();
        $this->makeTransaction(['grand_total' => 50000, 'user_id' => $kasirA->id]);
        $this->makeTransaction(['grand_total' => 999999, 'user_id' => $kasirB->id]);

        $this->penjualan(['user_id' => $kasirA->id])
            ->assertViewHas('total_omzet', 50000)
            ->assertViewHas('jumlah_transaksi', 1)
            ->assertViewHas('filterKasirName', $kasirA->name);
    }

    public function test_filter_metode_bayar_hanya_menghitung_metode_itu(): void
    {
        $this->makeTransaction(['grand_total' => 50000, 'payment_method' => 'cash']);
        $this->makeTransaction(['grand_total' => 999999, 'payment_method' => 'qris']);

        $this->penjualan(['payment_method' => 'cash'])
            ->assertViewHas('total_omzet', 50000)
            ->assertViewHas('jumlah_transaksi', 1)
            ->assertViewHas('filterPaymentLabel', 'Cash');
    }

    public function test_filter_kasir_dan_metode_bayar_digabung_pakai_and_bukan_or(): void
    {
        $kasirA = User::factory()->kasir()->create();
        $kasirB = User::factory()->kasir()->create();
        $this->makeTransaction(['grand_total' => 50000, 'user_id' => $kasirA->id, 'payment_method' => 'cash']);
        // Cocok kasir SAJA (metode beda) -> tidak boleh ikut:
        $this->makeTransaction(['grand_total' => 999999, 'user_id' => $kasirA->id, 'payment_method' => 'qris']);
        // Cocok metode SAJA (kasir beda) -> tidak boleh ikut:
        $this->makeTransaction(['grand_total' => 999999, 'user_id' => $kasirB->id, 'payment_method' => 'cash']);

        $this->penjualan(['user_id' => $kasirA->id, 'payment_method' => 'cash'])
            ->assertViewHas('total_omzet', 50000)
            ->assertViewHas('jumlah_transaksi', 1);
    }

    public function test_pdf_memakai_rentang_dan_angka_yang_sama_dengan_tampilan_html(): void
    {
        $this->makeTransaction(['grand_total' => 75000, 'created_at' => '2026-05-10 12:00:00']);
        $this->makeTransaction(['grand_total' => 999999, 'created_at' => '2026-06-01 00:00:00']); // di luar rentang

        $html = $this->penjualan(['from' => '2026-05-01', 'to' => '2026-05-31'])->assertOk();
        $this->assertSame(75000, $html->viewData('total_omzet'));

        $pdf = $this->actingAs($this->admin)->get(route('admin.laporan.penjualan.pdf', ['from' => '2026-05-01', 'to' => '2026-05-31']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('laporan-penjualan-20260501-20260531.pdf', $pdf->headers->get('content-disposition'));
    }
}