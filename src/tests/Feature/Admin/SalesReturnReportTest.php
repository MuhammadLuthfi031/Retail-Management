<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\SalesReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Tahap 1b: Laporan (Penjualan, Laba/Rugi, Stok) & Dashboard memakai angka BERSIH — penjualan
 * (menurut tanggal jual) dikurangi retur pelanggan (menurut tanggal retur).
 *
 * Retur dibuat lewat SalesReturnService yang ASLI (bukan data buatan), jadi yang diuji adalah
 * alur nyata. Semua angka dihitung tangan; skenario standar:
 *   produk "Kopi": 10 pcs @ Rp 5.000 = Rp 50.000, biaya (unit_cost) Rp 3.000 -> HPP Rp 30.000.
 * "Sekarang" = 2026-10-14 12:00 (default rentang laporan = 1 Okt s/d 14 Okt).
 */
class SalesReturnReportTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private const NOW = '2026-10-14 12:00:00';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->hindariIdUserPertama();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Product::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** Satu transaksi dengan satu baris. @return array{0: Transaction, 1: TransactionDetail, 2: Product} */
    private function sale(
        int|float $qty = 10,
        int $price = 5000,
        ?int $cost = 3000,
        array $trx = [],
        array $detail = [],
        ?Product $product = null,
        int $discount = 0
    ): array {
        $product ??= $this->makeProduct(['stock' => 1000, 'average_cost' => 1000, 'name' => 'Kopi ' . Str::random(4)]);
        $subtotal = (int) round($qty * $price) - $discount;

        $transaction = $this->makeTransaction(
            array_merge(['grand_total' => $subtotal, 'total_amount' => $subtotal + $discount, 'discount_amount' => $discount, 'created_at' => self::NOW], $trx),
            [array_merge([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_name' => 'pcs',
                'unit_conversion' => 1,
                'price' => $price,
                'unit_cost' => $cost,
                'discount_amount' => $discount,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ], $detail)]
        );

        return [$transaction, $transaction->details()->firstOrFail(), $product];
    }

    /** Retur lewat service asli pada waktu $at (default: sekarang). */
    private function retur(
        Transaction $trx,
        TransactionDetail $detail,
        int|float $qty,
        string $condition = 'resellable',
        ?string $at = null,
        string $refundMethod = 'cash'
    ): SalesReturn {
        Carbon::setTestNow(Carbon::parse($at ?? self::NOW));

        try {
            return app(SalesReturnService::class)->process(
                $trx->fresh(),
                [$detail->id => ['qty' => (string) $qty, 'condition' => $condition]],
                $refundMethod,
                'uji',
                (string) Str::uuid(),
                $this->admin->id
            );
        } finally {
            Carbon::setTestNow(Carbon::parse(self::NOW));
        }
    }

    private function penjualan(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.penjualan', $query));
    }

    private function labaRugi(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.laba-rugi', $query));
    }

    private function stok(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.stok', $query));
    }

    private function dashboard(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard', $query));
    }

    /**
     * Nilai di dalam kartu ringkasan dengan label tertentu (HTML: <div>label</div><div>nilai</div>;
     * PDF: <span class="label">label</span><span class="value">nilai</span>). Dipakai supaya asersi
     * memeriksa angka DI KARTU YANG TEPAT — bukan sekadar "angka itu muncul di suatu tempat di halaman".
     */
    private function nilaiKartu(string $html, string $label): string
    {
        $pattern = '/' . preg_quote($label, '/') . '<\/(?:div|span)>\s*<(?:div|span)[^>]*>(.*?)<\/(?:div|span)>/s';
        $this->assertSame(1, preg_match($pattern, $html, $m), "Kartu '{$label}' tidak ditemukan");

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    private function rupiah(int $n): string
    {
        return ($n < 0 ? '-' : '') . number_format(abs($n), 0, ',', '.');
    }

    // ======================================================= LAPORAN PENJUALAN

    public function test_penjualan_bersih_dikurangi_retur_dan_rata_rata_memakai_kotor(): void
    {
        [$a, $da] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-14 09:00:00']);   // 50.000
        [$b, $db] = $this->sale(4, 5000, 3000, ['created_at' => '2026-10-13 09:00:00']);    // 20.000
        $this->retur($a, $da, 2);                       // 10.000 (layak jual)
        $this->retur($b, $db, 1, 'damaged');            //  5.000 (rusak): tetap mengurangi omzet

        $this->penjualan()->assertOk()
            ->assertViewHas('penjualan_kotor', 70000)
            ->assertViewHas('total_retur', 15000)
            ->assertViewHas('jumlah_retur', 2)
            ->assertViewHas('total_omzet', 55000)       // BERSIH
            ->assertViewHas('jumlah_transaksi', 2)
            ->assertViewHas('rata_rata', 35000);        // 70.000 / 2 (kotor), bukan 55.000 / 2
    }

    public function test_tanpa_retur_semua_angka_retur_nol_dan_omzet_sama_dengan_kotor(): void
    {
        $this->sale(10, 5000);

        $this->penjualan()->assertOk()
            ->assertViewHas('penjualan_kotor', 50000)
            ->assertViewHas('total_retur', 0)
            ->assertViewHas('jumlah_retur', 0)
            ->assertViewHas('total_omzet', 50000);
    }

    public function test_retur_dihitung_menurut_tanggal_retur_bukan_tanggal_jual(): void
    {
        // Dijual 5 Okt, diretur 14 Okt.
        [$trx, $d] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-05 10:00:00']);
        $this->retur($trx, $d, 2, 'resellable', '2026-10-14 10:00:00');

        // Rentang yang memuat RETUR tapi tidak memuat PENJUALAN: omzet bersih NEGATIF (jujur).
        $this->penjualan(['from' => '2026-10-10', 'to' => '2026-10-14'])->assertOk()
            ->assertViewHas('penjualan_kotor', 0)
            ->assertViewHas('total_retur', 10000)
            ->assertViewHas('total_omzet', -10000)
            ->assertViewHas('jumlah_transaksi', 0)
            ->assertViewHas('rata_rata', 0);

        // Rentang yang memuat PENJUALAN tapi belum ada retur: bersih = kotor.
        $this->penjualan(['from' => '2026-10-01', 'to' => '2026-10-09'])->assertOk()
            ->assertViewHas('penjualan_kotor', 50000)
            ->assertViewHas('total_retur', 0)
            ->assertViewHas('total_omzet', 50000);
    }

    public function test_batas_rentang_retur_inklusif_di_awal_dan_akhir_hari(): void
    {
        [$trx, $d] = $this->sale(10, 1000, 500, ['created_at' => '2026-10-01 08:00:00']);
        $this->retur($trx, $d, 1, 'resellable', '2026-10-09 23:59:59');   // di luar (sehari sebelum from)
        $this->retur($trx, $d, 1, 'resellable', '2026-10-10 00:00:00');   // dalam (awal from)
        $this->retur($trx, $d, 1, 'resellable', '2026-10-14 23:59:59');   // dalam (akhir to)
        $this->retur($trx, $d, 1, 'resellable', '2026-10-15 00:00:00');   // di luar (sehari setelah to)

        $this->penjualan(['from' => '2026-10-10', 'to' => '2026-10-14'])->assertOk()
            ->assertViewHas('total_retur', 2000)
            ->assertViewHas('jumlah_retur', 2);
    }

    public function test_filter_kasir_mengikuti_penjualan_asal_bukan_pemroses_retur(): void
    {
        $kasirA = User::factory()->kasir()->create();
        $kasirB = User::factory()->kasir()->create();
        [$a, $da] = $this->sale(10, 5000, 3000, ['user_id' => $kasirA->id]);   // 50.000
        [$b, $db] = $this->sale(4, 5000, 3000, ['user_id' => $kasirB->id]);    // 20.000
        $this->retur($a, $da, 2);   // 10.000 — diproses ADMIN, tapi atas penjualan kasir A
        $this->retur($b, $db, 1);   //  5.000

        $this->penjualan(['user_id' => $kasirA->id])->assertOk()
            ->assertViewHas('penjualan_kotor', 50000)->assertViewHas('total_retur', 10000)
            ->assertViewHas('jumlah_retur', 1)->assertViewHas('total_omzet', 40000);

        $this->penjualan(['user_id' => $kasirB->id])->assertOk()
            ->assertViewHas('penjualan_kotor', 20000)->assertViewHas('total_retur', 5000)
            ->assertViewHas('jumlah_retur', 1)->assertViewHas('total_omzet', 15000);

        // Pemroses retur (admin) tidak punya penjualan: tidak ada retur yang "milik"-nya.
        $this->penjualan(['user_id' => $this->admin->id])->assertOk()
            ->assertViewHas('total_retur', 0)->assertViewHas('total_omzet', 0);
    }

    public function test_filter_metode_bayar_mengikuti_penjualan_asal_bukan_metode_pengembalian(): void
    {
        [$cash, $dc] = $this->sale(10, 5000, 3000, ['payment_method' => 'cash']);   // 50.000
        [$qris, $dq] = $this->sale(4, 5000, 3000, ['payment_method' => 'qris']);    // 20.000
        $this->retur($cash, $dc, 2, 'resellable', null, 'qris');   // dijual cash, uang kembali via QRIS
        $this->retur($qris, $dq, 1, 'resellable', null, 'cash');   // dijual QRIS, uang kembali tunai

        $this->penjualan(['payment_method' => 'cash'])->assertOk()
            ->assertViewHas('total_retur', 10000)->assertViewHas('total_omzet', 40000);
        $this->penjualan(['payment_method' => 'qris'])->assertOk()
            ->assertViewHas('total_retur', 5000)->assertViewHas('total_omzet', 15000);
        $this->penjualan(['payment_method' => 'debit'])->assertOk()
            ->assertViewHas('total_retur', 0)->assertViewHas('total_omzet', 0);
    }

    public function test_retur_atas_transaksi_yang_tidak_selesai_diabaikan(): void
    {
        [$trx, $d] = $this->sale(10, 5000);
        $this->retur($trx, $d, 2);
        $trx->forceFill(['status' => 'cancelled'])->save();

        // Penjualannya tidak dihitung, jadi retur atasnya pun tidak boleh mengurangi (tidak dobel).
        $this->penjualan()->assertOk()
            ->assertViewHas('penjualan_kotor', 0)->assertViewHas('total_retur', 0)
            ->assertViewHas('jumlah_retur', 0)->assertViewHas('total_omzet', 0);
    }

    public function test_beberapa_retur_atas_satu_transaksi_dan_diskon_baris(): void
    {
        // 3 pcs @ 5.000 = 15.000, diskon baris 1.000 -> subtotal 14.000. Retur 1 pcs = round(14.000/3) = 4.667,
        // retur 2 pcs terakhir mengambil sisa 9.333. Total retur = subtotal 14.000 tepat.
        [$trx, $d] = $this->sale(3, 5000, 3000, [], [], null, 1000);
        $this->retur($trx, $d, 1);
        $this->retur($trx, $d, 2);

        $this->penjualan()->assertOk()
            ->assertViewHas('penjualan_kotor', 14000)
            ->assertViewHas('total_retur', 14000)
            ->assertViewHas('jumlah_retur', 2)
            ->assertViewHas('total_omzet', 0);
    }

    public function test_halaman_penjualan_menampilkan_kartu_retur_dan_omzet_bersih(): void
    {
        [$trx, $d] = $this->sale(10, 5000);
        $this->retur($trx, $d, 2);

        $res = $this->penjualan()->assertOk();
        $html = $res->getContent();

        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Penjualan Kotor'));
        $this->assertSame('− Rp 10.000', $this->nilaiKartu($html, 'Retur Pelanggan'));
        $this->assertSame('Rp 40.000', $this->nilaiKartu($html, 'Total Omzet (Bersih)'));
        $this->assertSame('1', $this->nilaiKartu($html, 'Jumlah Transaksi'));
        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Rata-rata / Transaksi'));   // kotor / 1

        $res->assertSee('1 retur')
            ->assertSee('>lihat</a>', false)   // tautan di kartu (URL-nya sendiri juga ada di sidebar, jadi tidak cukup)
            ->assertSee('tanggal retur');
    }

    public function test_halaman_penjualan_tanpa_retur_tidak_menampilkan_tanda_minus_atau_tautan(): void
    {
        $this->sale(10, 5000);

        $this->penjualan()->assertOk()
            ->assertSee('Retur Pelanggan')->assertSee('0 retur')
            ->assertDontSee('− Rp')
            // Catatan: URL daftar retur juga ada di menu sidebar, jadi yang dicek adalah tautan "lihat" milik kartu.
            ->assertDontSee('>lihat</a>', false);
    }

    public function test_pdf_penjualan_memuat_angka_bersih_yang_sama_dengan_layar(): void
    {
        [$trx, $d] = $this->sale(10, 5000);
        $this->retur($trx, $d, 2);

        $this->actingAs($this->admin)->get(route('admin.laporan.penjualan.pdf'))->assertOk();

        $data = $this->penjualan()->original->getData();
        $html = view('admin.laporan.pdf.penjualan', $data + ['dibatasi' => false])->render();

        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Penjualan Kotor'));
        $this->assertSame('- Rp 10.000', $this->nilaiKartu($html, 'Retur (1)'));
        $this->assertSame('Rp 40.000', $this->nilaiKartu($html, 'Total Omzet (Bersih)'));
        $this->assertSame('1', $this->nilaiKartu($html, 'Jumlah Transaksi'));
        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Rata-rata / Transaksi'));
    }

    // ========================================================= LAPORAN LABA/RUGI

    public function test_laba_rugi_hpp_hanya_dikurangi_untuk_barang_layak_jual(): void
    {
        [$trx, $d] = $this->sale(10, 5000, 3000);   // omzet 50.000, HPP 30.000
        $this->retur($trx, $d, 2, 'resellable');    // refund 10.000; biaya kembali 2 x 3.000 = 6.000
        $this->retur($trx, $d, 1, 'damaged');       // refund  5.000; HPP TIDAK berkurang

        $r = $this->labaRugi()->assertOk();

        $this->assertSame(50000, $r->viewData('penjualanKotor'));
        $this->assertSame(15000, $r->viewData('totalRetur'));
        $this->assertSame(2, $r->viewData('jumlahRetur'));
        $this->assertSame(35000, $r->viewData('totalOmzet'));    // 50.000 - 15.000
        $this->assertSame(6000, $r->viewData('hppRetur'));
        $this->assertSame(24000, $r->viewData('totalHpp'));      // 30.000 - 6.000
        $this->assertSame(11000, $r->viewData('totalLaba'));     // 35.000 - 24.000
        $this->assertSame(31.4, $r->viewData('margin'));         // 11.000 / 35.000

        $row = $r->viewData('perProduk')->first();
        $this->assertEquals(7, $row->qty_terjual);               // 10 - 2 - 1 (rusak pun mengurangi qty terjual)
        $this->assertSame(35000, $row->omzet);
        $this->assertSame(24000, $row->hpp);
        $this->assertSame(11000, $row->laba);
        $this->assertSame(31.4, $row->margin);
    }

    public function test_barang_rusak_menjadi_kerugian_di_laba_sedangkan_layak_jual_tidak(): void
    {
        [$a, $da] = $this->sale(10, 5000, 3000);
        $this->retur($a, $da, 2, 'damaged');

        $rusak = $this->labaRugi()->assertOk();
        $this->assertSame(40000, $rusak->viewData('totalOmzet'));
        $this->assertSame(30000, $rusak->viewData('totalHpp'));   // tidak berkurang
        $this->assertSame(10000, $rusak->viewData('totalLaba'));  // 40.000 - 30.000: kerugian 6.000 terlihat
        $this->assertSame(0, $rusak->viewData('hppRetur'));

        SalesReturn::query()->delete();   // ulang dengan barang layak jual
        \App\Models\SalesReturnItem::query()->delete();
        $this->retur($a, $da, 2, 'resellable');

        $layak = $this->labaRugi()->assertOk();
        $this->assertSame(40000, $layak->viewData('totalOmzet'));
        $this->assertSame(24000, $layak->viewData('totalHpp'));   // 30.000 - 6.000
        $this->assertSame(16000, $layak->viewData('totalLaba'));  // margin tetap 40%
        $this->assertSame(6000, $layak->viewData('hppRetur'));
    }

    public function test_laba_rugi_konversi_satuan_dus_dihitung_dalam_satuan_dasar(): void
    {
        // 2 dus (1 dus = 12 pcs) @ Rp 120.000 = 240.000; biaya Rp 8.000 per pcs -> HPP 8.000 x 2 x 12 = 192.000.
        [$trx, $d] = $this->sale(2, 120000, 8000, [], ['unit_name' => 'dus', 'unit_conversion' => 12]);
        $this->retur($trx, $d, 1);   // refund 120.000; biaya kembali 8.000 x 1 x 12 = 96.000

        $r = $this->labaRugi()->assertOk();
        $this->assertSame(120000, $r->viewData('totalOmzet'));
        $this->assertSame(96000, $r->viewData('hppRetur'));
        $this->assertSame(96000, $r->viewData('totalHpp'));
        $this->assertSame(24000, $r->viewData('totalLaba'));

        $row = $r->viewData('perProduk')->first();
        $this->assertEquals(12, $row->qty_terjual);   // (2 - 1) dus x 12 = 12 pcs
    }

    public function test_produk_yang_hanya_diretur_di_periode_ini_tampil_negatif(): void
    {
        [$trx, $d] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-05 10:00:00']);
        $this->retur($trx, $d, 2, 'resellable', '2026-10-14 10:00:00');

        $r = $this->labaRugi(['from' => '2026-10-10', 'to' => '2026-10-14'])->assertOk();

        $this->assertSame(0, $r->viewData('penjualanKotor'));
        $this->assertSame(-10000, $r->viewData('totalOmzet'));
        $this->assertSame(-6000, $r->viewData('totalHpp'));
        $this->assertSame(-4000, $r->viewData('totalLaba'));
        $this->assertSame(0.0, $r->viewData('margin'));   // omzet bersih <= 0 -> 0, bukan pembagian dengan nol/negatif

        $this->assertCount(1, $r->viewData('perProduk'));
        $row = $r->viewData('perProduk')->first();
        $this->assertEquals(-2, $row->qty_terjual);
        $this->assertSame(-10000, $row->omzet);
        $this->assertSame(-6000, $row->hpp);
        $this->assertSame(-4000, $row->laba);
        $this->assertSame(0.0, $row->margin);
    }

    public function test_jumlah_baris_per_produk_selalu_sama_dengan_total_dan_terurut_omzet_bersih(): void
    {
        // Dibuat dari omzet bersih TERKECIL ke terbesar (urutan id produk): tanpa pengurutan eksplisit,
        // hasilnya akan menaik, bukan menurun.
        [$c, $dc] = $this->sale(5, 3000, 1000);    // Teh:   15.000 tanpa retur
        [$b, $db] = $this->sale(2, 20000, 12000);  // Gula:  40.000 -> retur 20.000 rusak (1 pcs)     => 20.000
        [$a, $da] = $this->sale(10, 5000, 3000);   // Kopi:  50.000 -> retur 20.000 layak jual (4 pcs) => 30.000
        $this->retur($a, $da, 4, 'resellable');
        $this->retur($b, $db, 1, 'damaged');

        $r = $this->labaRugi()->assertOk();
        $rows = $r->viewData('perProduk');

        $this->assertSame(65000, $r->viewData('totalOmzet'));   // 105.000 - 40.000
        $this->assertSame((int) $rows->sum('omzet'), $r->viewData('totalOmzet'));
        $this->assertSame((int) $rows->sum('hpp'), $r->viewData('totalHpp'));
        $this->assertSame((int) $rows->sum('laba'), $r->viewData('totalLaba'));
        $this->assertSame([30000, 20000, 15000], $rows->pluck('omzet')->all());   // urut omzet BERSIH menurun
    }

    public function test_baris_produk_yang_hanya_diretur_ikut_terurut_di_antara_baris_lain(): void
    {
        [$lama, $dl] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-05 10:00:00']);  // di luar rentang
        $this->sale(2, 5000, 3000, ['created_at' => '2026-10-12 10:00:00']);                  // 10.000 dalam rentang
        $this->retur($lama, $dl, 2, 'resellable', '2026-10-13 10:00:00');                     // -10.000 (produk lain, retur-saja)

        $rows = $this->labaRugi(['from' => '2026-10-10', 'to' => '2026-10-14'])->assertOk()->viewData('perProduk');

        $this->assertSame([10000, -10000], $rows->pluck('omzet')->all());   // positif di atas, negatif di bawah
    }

    public function test_margin_nol_bukan_angka_aneh_saat_omzet_bersih_tepat_nol(): void
    {
        // Semua barang diretur RUSAK: omzet bersih 0 tetapi HPP tetap 30.000 -> laba -30.000.
        // Margin harus 0,0 (bukan -3.000.000 akibat pembagian dengan pengaman palsu).
        [$trx, $d] = $this->sale(10, 5000, 3000);
        $this->retur($trx, $d, 10, 'damaged');

        $r = $this->labaRugi()->assertOk();
        $this->assertSame(0, $r->viewData('totalOmzet'));
        $this->assertSame(30000, $r->viewData('totalHpp'));
        $this->assertSame(-30000, $r->viewData('totalLaba'));
        $this->assertSame(0.0, $r->viewData('margin'));

        $row = $r->viewData('perProduk')->first();
        $this->assertSame(0, $row->omzet);
        $this->assertSame(-30000, $row->laba);
        $this->assertSame(0.0, $row->margin);
    }

    public function test_hpp_retur_barang_curah_dibulatkan_bukan_dipotong(): void
    {
        // unit_cost bertipe integer (Rp 1.001/kg), jadi HPP pecahan hanya muncul dari QTY pecahan (curah).
        // Jual 2,5 kg -> HPP 2.502,5. Retur 0,5 kg layak jual -> biaya kembali 500,5 dibulatkan 501 (bukan
        // dipotong 500). HPP bersih = round(2.502,5 - 500,5) = 2.002.
        $curah = $this->makeProduct(
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 100, 'name' => 'Gula Curah'],
            [['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 20000, 'is_base_unit' => true]]
        );
        [$trx, $d] = $this->sale(2.5, 20000, 1001, [], ['unit_name' => 'kg'], $curah);
        $this->retur($trx, $d, 0.5);

        $r = $this->labaRugi()->assertOk();
        $this->assertSame(501, $r->viewData('hppRetur'));
        $this->assertSame(2002, $r->viewData('totalHpp'));
        $this->assertSame(40000, $r->viewData('totalOmzet'));   // 50.000 - 10.000
    }

    public function test_satu_retur_berisi_banyak_baris_dihitung_sebagai_satu_retur(): void
    {
        // Satu sesi retur (satu dokumen) atas 2 baris barang: jumlah retur = 1, bukan 2.
        $p1 = $this->makeProduct(['stock' => 100, 'name' => 'A']);
        $p2 = $this->makeProduct(['stock' => 100, 'name' => 'B']);
        $trx = $this->makeTransaction(['grand_total' => 15000, 'created_at' => self::NOW], [
            ['product_id' => $p1->id, 'product_name' => 'A', 'unit_conversion' => 1, 'price' => 5000, 'unit_cost' => 3000, 'quantity' => 2, 'subtotal' => 10000],
            ['product_id' => $p2->id, 'product_name' => 'B', 'unit_conversion' => 1, 'price' => 5000, 'unit_cost' => 3000, 'quantity' => 1, 'subtotal' => 5000],
        ]);
        [$d1, $d2] = $trx->details()->orderBy('id')->get()->all();

        app(SalesReturnService::class)->process($trx, [
            $d1->id => ['qty' => '1', 'condition' => 'resellable'],
            $d2->id => ['qty' => '1', 'condition' => 'damaged'],
        ], 'cash', 'uji', (string) Str::uuid(), $this->admin->id);

        $this->assertSame(1, SalesReturn::count());
        $this->penjualan()->assertOk()->assertViewHas('jumlah_retur', 1)->assertViewHas('total_retur', 10000);
        $this->labaRugi()->assertOk()->assertViewHas('jumlahRetur', 1);
        $this->dashboard()->assertOk();
        $this->assertSame(1, $this->dashboard()->viewData('kpi')['jumlah_retur']);
    }

    public function test_catatan_data_lama_tidak_muncul_bila_semua_biaya_tercatat(): void
    {
        [$trx, $d] = $this->sale(4, 2500, 1000);   // unit_cost terisi
        $this->retur($trx, $d, 1);

        $this->labaRugi()->assertOk()->assertViewHas('adaDataLegacy', false);
    }

    public function test_catatan_data_lama_juga_muncul_bila_hanya_retur_yang_memakai_perkiraan_biaya(): void
    {
        // Penjualan lama (unit_cost kosong) di luar rentang; retur-nya di dalam rentang.
        [$trx, $d] = $this->sale(4, 2500, null, ['created_at' => '2026-10-05 10:00:00']);
        $this->retur($trx, $d, 1, 'resellable', '2026-10-14 10:00:00');

        $this->labaRugi(['from' => '2026-10-10', 'to' => '2026-10-14'])->assertOk()
            ->assertViewHas('adaDataLegacy', true);
    }

    public function test_biaya_retur_baris_lama_memakai_average_cost_sama_seperti_penjualannya(): void
    {
        // unit_cost kosong -> fallback average_cost produk (Rp 1.000): HPP 4 x 1.000 = 4.000; retur 1 -> 1.000.
        [$trx, $d] = $this->sale(4, 2500, null);
        $this->retur($trx, $d, 1);

        $r = $this->labaRugi()->assertOk();
        $this->assertSame(1000, $r->viewData('hppRetur'));
        $this->assertSame(3000, $r->viewData('totalHpp'));
        $this->assertSame(7500, $r->viewData('totalOmzet'));
        $this->assertTrue($r->viewData('adaDataLegacy'));
    }

    public function test_halaman_laba_rugi_menampilkan_rincian_retur_dan_catatan_hpp(): void
    {
        [$trx, $d] = $this->sale(10, 5000, 3000);
        $this->retur($trx, $d, 2);

        $res = $this->labaRugi()->assertOk();
        $html = $res->getContent();

        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Penjualan Kotor'));
        $this->assertSame('− Rp 10.000', $this->nilaiKartu($html, 'Retur Pelanggan'));
        $this->assertSame('Rp 40.000', $this->nilaiKartu($html, 'Penjualan Bersih'));
        $this->assertSame('Rp 24.000', $this->nilaiKartu($html, 'Total HPP'));
        $this->assertSame('Rp 16.000', $this->nilaiKartu($html, 'Laba Kotor'));

        $res->assertSee('layak jual')->assertSee('(Rp 6.000)', false)->assertSee('kerugian di laba');
    }

    public function test_halaman_laba_rugi_tanpa_retur_tidak_menampilkan_catatan_retur(): void
    {
        $this->sale(10, 5000, 3000);

        $this->labaRugi()->assertOk()
            ->assertSee('Retur Pelanggan')
            ->assertDontSee('kerugian di laba');
    }

    public function test_pdf_laba_rugi_memuat_angka_bersih_yang_sama_dengan_layar(): void
    {
        [$trx, $d] = $this->sale(10, 5000, 3000);
        $this->retur($trx, $d, 2);

        $this->actingAs($this->admin)->get(route('admin.laporan.laba-rugi.pdf'))->assertOk();

        $html = view('admin.laporan.pdf.laba-rugi', $this->labaRugi()->original->getData())->render();

        $this->assertSame('Rp 50.000', $this->nilaiKartu($html, 'Penjualan Kotor'));
        $this->assertSame('- Rp 10.000', $this->nilaiKartu($html, 'Retur (1)'));
        $this->assertSame('Rp 40.000', $this->nilaiKartu($html, 'Penjualan Bersih'));
        $this->assertSame('Rp 24.000', $this->nilaiKartu($html, 'Total HPP'));
        $this->assertSame('Rp 16.000', $this->nilaiKartu($html, 'Laba Kotor'));
        $this->assertSame('40%', $this->nilaiKartu($html, 'Margin'));
    }

    // ============================================================ LAPORAN STOK

    public function test_produk_terlaris_dihitung_bersih_dan_retur_mengubah_peringkat(): void
    {
        $kopi = $this->makeProduct(['stock' => 1000, 'name' => 'Kopi']);
        $gula = $this->makeProduct(['stock' => 1000, 'name' => 'Gula']);
        [$a, $da] = $this->sale(10, 5000, 3000, [], [], $kopi);   // Kopi 10 pcs, 50.000
        [$b] = $this->sale(8, 2000, 1000, [], [], $gula);         // Gula  8 pcs, 16.000
        $this->retur($a, $da, 3);                                 // Kopi bersih: 7 pcs, 35.000

        $terlaris = $this->stok()->assertOk()->viewData('terlaris');

        $this->assertSame(['Gula', 'Kopi'], $terlaris->pluck('product_name')->all());   // tanpa retur: Kopi (10) di atas Gula (8)
        $this->assertEquals([8, 7], $terlaris->pluck('qty_terjual')->all());
        $this->assertSame([16000, 35000], $terlaris->pluck('omzet')->all());
    }

    public function test_produk_dengan_terjual_bersih_nol_tidak_masuk_terlaris(): void
    {
        $kopi = $this->makeProduct(['stock' => 1000, 'name' => 'Kopi']);
        [$a, $da] = $this->sale(2, 5000, 3000, [], [], $kopi);
        $this->retur($a, $da, 2);   // semua diretur -> bersih 0

        $this->assertCount(0, $this->stok()->assertOk()->viewData('terlaris'));
    }

    public function test_batas_sepuluh_teratas_diterapkan_setelah_retur_dikurangkan(): void
    {
        // 10 produk @ 5 pcs; 1 produk "Bintang" @ 20 pcs yang 18-nya diretur (bersih 2).
        // Kalau retur baru dikurangkan SETELAH dipotong 10 teratas, Bintang (20 pcs mentah) menempati
        // peringkat 1 dan mendesak satu produk @ 5 pcs keluar; yang benar: Bintang bersih 2 < 5 -> tidak masuk.
        for ($i = 1; $i <= 10; $i++) {
            $this->sale(5, 1000, 500, [], [], $this->makeProduct(['stock' => 100, 'name' => "Biasa {$i}"]));
        }
        [$trx, $d] = $this->sale(20, 1000, 500, [], [], $this->makeProduct(['stock' => 100, 'name' => 'Bintang']));
        $this->retur($trx, $d, 18);

        $terlaris = $this->stok()->assertOk()->viewData('terlaris');

        $this->assertCount(10, $terlaris);
        $this->assertFalse($terlaris->contains('product_name', 'Bintang'));
        $this->assertSame(10, $terlaris->filter(fn ($r) => str_starts_with($r->product_name, 'Biasa'))->count());
    }

    public function test_halaman_stok_dan_pdf_menandai_terlaris_sebagai_bersih(): void
    {
        [$trx, $d] = $this->sale(10, 5000);
        $this->retur($trx, $d, 2);

        $this->stok()->assertOk()->assertSee('bersih, setelah retur');
        $data = $this->stok()->original->getData();
        $html = view('admin.laporan.pdf.stok', $data + ['produk' => collect(), 'dibatasi' => false])->render();
        $this->assertStringContainsString('bersih setelah retur', $html);
    }

    // ============================================== dua produk berbeda, nama sama

    /** @return array{0: Product, 1: Product} P2 (id lebih besar) yang diretur */
    private function duaProdukBernamaSama(): array
    {
        $p1 = $this->makeProduct(['stock' => 1000, 'name' => 'Kopi']);
        $p2 = $this->makeProduct(['stock' => 1000, 'name' => 'Kopi']);   // BEDA produk, nama sama
        $this->sale(10, 5000, 3000, [], ['product_name' => 'Kopi'], $p1);            // P1: 50.000
        [$trx2, $d2] = $this->sale(4, 5000, 3000, [], ['product_name' => 'Kopi'], $p2); // P2: 20.000
        $this->retur($trx2, $d2, 2);                                                  // retur P2 saja: 10.000

        return [$p1, $p2];
    }

    public function test_laba_rugi_retur_tidak_tertukar_antar_produk_bernama_sama(): void
    {
        [$p1, $p2] = $this->duaProdukBernamaSama();

        $rows = $this->labaRugi()->assertOk()->viewData('perProduk')->keyBy('product_id');

        $this->assertCount(2, $rows);
        $this->assertSame(50000, $rows[$p1->id]->omzet);   // P1 TIDAK ikut berkurang
        $this->assertSame(10000, $rows[$p2->id]->omzet);   // P2: 20.000 - 10.000
        $this->assertEquals(10, $rows[$p1->id]->qty_terjual);
        $this->assertEquals(2, $rows[$p2->id]->qty_terjual);
    }

    public function test_dashboard_retur_tidak_tertukar_antar_produk_bernama_sama(): void
    {
        [$p1, $p2] = $this->duaProdukBernamaSama();

        $top = $this->dashboard()->assertOk()->viewData('produkTerlaris')->keyBy('product_id');

        $this->assertSame(50000, $top[$p1->id]->total_omzet);
        $this->assertSame(10000, $top[$p2->id]->total_omzet);
    }

    public function test_stok_terlaris_retur_tidak_tertukar_antar_produk_bernama_sama(): void
    {
        [$p1, $p2] = $this->duaProdukBernamaSama();

        $rows = $this->stok()->assertOk()->viewData('terlaris')->keyBy('product_id');

        $this->assertEquals(10, $rows[$p1->id]->qty_terjual);
        $this->assertEquals(2, $rows[$p2->id]->qty_terjual);
    }

    // =============================================================== DASHBOARD

    public function test_kpi_hari_ini_dikurangi_retur_yang_terjadi_hari_ini(): void
    {
        [$a, $da] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-14 08:00:00']);   // 50.000
        [$b] = $this->sale(4, 5000, 3000, ['created_at' => '2026-10-14 09:00:00']);         // 20.000
        [$lama, $dl] = $this->sale(2, 5000, 3000, ['created_at' => '2026-10-05 09:00:00']); // penjualan lama
        $this->retur($a, $da, 2);                                   // hari ini: 10.000
        $this->retur($lama, $dl, 1);                                // hari ini, atas penjualan lama: 5.000 (tetap mengurangi HARI INI)
        $this->retur($a, $da, 1, 'resellable', '2026-10-13 10:00:00');   // kemarin: TIDAK ikut

        $kpi = $this->dashboard()->assertOk()->viewData('kpi');

        $this->assertSame(70000, $kpi['omzet_kotor']);
        $this->assertSame(15000, $kpi['retur']);
        $this->assertSame(2, $kpi['jumlah_retur']);
        $this->assertSame(55000, $kpi['omzet']);
        $this->assertSame(2, $kpi['jumlah_transaksi']);
        $this->assertSame(35000, $kpi['rata_rata']);   // 70.000 / 2 (kotor)
    }

    public function test_kpi_batas_hari_retur_tengah_malam(): void
    {
        [$trx, $d] = $this->sale(10, 1000, 500, ['created_at' => '2026-10-01 08:00:00']);
        $this->retur($trx, $d, 1, 'resellable', '2026-10-13 23:59:59');   // kemarin
        $this->retur($trx, $d, 1, 'resellable', '2026-10-14 00:00:00');   // hari ini (awal)
        $this->retur($trx, $d, 1, 'resellable', '2026-10-14 23:59:59');   // hari ini (akhir)

        $kpi = $this->dashboard()->assertOk()->viewData('kpi');

        $this->assertSame(2000, $kpi['retur']);
        $this->assertSame(2, $kpi['jumlah_retur']);
    }

    public function test_kpi_tanpa_retur_omzet_sama_dengan_kotor(): void
    {
        $this->sale(10, 5000);

        $kpi = $this->dashboard()->assertOk()->viewData('kpi');
        $this->assertSame(50000, $kpi['omzet']);
        $this->assertSame(0, $kpi['retur']);
        $this->assertSame(0, $kpi['jumlah_retur']);
    }

    public function test_kartu_omzet_dashboard_menampilkan_info_retur_hanya_bila_ada(): void
    {
        [$trx, $d] = $this->sale(10, 5000);
        $this->dashboard()->assertOk()->assertDontSee('sudah dikurangi retur');

        $this->retur($trx, $d, 2);
        $this->dashboard()->assertOk()->assertSee('sudah dikurangi retur Rp 10.000')
            ->assertSeeInOrder(['Omzet Hari Ini', 'Rp 40.000', 'sudah dikurangi retur Rp 10.000']);
    }

    public function test_grafik_penjualan_neto_per_hari_dan_boleh_negatif(): void
    {
        [$s12] = $this->sale(6, 5000, 3000, ['created_at' => '2026-10-12 10:00:00']);    // 30.000
        $this->sale(4, 5000, 3000, ['created_at' => '2026-10-13 10:00:00']);             // 20.000
        [$s14, $d14] = $this->sale(10, 5000, 3000, ['created_at' => '2026-10-14 10:00:00']); // 50.000
        [$s05, $d05] = $this->sale(2, 5000, 3000, ['created_at' => '2026-10-05 10:00:00']);  // di luar jendela 7 hari
        $this->retur($s14, $d14, 2, 'resellable', '2026-10-14 11:00:00');   // 14 Okt: -10.000
        $d12 = $s12->details()->first();
        $this->retur($s12, $d12, 1, 'resellable', '2026-10-13 11:00:00');   // 13 Okt: -5.000 (atas penjualan 12 Okt)
        $this->retur($s05, $d05, 1, 'resellable', '2026-10-11 11:00:00');   // 11 Okt: -5.000 (atas penjualan lama) -> hari NEGATIF

        $grafik = $this->dashboard()->assertOk()->viewData('grafikPenjualan');

        // 8, 9, 10, 11, 12, 13, 14 Okt
        $this->assertSame(['08 Oct', '09 Oct', '10 Oct', '11 Oct', '12 Oct', '13 Oct', '14 Oct'], $grafik['labels']);
        $this->assertSame([0, 0, 0, -5000, 30000, 15000, 40000], $grafik['values']);
    }

    public function test_grafik_tanpa_retur_tidak_berubah(): void
    {
        $this->sale(10, 5000, 3000, ['created_at' => '2026-10-14 10:00:00']);

        $this->assertSame([0, 0, 0, 0, 0, 0, 50000], $this->dashboard()->assertOk()->viewData('grafikPenjualan')['values']);
    }

    public function test_produk_bersih_nol_tidak_muncul_di_top_lima_walau_daftar_belum_penuh(): void
    {
        // Hanya 2 produk: A bersih 5.000 dan G bersih 0 (diretur seluruhnya). Karena daftar belum penuh 5,
        // G akan ikut tampil kalau tidak disaring.
        [$a, $da] = $this->sale(2, 5000, 3000, [], [], $this->makeProduct(['stock' => 1000, 'name' => 'Prod A']));
        [$g, $dg] = $this->sale(3, 3000, 1000, [], [], $this->makeProduct(['stock' => 1000, 'name' => 'Prod G']));
        $this->retur($g, $dg, 3);
        $this->retur($a, $da, 1);   // A: 10.000 - 5.000 = 5.000

        $top = $this->dashboard()->assertOk()->viewData('produkTerlaris');

        $this->assertSame(['Prod A'], $top->pluck('product_name')->all());
        $this->assertSame([5000], $top->pluck('total_omzet')->all());
    }

    public function test_qty_top_lima_dashboard_dalam_satuan_jual_bukan_satuan_dasar(): void
    {
        // 2 dus (1 dus = 12 pcs) @ Rp 120.000; retur 1 dus -> qty bersih 1 dus (BUKAN 2 - 12).
        [$trx, $d] = $this->sale(2, 120000, 8000, [], ['unit_name' => 'dus', 'unit_conversion' => 12]);
        $this->retur($trx, $d, 1);

        $top = $this->dashboard()->assertOk()->viewData('produkTerlaris');

        $this->assertCount(1, $top);
        $this->assertSame(120000, $top->first()->total_omzet);
        $this->assertEquals(1, $top->first()->total_qty);
    }

    public function test_top_lima_dashboard_memakai_omzet_bersih_dan_mengubah_peringkat(): void
    {
        $nama = ['A' => [10, 5000], 'B' => [4, 5000], 'C' => [2, 5000], 'D' => [2, 4000], 'E' => [2, 3000], 'F' => [2, 2000]];
        $trx = [];
        foreach ($nama as $n => [$qty, $price]) {
            $trx[$n] = $this->sale($qty, $price, 1000, [], [], $this->makeProduct(['stock' => 1000, 'name' => "Prod {$n}"]));
        }
        // G: 3 pcs @ 3.000 diretur seluruhnya -> bersih 0 -> tidak ikut
        $trx['G'] = $this->sale(3, 3000, 1000, [], [], $this->makeProduct(['stock' => 1000, 'name' => 'Prod G']));

        $this->retur($trx['A'][0], $trx['A'][1], 9);   // A: 50.000 - 45.000 = 5.000 (qty 10 - 9 = 1)
        $this->retur($trx['G'][0], $trx['G'][1], 3);

        // Bersih: B 20.000, C 10.000, D 8.000, E 6.000, A 5.000, F 4.000, G 0.
        $top = $this->dashboard()->assertOk()->viewData('produkTerlaris');

        $this->assertSame(['Prod B', 'Prod C', 'Prod D', 'Prod E', 'Prod A'], $top->pluck('product_name')->all());
        $this->assertSame([20000, 10000, 8000, 6000, 5000], $top->pluck('total_omzet')->all());
        $this->assertEquals(1, $top->last()->total_qty);   // A: 10 - 9
    }
}