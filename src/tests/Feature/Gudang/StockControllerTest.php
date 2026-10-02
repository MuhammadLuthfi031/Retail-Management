<?php

namespace Tests\Feature\Gudang;

use App\Http\Controllers\Gudang\StockController;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Stok, §5.4 spesifikasi) — korektivitas lapisan controller
 * Gudang/StockController: stok keluar, mutasi, opname, daftar stok, riwayat.
 *
 * Mekanisme record() (lock, guard minus, average_cost) sudah dijaga
 * StockMovementTest; di sini yang diuji lapisan DI ATASNYA: validasi form,
 * pesan error, perhitungan selisih opname, filter/urutan/paginasi, dan akses.
 *
 * Catatan desain test: controller dan record() sama-sama menolak qty
 * berlebih dengan key error 'quantity'. Supaya penghapusan SALAH SATU lapis
 * tidak lolos diam-diam, test memeriksa TEKS pesan (pesan controller:
 * "tidak boleh melebihi stok yang ada"; pesan record(): "Stok tidak cukup").
 */
class StockControllerTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hindariIdUserPertama();
        $this->gudang = User::factory()->gudang()->create();
    }

    // === Helper ===

    private function produk(float $stok = 10, array $attr = []): Product
    {
        return $this->makeProduct(array_merge(['stock' => $stok], $attr));
    }

    private function keluar(Product $p, array $input, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->post(route('gudang.stok.keluar', $p), $input);
    }

    private function mutasi(Product $p, array $input, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->post(route('gudang.stok.mutasi', $p), $input);
    }

    private function opname(Product $p, array $input, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->post(route('gudang.stok.opname', $p), $input);
    }

    private function pesanError($response, string $key): string
    {
        $response->assertSessionHasErrors($key);

        return (string) session('errors')->first($key);
    }

    private function jumlahMovement(Product $p, ?string $type = null): int
    {
        return StockMovement::where('product_id', $p->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->count();
    }

    /**
     * Panggil aksi controller LANGSUNG dengan instance Product yang sengaja
     * BASI — mensimulasikan race: route-model-binding sudah resolve produk
     * (stok lama), lalu proses lain mengubah stok sebelum aksi jalan.
     */
    private function panggilDenganProdukBasi(string $aksi, Product $basi, array $input)
    {
        $this->actingAs($this->gudang);

        return app(StockController::class)->{$aksi}(Request::create('/x', 'POST', $input), $basi);
    }

    // =====================================================================
    // Stok keluar
    // =====================================================================

    public function test_stok_keluar_mengurangi_stok_dan_mencatat_movement_lengkap(): void
    {
        $p = $this->produk(10);

        $this->keluar($p, ['quantity' => 3, 'note' => 'Rusak terkena air'])
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(7, $this->stockOf($p), 0.0005);

        $m = StockMovement::where('product_id', $p->id)->sole();
        $this->assertSame('out', $m->type);
        $this->assertEqualsWithDelta(3, (float) $m->quantity, 0.0005);
        $this->assertEqualsWithDelta(10, (float) $m->stock_before, 0.0005);
        $this->assertEqualsWithDelta(7, (float) $m->stock_after, 0.0005);
        $this->assertSame('Rusak terkena air', $m->note);
        $this->assertSame($this->gudang->id, $m->user_id);
    }

    public function test_stok_keluar_bisa_menghabiskan_stok_persis_sampai_nol(): void
    {
        $p = $this->produk(4);

        $this->keluar($p, ['quantity' => 4, 'note' => 'Kadaluarsa semua'])->assertSessionHas('success');

        $this->assertEqualsWithDelta(0, $this->stockOf($p), 0.0005);
    }

    public function test_stok_keluar_menerima_qty_pecahan_untuk_barang_curah(): void
    {
        $curah = $this->produk(10, ['tracking_mode' => 'weight']);

        $this->keluar($curah, ['quantity' => 2.5, 'note' => 'Tumpah'])->assertSessionHas('success');

        $this->assertEqualsWithDelta(7.5, $this->stockOf($curah), 0.0005);
    }

    public function test_stok_keluar_melebihi_stok_ditolak_dengan_pesan_controller_dan_tidak_ada_yang_berubah(): void
    {
        $p = $this->produk(10);

        $res = $this->keluar($p, ['quantity' => 10.5, 'note' => 'Hilang']);

        $this->assertStringContainsString('tidak boleh melebihi stok yang ada', $this->pesanError($res, 'quantity'));
        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_stok_keluar_pada_produk_stok_nol_selalu_ditolak(): void
    {
        $p = $this->produk(0);

        $res = $this->keluar($p, ['quantity' => 1, 'note' => 'Coba']);

        $this->assertStringContainsString('tidak boleh melebihi stok yang ada', $this->pesanError($res, 'quantity'));
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_stok_keluar_menolak_qty_tidak_valid(): void
    {
        $p = $this->produk(10);

        foreach ([0, -1, 'abc', '', 0.0005] as $qty) {
            $this->keluar($p, ['quantity' => $qty, 'note' => 'x'])->assertSessionHasErrors('quantity');
        }

        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_stok_keluar_batas_bawah_qty_adalah_0_001(): void
    {
        $p = $this->produk(10);

        $this->keluar($p, ['quantity' => 0.001, 'note' => 'Sampel kecil'])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(9.999, $this->stockOf($p), 0.0005);
    }

    public function test_stok_keluar_wajib_keterangan_dan_maksimal_500_karakter(): void
    {
        $p = $this->produk(10);

        $this->keluar($p, ['quantity' => 1])->assertSessionHasErrors('note');
        $this->keluar($p, ['quantity' => 1, 'note' => ''])->assertSessionHasErrors('note');
        $this->keluar($p, ['quantity' => 1, 'note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
        $this->assertSame(0, $this->jumlahMovement($p));

        $this->keluar($p, ['quantity' => 1, 'note' => str_repeat('a', 500)])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->jumlahMovement($p, 'out'));
    }

    public function test_race_stok_keluar_dengan_produk_basi_tetap_ditolak_benteng_terakhir_dan_stok_tidak_minus(): void
    {
        $basi = $this->produk(10);
        $this->assertEqualsWithDelta(10, (float) $basi->stock, 0.0005);

        // Proses lain menghabiskan 7 SETELAH produk di-resolve controller.
        StockMovement::record(Product::find($basi->id), 'out', 7.0, $this->gudang->id, note: 'proses lain');

        // Validasi controller (max=10 dari data basi) lolos untuk qty 8,
        // jadi yang menolak harus guard pasca-lock di record().
        try {
            $this->panggilDenganProdukBasi('storeOut', $basi, ['quantity' => 8, 'note' => 'ikut-ikutan']);
            $this->fail('Seharusnya ditolak: stok sebenarnya tinggal 3.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stok tidak cukup', $e->errors()['quantity'][0]);
        }

        $this->assertEqualsWithDelta(3, $this->stockOf($basi), 0.0005);
        $this->assertSame(1, $this->jumlahMovement($basi, 'out'), 'hanya movement "proses lain" yang boleh ada');
    }

    // =====================================================================
    // Mutasi stok
    // =====================================================================

    public function test_mutasi_mengurangi_stok_dan_tercatat_sebagai_type_mutation_bukan_out(): void
    {
        $p = $this->produk(10);

        $this->mutasi($p, ['quantity' => 4, 'note' => 'Pindah ke cabang B'])->assertSessionHas('success');

        $this->assertEqualsWithDelta(6, $this->stockOf($p), 0.0005);

        $m = StockMovement::where('product_id', $p->id)->sole();
        $this->assertSame('mutation', $m->type);
        $this->assertEqualsWithDelta(4, (float) $m->quantity, 0.0005);
        $this->assertSame('Pindah ke cabang B', $m->note);
        $this->assertSame($this->gudang->id, $m->user_id);
    }

    public function test_mutasi_melebihi_stok_ditolak_dengan_pesan_mutasi_dan_tidak_ada_yang_berubah(): void
    {
        $p = $this->produk(10);

        $res = $this->mutasi($p, ['quantity' => 11, 'note' => 'Pindah']);

        $pesan = $this->pesanError($res, 'quantity');
        $this->assertStringContainsString('Qty mutasi tidak boleh melebihi stok', $pesan);
        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_mutasi_wajib_keterangan_dan_qty_valid(): void
    {
        $p = $this->produk(10);

        $this->mutasi($p, ['quantity' => 1])->assertSessionHasErrors('note');
        $this->mutasi($p, ['quantity' => 0, 'note' => 'x'])->assertSessionHasErrors('quantity');
        $this->mutasi($p, ['quantity' => -3, 'note' => 'x'])->assertSessionHasErrors('quantity');
        $this->mutasi($p, ['quantity' => 'abc', 'note' => 'x'])->assertSessionHasErrors('quantity');

        $this->assertSame(0, $this->jumlahMovement($p));
        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
    }

    public function test_race_mutasi_dengan_produk_basi_ditolak_benteng_terakhir(): void
    {
        $basi = $this->produk(10);
        StockMovement::record(Product::find($basi->id), 'out', 9.0, $this->gudang->id, note: 'proses lain');

        try {
            $this->panggilDenganProdukBasi('storeMutation', $basi, ['quantity' => 5, 'note' => 'pindah']);
            $this->fail('Seharusnya ditolak: stok sebenarnya tinggal 1.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stok tidak cukup', $e->errors()['quantity'][0]);
        }

        $this->assertEqualsWithDelta(1, $this->stockOf($basi), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($basi, 'mutation'));
    }

    // =====================================================================
    // Stok opname / adjustment
    // =====================================================================

    public function test_opname_fisik_lebih_banyak_menaikkan_stok_dan_mencatat_selisih_positif(): void
    {
        $p = $this->produk(10);

        $this->opname($p, ['physical_stock' => 12])->assertSessionHas('success');

        $this->assertEqualsWithDelta(12, $this->stockOf($p), 0.0005);

        $m = StockMovement::where('product_id', $p->id)->sole();
        $this->assertSame('adjustment', $m->type);
        $this->assertEqualsWithDelta(2, (float) $m->quantity, 0.0005);
        $this->assertEqualsWithDelta(10, (float) $m->stock_before, 0.0005);
        $this->assertEqualsWithDelta(12, (float) $m->stock_after, 0.0005);
        $this->assertSame('Hasil stok opname (stok fisik lebih banyak 2 dari catatan sistem)', $m->note);
        $this->assertSame($this->gudang->id, $m->user_id);
    }

    public function test_opname_fisik_lebih_sedikit_menurunkan_stok_dan_quantity_tersimpan_positif(): void
    {
        $p = $this->produk(10);

        $this->opname($p, ['physical_stock' => 7])->assertSessionHas('success');

        $this->assertEqualsWithDelta(7, $this->stockOf($p), 0.0005);

        $m = StockMovement::where('product_id', $p->id)->sole();
        $this->assertSame('adjustment', $m->type);
        $this->assertEqualsWithDelta(3, (float) $m->quantity, 0.0005, 'selalu nilai absolut');
        $this->assertEqualsWithDelta(10, (float) $m->stock_before, 0.0005);
        $this->assertEqualsWithDelta(7, (float) $m->stock_after, 0.0005);
        $this->assertSame('Hasil stok opname (stok fisik lebih sedikit 3 dari catatan sistem)', $m->note);
    }

    public function test_opname_keterangan_kustom_dipakai_dan_diberi_akhiran_selisih(): void
    {
        $p = $this->produk(10);

        $this->opname($p, ['physical_stock' => 9, 'note' => 'Hitung ulang rak A']);

        $this->assertSame(
            'Hitung ulang rak A (stok fisik lebih sedikit 1 dari catatan sistem)',
            StockMovement::where('product_id', $p->id)->sole()->note
        );
    }

    /**
     * Regresi: `note` bersifat nullable. Field yang TIDAK DIKIRIM sama sekali
     * dulu memicu ErrorException "Undefined array key" (HTTP 500) karena
     * $validated tidak memuat key-nya. Form browser selalu mengirim textarea
     * (kosong -> null), jadi hanya klien lain / request termodifikasi yang kena.
     */
    public function test_opname_tanpa_keterangan_dalam_bentuk_apa_pun_memakai_keterangan_default_bukan_error_500(): void
    {
        $bentuk = [
            'field tidak dikirim' => ['physical_stock' => 12],
            'field null' => ['physical_stock' => 12, 'note' => null],
            'field string kosong' => ['physical_stock' => 12, 'note' => ''],
        ];

        foreach ($bentuk as $nama => $input) {
            $p = $this->produk(10);

            $this->opname($p, $input)->assertSessionHas('success');

            $this->assertSame(
                'Hasil stok opname (stok fisik lebih banyak 2 dari catatan sistem)',
                StockMovement::where('product_id', $p->id)->sole()->note,
                "bentuk input: {$nama}"
            );
        }
    }

    public function test_opname_stok_fisik_sama_dengan_sistem_tidak_mencatat_movement_apa_pun(): void
    {
        $p = $this->produk(10);

        $this->opname($p, ['physical_stock' => 10])
            ->assertSessionHas('success', fn ($pesan) => str_contains($pesan, 'sudah sesuai'));

        $this->assertSame(0, $this->jumlahMovement($p));
        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
    }

    public function test_opname_pecahan_yang_sama_tidak_menciptakan_movement_hantu(): void
    {
        $p = $this->produk(0, ['tracking_mode' => 'weight']);
        StockMovement::record(Product::find($p->id), 'in', 0.1, $this->gudang->id);
        StockMovement::record(Product::find($p->id), 'in', 0.2, $this->gudang->id); // 0.1 + 0.2 di float = 0.30000000000000004

        $this->opname($p, ['physical_stock' => 0.3])
            ->assertSessionHas('success', fn ($pesan) => str_contains($pesan, 'sudah sesuai'));

        $this->assertSame(2, $this->jumlahMovement($p), 'hanya 2 movement masuk; opname tidak boleh menambah');
    }

    public function test_opname_ke_nol_diizinkan_karena_barang_bisa_habis_secara_fisik(): void
    {
        $p = $this->produk(5);

        $this->opname($p, ['physical_stock' => 0])->assertSessionHas('success');

        $this->assertEqualsWithDelta(0, $this->stockOf($p), 0.0005);
        $this->assertEqualsWithDelta(5, (float) StockMovement::where('product_id', $p->id)->sole()->quantity, 0.0005);
    }

    public function test_opname_menerima_selisih_pecahan(): void
    {
        $curah = $this->produk(10.25, ['tracking_mode' => 'weight']);

        $this->opname($curah, ['physical_stock' => 10.5])->assertSessionHas('success');

        $this->assertEqualsWithDelta(10.5, $this->stockOf($curah), 0.0005);
        $this->assertEqualsWithDelta(0.25, (float) StockMovement::where('product_id', $curah->id)->sole()->quantity, 0.0005);
    }

    public function test_opname_input_tidak_valid_ditolak_dan_tidak_ada_yang_berubah(): void
    {
        $p = $this->produk(10);

        foreach ([-1, 'abc', '', null] as $fisik) {
            $this->opname($p, ['physical_stock' => $fisik])->assertSessionHasErrors('physical_stock');
        }
        $this->opname($p, ['physical_stock' => 5, 'note' => str_repeat('a', 501)])->assertSessionHasErrors('note');

        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_opname_tidak_mengubah_average_cost(): void
    {
        $p = $this->produk(10, ['average_cost' => 1234]);

        $this->opname($p, ['physical_stock' => 20])->assertSessionHas('success');

        $this->assertSame(1234, (int) Product::find($p->id)->average_cost);
    }

    /**
     * Inti alasan opname memakai lock: selisih HARUS dihitung dari stok
     * TERKINI, bukan dari instance yang di-resolve sebelum transaksi.
     * Gudang buka form saat stok 10; sebelum submit, terjual 3 (stok 7);
     * fisik yang dihitung 9.
     *   benar : selisih = 9 - 7 = +2  -> stok akhir 9 (= angka fisik)
     *   basi  : selisih = 9 - 10 = -1 -> stok akhir 6 (SALAH, bukan 9)
     */
    public function test_opname_menghitung_selisih_dari_stok_terkini_bukan_dari_produk_basi(): void
    {
        $basi = $this->produk(10);
        StockMovement::record(Product::find($basi->id), 'sale', 3.0, $this->gudang->id, 'INV-RACE');

        $this->panggilDenganProdukBasi('storeAdjustment', $basi, ['physical_stock' => 9]);

        $this->assertEqualsWithDelta(9, $this->stockOf($basi), 0.0005, 'stok akhir harus persis sama dengan angka fisik');

        $adj = StockMovement::where('product_id', $basi->id)->where('type', 'adjustment')->sole();
        $this->assertEqualsWithDelta(7, (float) $adj->stock_before, 0.0005);
        $this->assertEqualsWithDelta(2, (float) $adj->quantity, 0.0005);
    }

    // =====================================================================
    // Daftar stok (index)
    // =====================================================================

    public function test_daftar_stok_hanya_produk_aktif_terurut_nama_dan_dipaginasi_15(): void
    {
        // Dibuat TERBALIK (17 -> 1) supaya urutan id berlawanan dengan urutan nama.
        foreach (range(17, 1) as $i) {
            $this->produk(10, ['name' => sprintf('Barang %02d', $i)]);
        }
        $this->produk(10, ['name' => 'Barang Nonaktif', 'is_active' => false]);

        $hal1 = $this->actingAs($this->gudang)->get(route('gudang.stok.index'))->assertOk()->viewData('products');
        $hal2 = $this->actingAs($this->gudang)->get(route('gudang.stok.index', ['page' => 2]))->viewData('products');

        $this->assertSame(17, $hal1->total(), 'produk nonaktif tidak ikut');
        $this->assertCount(15, $hal1);
        $this->assertSame('Barang 01', $hal1->first()->name);
        $this->assertSame('Barang 15', $hal1->last()->name);
        $this->assertSame(['Barang 16', 'Barang 17'], $hal2->pluck('name')->all());
    }

    public function test_daftar_stok_filter_pencarian_nama_dan_sku_serta_kategori(): void
    {
        $katA = $this->makeCategory('Minuman');
        $katB = $this->makeCategory('Makanan');
        $this->produk(10, ['name' => 'Teh Botol', 'sku' => 'MNM-001', 'category_id' => $katA->id]);
        $this->produk(10, ['name' => 'Kopi Sachet', 'sku' => 'MNM-002', 'category_id' => $katA->id]);
        $this->produk(10, ['name' => 'Roti Tawar', 'sku' => 'MKN-001', 'category_id' => $katB->id]);

        $cari = fn (array $q) => $this->actingAs($this->gudang)->get(route('gudang.stok.index', $q))
            ->assertOk()->viewData('products')->pluck('name')->all();

        $this->assertSame(['Teh Botol'], $cari(['search' => 'botol']));        // nama
        $this->assertSame(['Roti Tawar'], $cari(['search' => 'MKN-001']));     // sku
        $this->assertSame(['Kopi Sachet', 'Teh Botol'], $cari(['category_id' => $katA->id]));
        $this->assertSame(['Kopi Sachet'], $cari(['category_id' => $katA->id, 'search' => 'kopi'])); // AND
    }

    public function test_filter_stok_menipis_memakai_batas_sama_dengan_dan_mengabaikan_produk_nonaktif(): void
    {
        $this->produk(5, ['name' => 'Pas Batas', 'min_stock' => 5]);      // 5 <= 5  -> menipis
        $this->produk(0, ['name' => 'Habis', 'min_stock' => 5]);          // 0 <= 5  -> menipis
        $this->produk(6, ['name' => 'Aman', 'min_stock' => 5]);           // 6 > 5   -> aman
        $this->produk(0, ['name' => 'Nonaktif Habis', 'min_stock' => 5, 'is_active' => false]);

        $hasil = $this->actingAs($this->gudang)->get(route('gudang.stok.index', ['low_stock' => 1]))
            ->assertOk()->viewData('products')->pluck('name')->all();

        $this->assertSame(['Habis', 'Pas Batas'], $hasil);
    }

    // =====================================================================
    // Riwayat pergerakan stok (show)
    // =====================================================================

    public function test_riwayat_hanya_milik_produk_itu_terbaru_di_atas_dan_dipaginasi_20(): void
    {
        $p = $this->produk(0);
        $lain = $this->produk(0);

        foreach (range(1, 22) as $i) {
            StockMovement::record(Product::find($p->id), 'in', 1.0, $this->gudang->id, note: "masuk {$i}");
        }
        StockMovement::record(Product::find($lain->id), 'in', 5.0, $this->gudang->id, note: 'milik produk lain');

        $hal1 = $this->actingAs($this->gudang)->get(route('gudang.stok.show', $p))->assertOk()->viewData('movements');
        $hal2 = $this->actingAs($this->gudang)->get(route('gudang.stok.show', [$p, 'page' => 2]))->viewData('movements');

        $this->assertSame(22, $hal1->total(), 'movement produk lain tidak boleh bocor');
        $this->assertCount(20, $hal1);
        $this->assertCount(2, $hal2);
        $this->assertSame('masuk 22', $hal1->first()->note, 'terbaru paling atas');
        $this->assertSame('masuk 1', $hal2->last()->note);
    }

    public function test_riwayat_bisa_difilter_per_tipe(): void
    {
        $p = $this->produk(10);
        $this->keluar($p, ['quantity' => 1, 'note' => 'k1']);
        $this->mutasi($p, ['quantity' => 1, 'note' => 'm1']);
        $this->opname($p, ['physical_stock' => 20]);

        $tipe = fn (?string $t) => $this->actingAs($this->gudang)
            ->get(route('gudang.stok.show', [$p] + ($t ? ['type' => $t] : [])))
            ->assertOk()->viewData('movements')->pluck('type')->all();

        $this->assertEqualsCanonicalizing(['out', 'mutation', 'adjustment'], $tipe(null));
        $this->assertSame(['out'], $tipe('out'));
        $this->assertSame(['mutation'], $tipe('mutation'));
        $this->assertSame(['adjustment'], $tipe('adjustment'));
    }

    public function test_riwayat_produk_nonaktif_tetap_bisa_dibuka_untuk_penelusuran(): void
    {
        $p = $this->produk(0, ['is_active' => false]);
        StockMovement::record(Product::find($p->id), 'in', 3.0, $this->gudang->id, note: 'sebelum dinonaktifkan');

        $this->actingAs($this->gudang)->get(route('gudang.stok.show', $p))
            ->assertOk()->assertSee('sebelum dinonaktifkan');
    }

    // =====================================================================
    // Akses
    // =====================================================================

    public function test_admin_punya_akses_penuh_ke_stok(): void
    {
        $admin = User::factory()->admin()->create();
        $p = $this->produk(10);

        $this->actingAs($admin)->get(route('gudang.stok.index'))->assertOk();
        $this->actingAs($admin)->get(route('gudang.stok.show', $p))->assertOk();
        $this->keluar($p, ['quantity' => 1, 'note' => 'oleh admin'], $admin)->assertSessionHas('success');

        $this->assertSame($admin->id, StockMovement::where('product_id', $p->id)->sole()->user_id);
    }

    public function test_kasir_ditolak_di_semua_endpoint_stok_dan_stok_tidak_berubah(): void
    {
        $kasir = User::factory()->kasir()->create();
        $p = $this->produk(10);

        $this->actingAs($kasir)->get(route('gudang.stok.index'))->assertForbidden();
        $this->actingAs($kasir)->get(route('gudang.stok.show', $p))->assertForbidden();
        $this->keluar($p, ['quantity' => 1, 'note' => 'x'], $kasir)->assertForbidden();
        $this->mutasi($p, ['quantity' => 1, 'note' => 'x'], $kasir)->assertForbidden();
        $this->opname($p, ['physical_stock' => 99], $kasir)->assertForbidden();

        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(0, $this->jumlahMovement($p));
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $p = $this->produk(10);

        $this->get(route('gudang.stok.index'))->assertRedirect(route('login'));
        $this->get(route('gudang.stok.show', $p))->assertRedirect(route('login'));
        $this->post(route('gudang.stok.keluar', $p), [])->assertRedirect(route('login'));
        $this->post(route('gudang.stok.mutasi', $p), [])->assertRedirect(route('login'));
        $this->post(route('gudang.stok.opname', $p), [])->assertRedirect(route('login'));

        $this->assertSame(0, $this->jumlahMovement($p));
    }
}