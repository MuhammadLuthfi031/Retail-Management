<?php

namespace Tests\Feature\Admin;

use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Dashboard, §7.1 spesifikasi) — korektivitas ANGKA yang
 * tampil di Admin/DashboardController: KPI hari ini, grafik penjualan,
 * grafik barang masuk, produk terlaris, riwayat terbaru, dan stok menipis.
 *
 * Dashboard adalah tempat pemilik toko "sekali lihat langsung paham", dan
 * kesalahan agregasi di sini TIDAK memunculkan error — hanya angka keliru di
 * layar. Karena itu semua angka ekspektasi dihitung TANGAN, dan semua batas
 * hari (00:00:00 / 23:59:59) diuji eksplisit.
 *
 * Semua waktu dibangun RELATIF terhadap timezone aplikasi (Carbon::parse tanpa
 * zona), bukan UTC-hardcoded, supaya test tetap benar kalau timezone aplikasi
 * diganti nanti (lihat temuan timezone QA-004).
 *
 * Kalender uji: "hari ini" = 14 Okt 2026 12:00. Jendela 7 hari = 08–14 Okt,
 * jendela 30 hari = 15 Sep–14 Okt.
 */
class DashboardKorektivitasTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        $this->hindariIdUserPertama();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // === Helper ===

    private function dash(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard', $query));
    }

    /** Transaksi pada waktu tertentu; total_amount dibuat sama dengan grand_total (tanpa diskon). */
    private function trx(int $grandTotal, string $at, array $attr = [], array $items = [])
    {
        return $this->makeTransaction(array_merge([
            'grand_total' => $grandTotal,
            'total_amount' => $grandTotal,
            'created_at' => Carbon::parse($at),
        ], $attr), $items);
    }

    /** Baris detail penjualan: produk nyata (FK), nama snapshot, omzet bersih, qty. */
    private function baris(string $nama, int $subtotal, float $qty = 1, ?int $productId = null): array
    {
        return [
            'product_id' => $productId ?? $this->makeProduct()->id,
            'product_name' => $nama,
            'subtotal' => $subtotal,
            'quantity' => $qty,
        ];
    }

    private function kpi(array $query = []): array
    {
        return $this->dash($query)->assertOk()->viewData('kpi');
    }

    /** @return array<string, int> label => nilai */
    private function seri(string $key, array $query = []): array
    {
        $g = $this->dash($query)->assertOk()->viewData($key);

        return array_combine($g['labels'], $g['values']);
    }

    private function terlaris(array $query = []): array
    {
        return $this->dash($query)->assertOk()->viewData('produkTerlaris')
            ->map(fn ($r) => [$r->product_name, (int) $r->total_omzet])->all();
    }

    // =====================================================================
    // KPI hari ini
    // =====================================================================

    public function test_kpi_omzet_jumlah_dan_rata_rata_hari_ini_dihitung_benar(): void
    {
        $this->trx(10000, '2026-10-14 08:00:00');
        $this->trx(20000, '2026-10-14 12:00:00');
        $this->trx(25000, '2026-10-14 18:30:00');

        $kpi = $this->kpi();

        $this->assertSame(55000, $kpi['omzet']);
        $this->assertSame(3, $kpi['jumlah_transaksi']);
        $this->assertSame(18333, $kpi['rata_rata']); // 55.000 / 3 = 18.333,33 -> dibulatkan
    }

    public function test_rata_rata_dibulatkan_bukan_dipotong(): void
    {
        $this->trx(10001, '2026-10-14 09:00:00');
        $this->trx(10000, '2026-10-14 10:00:00');

        // 20.001 / 2 = 10.000,5 -> round = 10.001 (dipotong akan 10.000).
        $this->assertSame(10001, $this->kpi()['rata_rata']);
    }

    public function test_kpi_tanpa_transaksi_semua_nol_tanpa_pembagian_nol(): void
    {
        $kpi = $this->kpi();

        $this->assertSame(0, $kpi['omzet']);
        $this->assertSame(0, $kpi['jumlah_transaksi']);
        $this->assertSame(0, $kpi['rata_rata']);
    }

    public function test_omzet_memakai_grand_total_setelah_diskon_bukan_total_sebelum_diskon(): void
    {
        $this->trx(90000, '2026-10-14 10:00:00', ['total_amount' => 100000, 'discount_amount' => 10000]);

        $this->assertSame(90000, $this->kpi()['omzet']);
    }

    public function test_kpi_hanya_menghitung_transaksi_completed(): void
    {
        $this->trx(10000, '2026-10-14 10:00:00');
        $this->trx(99000, '2026-10-14 10:05:00', ['status' => 'refunded']);
        $this->trx(77000, '2026-10-14 10:10:00', ['status' => 'cancelled']);

        $kpi = $this->kpi();

        $this->assertSame(10000, $kpi['omzet']);
        $this->assertSame(1, $kpi['jumlah_transaksi']);
    }

    public function test_batas_hari_kpi_00_00_00_masuk_dan_23_59_59_hari_sebelumnya_dan_besok_tidak(): void
    {
        $this->trx(1000, '2026-10-13 23:59:59');   // kemarin   -> tidak
        $this->trx(2000, '2026-10-14 00:00:00');   // awal hari -> masuk
        $this->trx(4000, '2026-10-14 23:59:59');   // akhir hari-> masuk
        $this->trx(8000, '2026-10-15 00:00:00');   // besok     -> tidak

        $kpi = $this->kpi();

        $this->assertSame(6000, $kpi['omzet']);
        $this->assertSame(2, $kpi['jumlah_transaksi']);
    }

    public function test_kpi_selalu_hari_ini_dan_tidak_ikut_toggle_7_30_hari(): void
    {
        $this->trx(5000, '2026-10-14 10:00:00');
        $this->trx(7000, '2026-10-05 10:00:00'); // 9 hari lalu: ikut grafik 30 hari, bukan KPI

        $this->assertSame($this->kpi(['days' => 7]), $this->kpi(['days' => 30]));
        $this->assertSame(5000, $this->kpi(['days' => 30])['omzet']);
    }

    public function test_kpi_stok_menipis_hanya_produk_aktif_dengan_batas_sama_dengan(): void
    {
        $this->makeProduct(['stock' => 5, 'min_stock' => 5]);                          // 5 <= 5 -> menipis
        $this->makeProduct(['stock' => 0, 'min_stock' => 5]);                          // menipis
        $this->makeProduct(['stock' => 5.001, 'min_stock' => 5]);                      // sedikit di atas -> aman
        $this->makeProduct(['stock' => 0, 'min_stock' => 5, 'is_active' => false]);    // nonaktif -> diabaikan

        $this->assertSame(2, $this->kpi()['stok_menipis_count']);
    }

    // =====================================================================
    // Grafik penjualan
    // =====================================================================

    public function test_grafik_penjualan_7_hari_menjumlah_per_hari_dan_mengisi_hari_kosong_dengan_nol(): void
    {
        $this->trx(1000, '2026-10-08 09:00:00');
        $this->trx(2000, '2026-10-10 09:00:00');
        $this->trx(3000, '2026-10-10 20:00:00');
        // Ada diskon: grafik harus memakai grand_total (4.000), BUKAN total_amount sebelum diskon (5.000).
        $this->trx(4000, '2026-10-14 11:00:00', ['total_amount' => 5000, 'discount_amount' => 1000]);

        $this->assertSame([
            '08 Oct' => 1000,
            '09 Oct' => 0,
            '10 Oct' => 5000,
            '11 Oct' => 0,
            '12 Oct' => 0,
            '13 Oct' => 0,
            '14 Oct' => 4000,
        ], $this->seri('grafikPenjualan'));
    }

    public function test_grafik_penjualan_batas_jendela_7_hari_inklusif_di_awal_dan_akhir(): void
    {
        $this->trx(100, '2026-10-07 23:59:59');    // 1 detik SEBELUM jendela -> tidak
        $this->trx(200, '2026-10-08 00:00:00');    // tepat awal jendela      -> masuk
        $this->trx(400, '2026-10-14 23:59:59');    // tepat akhir hari ini    -> masuk (QA-006)
        $this->trx(800, '2026-10-15 00:00:00');    // besok                   -> tidak

        $seri = $this->seri('grafikPenjualan');

        $this->assertSame(200, $seri['08 Oct']);
        $this->assertSame(400, $seri['14 Oct']);
        $this->assertSame(600, array_sum($seri));
    }

    public function test_grafik_penjualan_hanya_transaksi_completed(): void
    {
        $this->trx(1000, '2026-10-14 10:00:00');
        $this->trx(5000, '2026-10-14 10:00:00', ['status' => 'refunded']);
        $this->trx(9000, '2026-10-14 10:00:00', ['status' => 'cancelled']);

        $this->assertSame(1000, $this->seri('grafikPenjualan')['14 Oct']);
    }

    public function test_toggle_30_hari_memperluas_jendela_dan_default_serta_input_aneh_jatuh_ke_7_hari(): void
    {
        $this->trx(3000, '2026-09-20 10:00:00'); // 24 hari lalu: hanya masuk jendela 30 hari

        $this->assertCount(7, $this->seri('grafikPenjualan'));
        $this->assertSame(0, array_sum($this->seri('grafikPenjualan')));

        $s30 = $this->seri('grafikPenjualan', ['days' => 30]);
        $this->assertCount(30, $s30);
        $this->assertSame('15 Sep', array_key_first($s30));
        $this->assertSame('14 Oct', array_key_last($s30));
        $this->assertSame(3000, $s30['20 Sep']);

        foreach (['abc', '14', '0', '-5', '', '7.5', '300'] as $aneh) {
            $this->assertCount(7, $this->seri('grafikPenjualan', ['days' => $aneh]), "days={$aneh} harus jatuh ke 7 hari");
        }

        $this->dash(['days' => 30])->assertViewHas('days', 30);
        $this->dash(['days' => 'abc'])->assertViewHas('days', 7);
    }

    // =====================================================================
    // Grafik barang masuk (nilai penerimaan PO)
    // =====================================================================

    public function test_grafik_barang_masuk_nilai_adalah_qty_diterima_kali_harga_beli_per_hari_penerimaan(): void
    {
        $this->makePurchaseOrder(['status' => 'received'], [
            // 4 dari 10 dus diterima: nilai = 4 x 100.000 = 400.000 (BUKAN qty dipesan 10 x 100.000).
            ['quantity_ordered' => 10, 'quantity_received' => 4, 'unit_price' => 100000, 'received_at' => Carbon::parse('2026-10-14 09:00:00')],
            // Dua item di hari yang sama dijumlahkan: 3 x 50.000 = 150.000.
            ['quantity_ordered' => 3, 'quantity_received' => 3, 'unit_price' => 50000, 'received_at' => Carbon::parse('2026-10-14 15:00:00')],
            // Curah pecahan: 2,5 kg x 20.000 = 50.000, di hari lain.
            ['quantity_ordered' => 5, 'quantity_received' => 2.5, 'unit_price' => 20000, 'received_at' => Carbon::parse('2026-10-11 10:00:00')],
        ]);

        $seri = $this->seri('grafikBarangMasuk');

        $this->assertSame(550000, $seri['14 Oct']);
        $this->assertSame(50000, $seri['11 Oct']);
        $this->assertSame(600000, array_sum($seri));
    }

    public function test_grafik_barang_masuk_membulatkan_nilai_pecahan_bukan_memotong(): void
    {
        // 1,5 x 333 = 499,5 -> round = 500 (dipotong akan 499).
        $this->makePurchaseOrder(['status' => 'received'], [
            ['quantity_ordered' => 2, 'quantity_received' => 1.5, 'unit_price' => 333, 'received_at' => Carbon::parse('2026-10-14 09:00:00')],
        ]);

        $this->assertSame(500, $this->seri('grafikBarangMasuk')['14 Oct']);
    }

    public function test_grafik_barang_masuk_mengabaikan_penerimaan_di_luar_jendela_dan_item_belum_diterima(): void
    {
        $this->makePurchaseOrder(['status' => 'ordered'], [
            ['quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 10000, 'received_at' => Carbon::parse('2026-10-07 23:59:59')], // sebelum jendela
            ['quantity_ordered' => 5, 'quantity_received' => 5, 'unit_price' => 20000, 'received_at' => Carbon::parse('2026-10-15 00:00:00')], // besok
            ['quantity_ordered' => 5, 'quantity_received' => 0, 'unit_price' => 30000, 'received_at' => null],                                // belum diterima
        ]);

        $this->assertSame(0, array_sum($this->seri('grafikBarangMasuk')));

        // Jendela 30 hari memasukkan yang 7 Okt.
        $this->assertSame(50000, array_sum($this->seri('grafikBarangMasuk', ['days' => 30])));
    }

    public function test_grafik_barang_masuk_batas_awal_dan_akhir_jendela_inklusif(): void
    {
        $this->makePurchaseOrder(['status' => 'received'], [
            ['quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 1000, 'received_at' => Carbon::parse('2026-10-08 00:00:00')],
            ['quantity_ordered' => 1, 'quantity_received' => 1, 'unit_price' => 2000, 'received_at' => Carbon::parse('2026-10-14 23:59:59')],
        ]);

        $seri = $this->seri('grafikBarangMasuk');

        $this->assertSame(1000, $seri['08 Oct']);
        $this->assertSame(2000, $seri['14 Oct']);
    }

    /**
     * Alasan grafik ini membaca PurchaseOrderItem, bukan StockMovement 'in':
     * stok awal saat produk dibuat juga tercatat sebagai 'in', dan itu BUKAN
     * barang masuk dari pembelian (akan membuat grafik melonjak palsu).
     */
    public function test_stok_awal_produk_baru_tidak_dihitung_sebagai_barang_masuk(): void
    {
        $gudang = User::factory()->gudang()->create();
        $produk = $this->makeProduct();

        StockMovement::record(
            product: $produk,
            type: 'in',
            quantity: 500.0,
            userId: $gudang->id,
            note: 'Stok awal saat produk pertama kali dibuat',
            unitCost: 1000,
        );

        $this->assertSame(500.0, $this->stockOf($produk), 'fixture: stok awal memang tercatat');
        $this->assertSame(0, array_sum($this->seri('grafikBarangMasuk')));
    }

    // =====================================================================
    // Produk terlaris
    // =====================================================================

    public function test_top_5_diurutkan_berdasarkan_omzet_bukan_qty_dan_dibatasi_5(): void
    {
        $this->trx(0, '2026-10-14 10:00:00', [], [
            $this->baris('Produk G', 100000, 1),
            $this->baris('Produk F', 200000, 1),
            $this->baris('Produk E', 300000, 1),
            $this->baris('Produk D', 400000, 1),
            $this->baris('Produk C', 500000, 1),
            $this->baris('Produk B', 600000, 1),
            // Qty paling banyak tapi omzet kecil -> tidak boleh menang hanya karena qty.
            $this->baris('Permen', 10000, 500),
        ]);

        $this->assertSame([
            ['Produk B', 600000],
            ['Produk C', 500000],
            ['Produk D', 400000],
            ['Produk E', 300000],
            ['Produk F', 200000],
        ], $this->terlaris());
    }

    public function test_omzet_produk_dijumlah_lintas_transaksi_dan_memakai_subtotal_bersih_diskon_baris(): void
    {
        $produk = $this->makeProduct();

        // subtotal = harga x qty SETELAH diskon baris (kolom ini sudah bersih).
        $this->trx(0, '2026-10-14 09:00:00', [], [$this->baris('Kopi', 18000, 2, $produk->id)]);
        $this->trx(0, '2026-10-13 09:00:00', [], [$this->baris('Kopi', 27000, 3, $produk->id)]);

        $this->assertSame([['Kopi', 45000]], $this->terlaris());
    }

    public function test_produk_terlaris_hanya_transaksi_completed_dan_di_dalam_jendela(): void
    {
        $this->trx(0, '2026-10-14 09:00:00', [], [$this->baris('Aktif', 10000)]);
        $this->trx(0, '2026-10-14 09:00:00', ['status' => 'refunded'], [$this->baris('Refund', 99000)]);
        $this->trx(0, '2026-10-14 09:00:00', ['status' => 'cancelled'], [$this->baris('Batal', 88000)]);
        $this->trx(0, '2026-10-07 23:59:59', [], [$this->baris('Lama', 77000)]);   // 1 detik sebelum jendela 7 hari
        $this->trx(0, '2026-10-15 00:00:00', [], [$this->baris('Besok', 66000)]);

        $this->assertSame([['Aktif', 10000]], $this->terlaris());
        $this->assertSame([['Lama', 77000], ['Aktif', 10000]], $this->terlaris(['days' => 30]));
    }

    /**
     * Regresi QA-004: Dashboard mengelompokkan per NAMA saja, sedangkan Laporan
     * per product_id + nama — dua produk BERBEDA yang kebetulan bernama sama
     * (mis. beda ukuran/kategori) tergabung jadi satu baris di Dashboard.
     */
    public function test_dua_produk_berbeda_dengan_nama_sama_tidak_digabung(): void
    {
        $this->trx(0, '2026-10-14 09:00:00', [], [
            $this->baris('Aqua', 30000, 1, $this->makeProduct()->id),
            $this->baris('Aqua', 20000, 1, $this->makeProduct()->id),
        ]);

        $this->assertSame([['Aqua', 30000], ['Aqua', 20000]], $this->terlaris());
    }

    public function test_dashboard_dan_laporan_penjualan_sepakat_tentang_omzet_hari_ini(): void
    {
        $this->trx(55000, '2026-10-14 08:00:00', ['total_amount' => 60000, 'discount_amount' => 5000]);
        $this->trx(45000, '2026-10-14 16:00:00');
        $this->trx(99999, '2026-10-14 17:00:00', ['status' => 'cancelled']);
        $this->trx(12345, '2026-10-13 23:59:59');   // kemarin

        $hariIni = '2026-10-14';
        $laporan = $this->actingAs($this->admin)
            ->get(route('admin.laporan.penjualan', ['from' => $hariIni, 'to' => $hariIni]))->assertOk();

        $kpi = $this->kpi();

        $this->assertSame(100000, $kpi['omzet']);
        $laporan->assertViewHas('total_omzet', $kpi['omzet']);
        $laporan->assertViewHas('jumlah_transaksi', $kpi['jumlah_transaksi']);
        $laporan->assertViewHas('rata_rata', $kpi['rata_rata']);
    }

    // =====================================================================
    // Riwayat terbaru
    // =====================================================================

    public function test_riwayat_terbaru_maks_10_terbaru_dulu_hanya_completed_dan_tidak_ikut_toggle_periode(): void
    {
        // 12 transaksi completed berjarak 1 jam (id naik = waktu naik), termasuk yang sangat lama.
        $ids = [];
        foreach (range(1, 12) as $i) {
            $ids[$i] = $this->trx(1000 * $i, Carbon::parse('2026-08-01 08:00:00')->addHours($i)->toDateTimeString())->id;
        }
        $this->trx(999999, '2026-10-14 11:00:00', ['status' => 'cancelled']); // terbaru tapi dibatalkan

        $riwayat = $this->dash(['days' => 7])->assertOk()->viewData('riwayatTerbaru');

        $this->assertCount(10, $riwayat);
        $this->assertSame(
            [$ids[12], $ids[11], $ids[10], $ids[9], $ids[8], $ids[7], $ids[6], $ids[5], $ids[4], $ids[3]],
            $riwayat->pluck('id')->all()
        );
    }

    public function test_riwayat_terbaru_menampilkan_nama_kasir_dan_total_di_halaman(): void
    {
        $kasir = User::factory()->kasir()->create(['name' => 'Siti Kasir']);
        $this->trx(123000, '2026-10-14 10:00:00', ['user_id' => $kasir->id, 'invoice_number' => 'INV-TAMPIL-1']);

        $this->dash()->assertOk()
            ->assertSee('INV-TAMPIL-1')
            ->assertSee('Siti Kasir')
            ->assertSee('Rp 123.000');
    }

    // =====================================================================
    // Stok menipis (daftar)
    // =====================================================================

    public function test_daftar_stok_menipis_maks_10_terurut_nama_dan_sisanya_dihitung_di_kpi(): void
    {
        // Dibuat TERBALIK (12 -> 1) supaya urutan id berlawanan dengan urutan nama.
        foreach (range(12, 1) as $i) {
            $this->makeProduct(['name' => sprintf('Item %02d', $i), 'stock' => 0, 'min_stock' => 5]);
        }
        // Nama dipilih terurut PALING AWAL (huruf 'A' < 'I') supaya kalau filternya hilang,
        // keduanya langsung masuk 10 teratas — bukan kebetulan terpotong limit di ujung.
        $this->makeProduct(['name' => 'A Nonaktif', 'stock' => 0, 'min_stock' => 5, 'is_active' => false]); // menipis tapi nonaktif
        $this->makeProduct(['name' => 'A Aman', 'stock' => 100, 'min_stock' => 5]);                         // aktif tapi stok aman

        $res = $this->dash()->assertOk();
        $daftar = $res->viewData('stokMenipis');

        $this->assertSame(12, $res->viewData('kpi')['stok_menipis_count']);
        $this->assertCount(10, $daftar);
        $this->assertSame('Item 01', $daftar->first()->name);
        $this->assertSame('Item 10', $daftar->last()->name);
        $this->assertNotContains('A Nonaktif', $daftar->pluck('name')->all());
        $this->assertNotContains('A Aman', $daftar->pluck('name')->all());
        $res->assertSee('+2 produk lainnya');
    }

    // =====================================================================
    // Render & akses
    // =====================================================================

    public function test_halaman_dashboard_render_penuh_dengan_semua_widget_terisi_dan_format_rupiah(): void
    {
        $this->makeProduct(['name' => 'Gula Menipis', 'stock' => 1, 'min_stock' => 5]);
        $this->trx(55000, '2026-10-14 10:00:00', [], [$this->baris('Beras 5kg', 55000)]);
        $this->makePurchaseOrder(['status' => 'received'], [
            ['quantity_ordered' => 2, 'quantity_received' => 2, 'unit_price' => 10000, 'received_at' => Carbon::parse('2026-10-14 09:00:00')],
        ]);

        // assertOk juga membuktikan tidak ada lazy-loading terlarang (preventLazyLoading aktif di non-produksi).
        $this->dash()->assertOk()
            ->assertSee('Rp 55.000')
            ->assertSee('Beras 5kg')
            ->assertSee('Gula Menipis');
    }

    public function test_hanya_admin_yang_boleh_membuka_dashboard_admin(): void
    {
        foreach (['kasir', 'gudang'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('admin.dashboard'))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

        $this->dash()->assertOk();
    }
}