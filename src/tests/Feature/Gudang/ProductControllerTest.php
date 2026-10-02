<?php

namespace Tests\Feature\Gudang;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian CRUD Produk, §5.2 spesifikasi) — korektivitas
 * Gudang/ProductController + Product::generateSku().
 *
 * Payload meniru PERSIS form browser (_form.blade.php): array `units[i]`
 * dengan kolom `relative_qty` ("isi ke satuan tepat di bawahnya", BUKAN
 * langsung ke satuan dasar), baris PALING BAWAH otomatis jadi satuan dasar,
 * dan penanda satuan beli lewat `is_purchase_unit_index_{form_id}`.
 *
 * Bug yang ditemukan lewat test ini (semuanya punya test regresi bernama
 * eksplisit di bawah): nama satuan kembar -> HTTP 500; SKU auto setelah SKU
 * manual "PRD-xxx" non-angka -> HTTP 500; foto lama terhapus padahal update
 * ditolak; biaya awal negatif -> average_cost negatif; harga jual negatif/
 * teks lolos diam-diam.
 */
class ProductControllerTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $gudang;

    private int $categoryId;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hindariIdUserPertama();
        $this->gudang = User::factory()->gudang()->create();
        $this->categoryId = $this->makeCategory('Sembako')->id;
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        // Dipakai test "gagal di tengah" yang memasang listener Product::creating.
        Product::flushEventListeners();

        parent::tearDown();
    }

    // =====================================================================
    // Helper
    // =====================================================================

    private function fileAsli(string $namaClient, string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'prod-img-');
        file_put_contents($path, $isi);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $namaClient, null, null, true);
    }

    private function fotoPng(string $nama = 'foto.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return $this->fileAsli($nama, $png);
    }

    /** 1 dus = 24 renceng, 1 renceng = 12 sachet (=> dus 288 sachet); sachet = satuan dasar. */
    private function barisDefault(): array
    {
        return [
            ['unit_name' => 'dus', 'relative_qty' => 24, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'renceng', 'relative_qty' => 12, 'selling_price' => 11000, 'barcode' => ''],
            ['unit_name' => 'sachet', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ];
    }

    private function payloadBaru(array $o = []): array
    {
        return array_merge([
            'form_id' => 'tambah',
            'is_purchase_unit_index_tambah' => 0,
            'category_id' => $this->categoryId,
            'tracking_mode' => 'unit',
            'name' => 'Kopi Sachet',
            'sku' => '',
            'min_stock' => 5,
            'description' => '',
            'is_active' => 1,
            'allow_fractional_sale' => 0,
            'units' => $this->barisDefault(),
        ], $o);
    }

    private function simpan(array $o = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->post(route('gudang.produk.store'), $this->payloadBaru($o));
    }

    /** Produk lewat alur sungguhan (store) supaya fixture identik dengan data produksi. */
    private function produkLewatForm(array $o = []): Product
    {
        $this->simpan($o)->assertSessionHasNoErrors();

        return Product::latest('id')->firstOrFail();
    }

    /** Baris form edit dari produk yang sudah ada (id dipertahankan, isi dihitung balik dari rasio absolut). */
    private function barisDari(Product $p): array
    {
        $units = $p->units()->get()->values(); // terurut konversi terbesar -> terkecil
        $rows = [];

        foreach ($units as $i => $u) {
            $bawah = $units[$i + 1] ?? null;
            $rows[] = [
                'id' => $u->id,
                'unit_name' => $u->unit_name,
                'relative_qty' => $bawah ? (float) $u->conversion_to_base / (float) $bawah->conversion_to_base : '',
                'selling_price' => $u->selling_price ?? '',
                'barcode' => $u->barcode ?? '',
            ];
        }

        return $rows;
    }

    private function ubah(Product $p, array $o = [], ?array $rows = null, ?User $as = null)
    {
        $rows ??= $this->barisDari($p);
        $formId = "edit-{$p->id}";
        $indeksBeli = 0;
        foreach ($p->units()->get()->values() as $i => $u) {
            if ($u->is_purchase_unit) {
                $indeksBeli = $i;
            }
        }

        return $this->actingAs($as ?? $this->gudang)->put(route('gudang.produk.update', $p), array_merge([
            'form_id' => $formId,
            "is_purchase_unit_index_{$formId}" => $indeksBeli,
            'category_id' => $p->category_id,
            'tracking_mode' => $p->tracking_mode,
            'name' => $p->name,
            'sku' => $p->sku,
            'min_stock' => 5,
            'is_active' => 1,
            'allow_fractional_sale' => 0,
            'units' => $rows,
        ], $o));
    }

    private function hapus(Product $p, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gudang)->delete(route('gudang.produk.destroy', $p));
    }

    private function unitTersimpan(Product $p): array
    {
        return $p->units()->get()->mapWithKeys(fn ($u) => [$u->unit_name => [
            'conv' => (float) $u->conversion_to_base,
            'base' => (bool) $u->is_base_unit,
            'beli' => (bool) $u->is_purchase_unit,
            'harga' => $u->selling_price,
            'barcode' => $u->barcode,
        ]])->all();
    }

    private function pasangFotoLama(Product $p, string $path = 'products/lama.png'): void
    {
        Storage::disk('public')->put($path, 'isi-foto-lama');
        $p->forceFill(['image' => $path])->save();
    }

    // =====================================================================
    // Store — produk multi-satuan
    // =====================================================================

    public function test_tambah_produk_menyimpan_konversi_bertingkat_satuan_dasar_dan_satuan_beli(): void
    {
        $this->simpan(['name' => 'Kopi Sachet', 'min_stock' => 7, 'description' => 'Kopi instan'])
            ->assertRedirect()->assertSessionHas('success');

        $p = Product::sole();
        $this->assertSame('Kopi Sachet', $p->name);
        $this->assertSame('unit', $p->tracking_mode);
        $this->assertSame($this->categoryId, $p->category_id);
        $this->assertEqualsWithDelta(7, (float) $p->min_stock, 0.0005);
        $this->assertSame('Kopi instan', $p->description);
        $this->assertEqualsWithDelta(0, (float) $p->stock, 0.0005);

        // 1 dus = 24 renceng, 1 renceng = 12 sachet  =>  dus = 24 x 12 = 288 sachet.
        $this->assertSame([
            'dus' => ['conv' => 288.0, 'base' => false, 'beli' => true, 'harga' => null, 'barcode' => null],
            'renceng' => ['conv' => 12.0, 'base' => false, 'beli' => false, 'harga' => 11000, 'barcode' => null],
            'sachet' => ['conv' => 1.0, 'base' => true, 'beli' => false, 'harga' => 1000, 'barcode' => null],
        ], $this->unitTersimpan($p));

        $this->assertSame(['dus', 'renceng', 'sachet'], $p->units()->get()->pluck('unit_name')->all());
        $this->assertSame([0, 1, 2], ProductUnit::where('product_id', $p->id)->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_redirect_ke_halaman_produk_yang_baru_dibuat(): void
    {
        $res = $this->simpan();

        $res->assertRedirect(route('gudang.produk.show', Product::sole()));
    }

    public function test_satuan_beli_mengikuti_indeks_radio_dan_hanya_satu(): void
    {
        $this->simpan(['is_purchase_unit_index_tambah' => 1])->assertSessionHas('success');

        $unit = $this->unitTersimpan(Product::sole());
        $this->assertSame(['dus' => false, 'renceng' => true, 'sachet' => false], array_map(fn ($u) => $u['beli'], $unit));
    }

    public function test_baris_satuan_kosong_dilewati_dan_baris_terisi_paling_bawah_jadi_satuan_dasar(): void
    {
        $this->simpan(['units' => [
            ['unit_name' => 'karton', 'relative_qty' => 10, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 2500, 'barcode' => ''],
            ['unit_name' => '', 'relative_qty' => '', 'selling_price' => '', 'barcode' => ''],   // baris kosong sisa
            ['unit_name' => '   ', 'relative_qty' => '', 'selling_price' => '', 'barcode' => ''], // spasi saja
        ]])->assertSessionHas('success');

        $unit = $this->unitTersimpan(Product::sole());
        $this->assertSame(['karton', 'pcs'], array_keys($unit));
        $this->assertSame(10.0, $unit['karton']['conv']);
        $this->assertTrue($unit['pcs']['base']);
        $this->assertSame(1.0, $unit['pcs']['conv']);
    }

    public function test_satu_satuan_saja_otomatis_jadi_satuan_dasar(): void
    {
        $this->simpan(['units' => [
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 3000, 'barcode' => ''],
        ]])->assertSessionHas('success');

        $unit = $this->unitTersimpan(Product::sole());
        $this->assertSame(['pcs' => ['conv' => 1.0, 'base' => true, 'beli' => true, 'harga' => 3000, 'barcode' => null]], $unit);
    }

    public function test_produk_curah_mode_weight_dengan_penjualan_pecahan(): void
    {
        $this->simpan([
            'name' => 'Beras Curah',
            'tracking_mode' => 'weight',
            'allow_fractional_sale' => 1,
            'units' => [
                ['unit_name' => 'karung', 'relative_qty' => 25, 'selling_price' => '', 'barcode' => ''],
                ['unit_name' => 'kg', 'relative_qty' => '', 'selling_price' => 14000, 'barcode' => ''],
            ],
        ])->assertSessionHas('success');

        $p = Product::sole();
        $this->assertSame('weight', $p->tracking_mode);
        $this->assertTrue($p->allow_fractional_sale);
        $this->assertSame(25.0, $this->unitTersimpan($p)['karung']['conv']);
    }

    public function test_flag_is_active_dan_allow_fractional_tersimpan_sesuai_checkbox(): void
    {
        // Regresi: dulu is_active diberi default(true) sehingga TIDAK PERNAH bisa tersimpan false.
        $this->simpan(['name' => 'Nonaktif', 'is_active' => 0, 'allow_fractional_sale' => 0]);
        $this->simpan(['name' => 'Aktif Pecahan', 'is_active' => 1, 'allow_fractional_sale' => 1]);

        $nonaktif = Product::where('name', 'Nonaktif')->sole();
        $aktif = Product::where('name', 'Aktif Pecahan')->sole();

        $this->assertFalse($nonaktif->is_active);
        $this->assertFalse($nonaktif->allow_fractional_sale);
        $this->assertTrue($aktif->is_active);
        $this->assertTrue($aktif->allow_fractional_sale);
    }

    // =====================================================================
    // Store — validasi satuan
    // =====================================================================

    public function test_produk_tanpa_satuan_sama_sekali_ditolak(): void
    {
        // Pesan sengaja diperiksa: dua cek berbeda (array kosong vs semua baris kosong)
        // sama-sama menolak dengan key 'units', jadi tanpa pesan salah satunya bisa hilang tak terdeteksi.
        $kasus = [
            'array satuan kosong' => [[], 'Minimal harus ada 1 satuan produk.'],
            'semua baris kosong' => [[['unit_name' => '', 'relative_qty' => '', 'selling_price' => '', 'barcode' => '']], 'Minimal harus ada 1 satuan produk yang valid.'],
        ];

        foreach ($kasus as $nama => [$units, $pesan]) {
            $this->simpan(['units' => $units])->assertSessionHasErrors('units');
            $this->assertSame($pesan, session('errors')->first('units'), $nama);
        }

        $this->assertSame(0, Product::count());
    }

    public function test_kolom_isi_wajib_angka_positif_untuk_satuan_selain_dasar(): void
    {
        // Nol/negatif/kosong harus ditolak oleh cek "harus lebih besar dari 0" (bukan oleh
        // cek batas minimum 0,001 yang tumpang-tindih) — pesan diperiksa supaya keduanya terjaga.
        foreach (['', 0, -2] as $isi) {
            $this->simpan(['units' => [
                ['unit_name' => 'dus', 'relative_qty' => $isi, 'selling_price' => '', 'barcode' => ''],
                ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
            ]])->assertSessionHasErrors('units');
            $this->assertStringContainsString('harus lebih besar dari 0', session('errors')->first('units'), "isi=" . var_export($isi, true));
        }

        foreach (['abc', '12abc'] as $isi) {
            $this->simpan(['units' => [
                ['unit_name' => 'dus', 'relative_qty' => $isi, 'selling_price' => '', 'barcode' => ''],
                ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
            ]])->assertSessionHasErrors('units');
            $this->assertStringContainsString('harus berupa angka', session('errors')->first('units'), "isi={$isi}");
        }

        $this->assertSame(0, Product::count());
    }

    public function test_isi_terlalu_kecil_atau_hasil_konversi_meluap_ditolak(): void
    {
        // < 0,001 akan dibulatkan decimal(12,3) menjadi 0,000 (pembagian nol / stok tidak terpotong).
        $this->simpan(['units' => [
            ['unit_name' => 'box', 'relative_qty' => 0.0004, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHasErrors('units');

        // 100.000 x 100.000 = 10 miliar > kapasitas decimal(12,3) (999.999.999,999).
        $this->simpan(['units' => [
            ['unit_name' => 'pallet', 'relative_qty' => 100000, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'karton', 'relative_qty' => 100000, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHasErrors('units');

        // Antara batas (999.999.999,999) dan 10 miliar: 100.000 x 50.000 = 5 miliar -> ditolak.
        $this->simpan(['units' => [
            ['unit_name' => 'pallet', 'relative_qty' => 100000, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'karton', 'relative_qty' => 50000, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHasErrors('units');

        $this->assertSame(0, Product::count());

        // Tepat di batas atas yang sah (999.999.999) diterima.
        $this->simpan(['name' => 'Di Batas Atas', 'units' => [
            ['unit_name' => 'kontainer', 'relative_qty' => 999999999, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHas('success');
        $this->assertSame(999999999.0, $this->unitTersimpan(Product::where('name', 'Di Batas Atas')->sole())['kontainer']['conv']);

        // Batas bawah yang sah (0,001) tetap diterima.
        $this->simpan(['units' => [
            ['unit_name' => 'gram', 'relative_qty' => 0.001, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'mg', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHas('success');
    }

    /**
     * Regresi QA-004: nama satuan kembar dulu langsung HTTP 500
     * (UniqueConstraintViolationException dari unique(product_id, unit_name)).
     * Dibandingkan tanpa peduli huruf besar/kecil & spasi pinggir, karena
     * collation MySQL case-insensitive menganggap "Dus" = "dus".
     */
    public function test_nama_satuan_kembar_ditolak_dengan_pesan_bukan_error_500(): void
    {
        $kembar = [
            'persis sama' => ['pcs', 'pcs'],
            'beda huruf besar' => ['Pcs', 'pCS'],
            'spasi pinggir' => [' pcs ', 'pcs'],
        ];

        foreach ($kembar as $nama => [$a, $b]) {
            $res = $this->simpan(['units' => [
                ['unit_name' => $a, 'relative_qty' => 2, 'selling_price' => '', 'barcode' => ''],
                ['unit_name' => $b, 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
            ]]);

            $res->assertSessionHasErrors('units');
            $this->assertStringContainsString('dipakai lebih dari sekali', session('errors')->first('units'), $nama);
        }

        $this->assertSame(0, Product::count());
    }

    /** Regresi QA-004: harga jual "-500" tersimpan, "abc" diam-diam jadi 0. */
    public function test_harga_jual_negatif_atau_bukan_angka_ditolak(): void
    {
        foreach (['-500', 'abc', '12abc'] as $harga) {
            $this->simpan(['units' => [
                ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => $harga, 'barcode' => ''],
            ]])->assertSessionHasErrors('units');
        }

        $this->assertSame(0, Product::count());
    }

    public function test_harga_jual_kosong_berarti_satuan_tidak_dijual_bukan_nol(): void
    {
        $this->simpan(['units' => [
            ['unit_name' => 'dus', 'relative_qty' => 10, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1500, 'barcode' => ''],
        ]]);

        $unit = $this->unitTersimpan(Product::sole());
        $this->assertNull($unit['dus']['harga']);
        $this->assertSame(1500, $unit['pcs']['harga']);
    }

    // =====================================================================
    // Store — barcode
    // =====================================================================

    public function test_barcode_di_trim_dan_kosong_disimpan_null(): void
    {
        $this->simpan(['units' => [
            ['unit_name' => 'dus', 'relative_qty' => 10, 'selling_price' => '', 'barcode' => '  8991234500011  '],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]])->assertSessionHas('success');

        $unit = $this->unitTersimpan(Product::sole());
        $this->assertSame('8991234500011', $unit['dus']['barcode']);
        $this->assertNull($unit['pcs']['barcode']);
    }

    public function test_banyak_produk_boleh_sama_sama_tanpa_barcode(): void
    {
        $this->simpan(['name' => 'A', 'units' => [['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1, 'barcode' => '']]])->assertSessionHas('success');
        $this->simpan(['name' => 'B', 'units' => [['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1, 'barcode' => '']]])->assertSessionHas('success');

        $this->assertSame(2, Product::count());
    }

    public function test_barcode_kembar_dalam_satu_form_ditolak(): void
    {
        $this->simpan(['units' => [
            ['unit_name' => 'dus', 'relative_qty' => 10, 'selling_price' => '', 'barcode' => '111'],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => '111'],
        ]])->assertSessionHasErrors('units');

        $this->assertSame(0, Product::count());
    }

    public function test_barcode_yang_sudah_dipakai_produk_lain_ditolak_dengan_menyebut_kodenya(): void
    {
        $this->simpan(['name' => 'Produk Lama', 'units' => [
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => '8990001'],
        ]])->assertSessionHas('success');

        $res = $this->simpan(['name' => 'Produk Baru', 'units' => [
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => '8990001'],
        ]]);

        $res->assertSessionHasErrors('units');
        $this->assertStringContainsString('8990001', session('errors')->first('units'));
        $this->assertSame(1, Product::count());
    }

    // =====================================================================
    // Store — SKU
    // =====================================================================

    public function test_sku_dikosongkan_digenerate_berurutan_dan_sku_manual_dipertahankan(): void
    {
        $this->simpan(['name' => 'A']);
        $this->simpan(['name' => 'B']);
        $this->simpan(['name' => 'C', 'sku' => 'MANUAL-77']);

        $this->assertSame('PRD-0001', Product::where('name', 'A')->value('sku'));
        $this->assertSame('PRD-0002', Product::where('name', 'B')->value('sku'));
        $this->assertSame('MANUAL-77', Product::where('name', 'C')->value('sku'));
    }

    public function test_sku_manual_yang_sudah_dipakai_ditolak(): void
    {
        $this->simpan(['name' => 'A', 'sku' => 'KOPI-1'])->assertSessionHas('success');

        $this->simpan(['name' => 'B', 'sku' => 'KOPI-1'])->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::count());
    }

    /**
     * Regresi QA-004: SKU auto sesudah SKU manual berawalan "PRD-" tapi bukan
     * angka dulu menghasilkan kandidat yang menabrak SKU existing, dan 3x
     * percobaan ulang menghasilkan kandidat SAMA -> HTTP 500.
     */
    public function test_sku_auto_setelah_sku_manual_berawalan_prd_non_angka_tidak_menabrak(): void
    {
        $this->simpan(['name' => 'Auto 1']);                               // PRD-0001
        $this->simpan(['name' => 'Manual', 'sku' => 'PRD-ABC']);           // id terakhir = non-angka
        $res = $this->simpan(['name' => 'Auto 2']);                        // dulu: kandidat "PRD-0001" -> 500

        $res->assertSessionHas('success');
        $this->assertSame('PRD-0002', Product::where('name', 'Auto 2')->value('sku'));
    }

    public function test_sku_auto_melewati_nomor_yang_sudah_dipakai_sku_manual(): void
    {
        $this->simpan(['name' => 'M1', 'sku' => 'PRD-0006']);
        $this->simpan(['name' => 'M2', 'sku' => 'PRD-0005']);              // id terakhir = 0005 -> kandidat 0006 (sudah ada)

        $this->simpan(['name' => 'Auto'])->assertSessionHas('success');

        $this->assertSame('PRD-0007', Product::where('name', 'Auto')->value('sku'));
    }

    // =====================================================================
    // Store — validasi field inti
    // =====================================================================

    public function test_field_inti_tidak_valid_ditolak_dan_tidak_ada_produk_dibuat(): void
    {
        $kasus = [
            'nama kosong' => [['name' => ''], 'name'],
            'nama > 255' => [['name' => str_repeat('a', 256)], 'name'],
            'kategori kosong' => [['category_id' => ''], 'category_id'],
            'kategori tidak ada' => [['category_id' => 99999], 'category_id'],
            'tracking_mode asing' => [['tracking_mode' => 'liquid'], 'tracking_mode'],
            'min_stock kosong' => [['min_stock' => ''], 'min_stock'],
            'min_stock negatif' => [['min_stock' => -1], 'min_stock'],
            'min_stock bukan angka' => [['min_stock' => 'banyak'], 'min_stock'],
            'deskripsi > 1000' => [['description' => str_repeat('a', 1001)], 'description'],
            'sku > 100' => [['sku' => str_repeat('a', 101)], 'sku'],
        ];

        foreach ($kasus as $nama => [$override, $field]) {
            try {
                $this->simpan($override)->assertSessionHasErrors($field);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->fail("Kasus '{$nama}' seharusnya ditolak dengan error pada '{$field}': " . $e->getMessage());
            }
        }

        $this->assertSame(0, Product::count());
    }

    // =====================================================================
    // Store — stok awal
    // =====================================================================

    public function test_stok_awal_masuk_dalam_satuan_dasar_dengan_movement_dan_average_cost_dari_biaya_awal(): void
    {
        $this->simpan(['initial_stock' => 50, 'initial_cost' => 1000])->assertSessionHas('success');

        $p = Product::sole();

        // 50 = 50 SACHET (satuan dasar), BUKAN 50 dus (= 14.400 sachet).
        $this->assertEqualsWithDelta(50, (float) $p->stock, 0.0005);
        $this->assertSame(1000, (int) $p->average_cost);

        $m = StockMovement::where('product_id', $p->id)->sole();
        $this->assertSame('in', $m->type);
        $this->assertEqualsWithDelta(50, (float) $m->quantity, 0.0005);
        $this->assertEqualsWithDelta(0, (float) $m->stock_before, 0.0005);
        $this->assertEqualsWithDelta(50, (float) $m->stock_after, 0.0005);
        $this->assertSame(1000, (int) $m->unit_cost);
        $this->assertSame('Stok awal saat produk pertama kali dibuat', $m->note);
        $this->assertSame($this->gudang->id, $m->user_id, 'stok awal tercatat atas nama user yang membuat produk');
    }

    public function test_stok_awal_tanpa_biaya_awal_tidak_menyentuh_average_cost(): void
    {
        $this->simpan(['initial_stock' => 12])->assertSessionHas('success');

        $p = Product::sole();
        $this->assertEqualsWithDelta(12, (float) $p->stock, 0.0005);
        $this->assertSame(0, (int) $p->average_cost);
        $this->assertNull(StockMovement::where('product_id', $p->id)->sole()->unit_cost);
    }

    public function test_tanpa_stok_awal_atau_nol_tidak_membuat_movement(): void
    {
        foreach ([[], ['initial_stock' => 0], ['initial_stock' => '']] as $i => $o) {
            $this->simpan(array_merge(['name' => "P{$i}"], $o))->assertSessionHas('success');
        }

        $this->assertSame(3, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stok_awal_pecahan_untuk_barang_curah(): void
    {
        $this->simpan([
            'tracking_mode' => 'weight',
            'initial_stock' => 2.5,
            'units' => [['unit_name' => 'kg', 'relative_qty' => '', 'selling_price' => 14000, 'barcode' => '']],
        ])->assertSessionHas('success');

        $this->assertEqualsWithDelta(2.5, (float) Product::sole()->stock, 0.0005);
    }

    /** Regresi QA-004: stok awal "abc"/negatif dulu diam-diam jadi 0 dengan pesan sukses. */
    public function test_stok_awal_tidak_valid_ditolak_bukan_diam_diam_menjadi_nol(): void
    {
        foreach (['abc', -5, '1e12', 1000000000] as $stok) {
            $this->simpan(['initial_stock' => $stok])->assertSessionHasErrors('initial_stock');
        }

        $this->assertSame(0, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    /** Regresi QA-004: biaya awal -1000 dulu menghasilkan average_cost = -1000. */
    public function test_biaya_awal_negatif_atau_bukan_angka_ditolak(): void
    {
        foreach ([-1000, 'abc'] as $biaya) {
            $this->simpan(['initial_stock' => 10, 'initial_cost' => $biaya])->assertSessionHasErrors('initial_cost');
        }

        $this->assertSame(0, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    // =====================================================================
    // Store — foto produk
    // =====================================================================

    public function test_foto_valid_tersimpan_di_folder_products_dan_path_masuk_database(): void
    {
        $this->simpan(['image' => $this->fotoPng()])->assertSessionHas('success');

        $p = Product::sole();
        $this->assertNotNull($p->image);
        $this->assertStringStartsWith('products/', $p->image);
        Storage::disk('public')->assertExists($p->image);
    }

    public function test_produk_tanpa_foto_boleh_dan_kolom_image_null(): void
    {
        $this->simpan()->assertSessionHas('success');

        $this->assertNull(Product::sole()->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_foto_di_luar_whitelist_atau_menyamar_ditolak_dan_tidak_ada_file_nyasar(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>';

        $percobaan = [
            UploadedFile::fake()->create('foto.gif', 10, 'image/gif'),
            UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf'),
            $this->fileAsli('foto.png', $svg),                      // nama .png, isi SVG berskrip
            $this->fileAsli('foto.png', '<?php system($_GET[1]); ?>'),
        ];

        foreach ($percobaan as $file) {
            $this->simpan(['image' => $file])->assertSessionHasErrors('image');
        }

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_foto_batas_ukuran_2048_kb(): void
    {
        $this->simpan(['image' => UploadedFile::fake()->create('besar.jpg', 2049, 'image/jpeg')])
            ->assertSessionHasErrors('image');
        $this->assertSame(0, Product::count());

        $this->simpan(['image' => UploadedFile::fake()->create('pas.jpg', 2048, 'image/jpeg')])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, Product::count());
    }

    public function test_pembuatan_produk_yang_gagal_di_tengah_tidak_meninggalkan_file_foto_yatim(): void
    {
        // Simulasi kegagalan DB SETELAH foto disimpan (mis. tabrakan SKU balapan).
        Product::creating(function () {
            throw new \RuntimeException('simulasi kegagalan database');
        });
        $this->withoutExceptionHandling();

        try {
            $this->simpan(['image' => $this->fotoPng()]);
            $this->fail('Seharusnya melempar exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulasi kegagalan database', $e->getMessage());
        }

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'foto yang sudah terlanjur disimpan harus dibersihkan');
    }

    // =====================================================================
    // Update — field inti
    // =====================================================================

    public function test_ubah_field_inti_produk(): void
    {
        $p = $this->produkLewatForm(['name' => 'Lama']);
        $kategoriBaru = $this->makeCategory('Minuman')->id;

        $this->ubah($p, [
            'name' => 'Baru',
            'category_id' => $kategoriBaru,
            'min_stock' => 9,
            'description' => 'Deskripsi baru',
            'is_active' => 0,
            'allow_fractional_sale' => 1,
        ])->assertSessionHas('success');

        $p->refresh();
        $this->assertSame('Baru', $p->name);
        $this->assertSame($kategoriBaru, $p->category_id);
        $this->assertEqualsWithDelta(9, (float) $p->min_stock, 0.0005);
        $this->assertSame('Deskripsi baru', $p->description);
        $this->assertFalse($p->is_active, 'checkbox tidak dicentang harus bisa menonaktifkan produk');
        $this->assertTrue($p->allow_fractional_sale);
    }

    public function test_sku_dikosongkan_saat_edit_tetap_memakai_sku_lama_dan_sku_sendiri_boleh_dikirim_ulang(): void
    {
        $p = $this->produkLewatForm(['name' => 'A', 'sku' => 'KOPI-1']);

        $this->ubah($p, ['sku' => ''])->assertSessionHas('success');
        $this->assertSame('KOPI-1', $p->fresh()->sku);

        $this->ubah($p, ['sku' => 'KOPI-1', 'name' => 'A2'])->assertSessionHasNoErrors();
        $this->assertSame('A2', $p->fresh()->name);
    }

    public function test_ubah_sku_menjadi_milik_produk_lain_ditolak(): void
    {
        $this->produkLewatForm(['name' => 'A', 'sku' => 'KOPI-1']);
        $b = $this->produkLewatForm(['name' => 'B', 'sku' => 'TEH-1']);

        $this->ubah($b, ['sku' => 'KOPI-1'])->assertSessionHasErrors('sku');

        $this->assertSame('TEH-1', $b->fresh()->sku);
    }

    public function test_tracking_mode_tidak_bisa_diubah_lewat_update(): void
    {
        $p = $this->produkLewatForm(['tracking_mode' => 'unit']);

        $this->ubah($p, ['tracking_mode' => 'weight'])->assertSessionHas('success');

        $this->assertSame('unit', $p->fresh()->tracking_mode);
    }

    public function test_stok_awal_diabaikan_saat_update_dan_stok_tidak_berubah(): void
    {
        $p = $this->produkLewatForm(['initial_stock' => 10]);

        $this->ubah($p, ['initial_stock' => 999, 'initial_cost' => 5000])->assertSessionHas('success');

        $this->assertEqualsWithDelta(10, $this->stockOf($p), 0.0005);
        $this->assertSame(1, StockMovement::where('product_id', $p->id)->count());
    }

    public function test_update_dengan_field_inti_tidak_valid_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $p = $this->produkLewatForm(['name' => 'Asli']);

        $this->ubah($p, ['name' => ''])->assertSessionHasErrors('name');
        $this->ubah($p, ['min_stock' => -3])->assertSessionHasErrors('min_stock');
        $this->ubah($p, ['category_id' => 99999])->assertSessionHasErrors('category_id');

        $this->assertSame('Asli', $p->fresh()->name);
    }

    // =====================================================================
    // Update — satuan
    // =====================================================================

    public function test_ubah_harga_jual_dan_barcode_satuan_mempertahankan_id_dan_konversi(): void
    {
        $p = $this->produkLewatForm();
        $idAwal = $p->units()->pluck('id', 'unit_name')->all();
        $rows = $this->barisDari($p);
        $rows[1]['selling_price'] = 12500;       // renceng
        $rows[2]['barcode'] = '8995550001';      // sachet

        $this->ubah($p, [], $rows)->assertSessionHas('success');

        $this->assertSame($idAwal, $p->units()->pluck('id', 'unit_name')->all(), 'id satuan tidak boleh berganti (FK PO/riwayat)');
        $unit = $this->unitTersimpan($p);
        $this->assertSame(12500, $unit['renceng']['harga']);
        $this->assertSame('8995550001', $unit['sachet']['barcode']);
        $this->assertSame(288.0, $unit['dus']['conv']);
    }

    public function test_rasio_satuan_yang_belum_dipakai_po_boleh_diubah_dan_dihitung_ulang_bertingkat(): void
    {
        $p = $this->produkLewatForm();
        $rows = $this->barisDari($p);
        $rows[0]['relative_qty'] = 10;           // dus = 10 renceng (dulu 24) -> 10 x 12 = 120 sachet

        $this->ubah($p, [], $rows)->assertSessionHas('success');

        $this->assertSame(120.0, $this->unitTersimpan($p)['dus']['conv']);
    }

    public function test_rasio_satuan_yang_sudah_dipakai_po_tidak_bisa_diubah_dan_seluruh_update_dibatalkan(): void
    {
        $p = $this->produkLewatForm(['name' => 'Nama Asli']);
        $this->makePurchaseOrder(['status' => 'ordered'], [['product' => $p->load('units')]]);

        $rows = $this->barisDari($p);
        $rows[0]['relative_qty'] = 99;           // mengubah rasio dus yang sudah dipakai PO

        $this->ubah($p, ['name' => 'Nama Diubah'], $rows)->assertSessionHasErrors('units');

        $this->assertSame(288.0, $this->unitTersimpan($p)['dus']['conv']);
        $this->assertSame('Nama Asli', $p->fresh()->name, 'transaksi harus rollback: nama produk ikut batal berubah');
    }

    public function test_satuan_yang_sudah_dipakai_po_tidak_bisa_dihapus_tapi_yang_belum_boleh(): void
    {
        $p = $this->produkLewatForm();
        $this->makePurchaseOrder(['status' => 'ordered'], [['product' => $p->load('units')]]); // memakai dus (satuan beli)

        // Hapus 'dus' (dipakai PO) -> ditolak.
        $tanpaDus = array_slice($this->barisDari($p), 1);
        $this->ubah($p, [], $tanpaDus)->assertSessionHasErrors('units');
        $this->assertArrayHasKey('dus', $this->unitTersimpan($p));

        // Hapus 'renceng' (belum dipakai PO) -> boleh. Baris dus tetap utuh: rasio dihitung ulang ke sachet.
        $rows = $this->barisDari($p);
        $rows = [$rows[0], $rows[2]];
        $rows[0]['relative_qty'] = 288;          // dus langsung = 288 sachet, rasio TETAP sama (aman terhadap kunci PO)
        $this->ubah($p, [], $rows)->assertSessionHas('success');

        $this->assertSame(['dus', 'sachet'], array_keys($this->unitTersimpan($p)));
    }

    public function test_tambah_satuan_baru_saat_edit_membuat_baris_baru_di_atas_satuan_dasar(): void
    {
        $p = $this->produkLewatForm(['units' => [
            ['unit_name' => 'dus', 'relative_qty' => 10, 'selling_price' => '', 'barcode' => ''],
            ['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1000, 'barcode' => ''],
        ]]);
        $idPcs = $p->units()->where('unit_name', 'pcs')->value('id');

        $rows = $this->barisDari($p);                                       // [dus, pcs]
        array_splice($rows, 1, 0, [['unit_name' => 'pak', 'relative_qty' => 5, 'selling_price' => 4500, 'barcode' => '']]);
        $rows[0]['relative_qty'] = 2;                                       // dus = 2 pak; pak = 5 pcs  => dus = 10 pcs (sama)

        $this->ubah($p, [], $rows)->assertSessionHas('success');

        $unit = $this->unitTersimpan($p);
        $this->assertSame(['dus', 'pak', 'pcs'], array_keys($unit));
        $this->assertSame(5.0, $unit['pak']['conv']);
        $this->assertSame(10.0, $unit['dus']['conv']);
        $this->assertSame($idPcs, $p->units()->where('is_base_unit', true)->value('id'), 'satuan dasar tetap baris yang sama');
    }

    public function test_satuan_dasar_tidak_bisa_diganti_setelah_produk_dibuat(): void
    {
        $p = $this->produkLewatForm();
        $sebelum = $this->unitTersimpan($p);

        // Urutan dibalik: baris paling bawah (calon satuan dasar) jadi 'dus' (bukan sachet lama).
        $rows = array_reverse($this->barisDari($p));
        $rows[0]['relative_qty'] = 1;
        $rows[1]['relative_qty'] = 1;
        $rows[2]['relative_qty'] = '';

        $res = $this->ubah($p, [], $rows);

        $res->assertSessionHasErrors('units');
        $this->assertStringContainsString('Satuan dasar', session('errors')->first('units'));
        $this->assertSame($sebelum, $this->unitTersimpan($p));
    }

    public function test_id_satuan_milik_produk_lain_tidak_pernah_menyentuh_produk_itu(): void
    {
        $a = $this->produkLewatForm(['name' => 'A']);
        $b = $this->produkLewatForm(['name' => 'B', 'units' => [
            ['unit_name' => 'botol', 'relative_qty' => '', 'selling_price' => 7000, 'barcode' => ''],
        ]]);
        $unitB = $b->units()->sole();

        $rows = $this->barisDari($a);
        $rows[1]['id'] = $unitB->id;             // form dimanipulasi: id satuan milik produk B
        $rows[1]['unit_name'] = 'diretas';
        $rows[1]['selling_price'] = 1;

        $this->ubah($a, [], $rows);

        $unitB->refresh();
        $this->assertSame($b->id, $unitB->product_id);
        $this->assertSame('botol', $unitB->unit_name);
        $this->assertSame(7000, $unitB->selling_price);
    }

    public function test_update_juga_menolak_nama_satuan_kembar_dan_harga_negatif(): void
    {
        $p = $this->produkLewatForm();
        $sebelum = $this->unitTersimpan($p);

        $kembar = $this->barisDari($p);
        $kembar[1]['unit_name'] = 'DUS';
        $this->ubah($p, [], $kembar)->assertSessionHasErrors('units');

        $negatif = $this->barisDari($p);
        $negatif[2]['selling_price'] = '-1';
        $this->ubah($p, [], $negatif)->assertSessionHasErrors('units');

        $this->assertSame($sebelum, $this->unitTersimpan($p));
    }

    public function test_barcode_milik_sendiri_boleh_dikirim_ulang_tapi_milik_produk_lain_ditolak(): void
    {
        $a = $this->produkLewatForm(['name' => 'A', 'units' => [['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1, 'barcode' => 'AAA-1']]]);
        $b = $this->produkLewatForm(['name' => 'B', 'units' => [['unit_name' => 'pcs', 'relative_qty' => '', 'selling_price' => 1, 'barcode' => 'BBB-1']]]);

        $this->ubah($a, ['name' => 'A2'])->assertSessionHasNoErrors();      // barcode AAA-1 sendiri dikirim ulang
        $this->assertSame('A2', $a->fresh()->name);

        $rows = $this->barisDari($a);
        $rows[0]['barcode'] = 'BBB-1';                                      // milik B
        $this->ubah($a, [], $rows)->assertSessionHasErrors('units');

        $this->assertSame('AAA-1', $a->units()->sole()->barcode);
        $this->assertSame('BBB-1', $b->units()->sole()->barcode);
    }

    // =====================================================================
    // Update — foto
    // =====================================================================

    public function test_ganti_foto_menyimpan_yang_baru_dan_menghapus_yang_lama_setelah_sukses(): void
    {
        $p = $this->produkLewatForm();
        $this->pasangFotoLama($p);

        $this->ubah($p, ['image' => $this->fotoPng()])->assertSessionHas('success');

        $p->refresh();
        $this->assertNotSame('products/lama.png', $p->image);
        Storage::disk('public')->assertExists($p->image);
        Storage::disk('public')->assertMissing('products/lama.png');
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_update_tanpa_file_baru_mempertahankan_foto_lama(): void
    {
        $p = $this->produkLewatForm();
        $this->pasangFotoLama($p);

        $this->ubah($p, ['name' => 'Ganti Nama Saja'])->assertSessionHas('success');

        $this->assertSame('products/lama.png', $p->fresh()->image);
        Storage::disk('public')->assertExists('products/lama.png');
    }

    /**
     * Regresi QA-004 (paling merugikan): update DITOLAK oleh syncUnits() karena
     * rasio terkunci PO, tapi foto lama sudah terlanjur dihapus dari disk
     * (kolom `image` masih menunjuk file yang hilang) + foto baru jadi yatim.
     */
    public function test_update_yang_ditolak_tidak_menghapus_foto_lama_dan_tidak_meninggalkan_foto_baru(): void
    {
        $p = $this->produkLewatForm();
        $this->pasangFotoLama($p);
        $this->makePurchaseOrder(['status' => 'ordered'], [['product' => $p->load('units')]]);

        $rows = $this->barisDari($p);
        $rows[0]['relative_qty'] = 99;           // rasio terkunci PO -> update ditolak

        $this->ubah($p, ['image' => $this->fotoPng()], $rows)->assertSessionHasErrors('units');

        $this->assertSame('products/lama.png', $p->fresh()->image);
        Storage::disk('public')->assertExists('products/lama.png');
        $this->assertSame(['products/lama.png'], Storage::disk('public')->allFiles(), 'tidak boleh ada foto baru yang nyasar');
    }

    public function test_update_dengan_foto_format_salah_ditolak_dan_foto_lama_aman(): void
    {
        $p = $this->produkLewatForm();
        $this->pasangFotoLama($p);

        $this->ubah($p, ['image' => UploadedFile::fake()->create('x.gif', 10, 'image/gif')])->assertSessionHasErrors('image');
        $this->ubah($p, ['image' => $this->fileAsli('x.png', '<svg onload="alert(1)"/>')])->assertSessionHasErrors('image');

        $this->assertSame('products/lama.png', $p->fresh()->image);
        $this->assertSame(['products/lama.png'], Storage::disk('public')->allFiles());
    }

    // =====================================================================
    // Destroy
    // =====================================================================

    public function test_hapus_produk_bersih_menghapus_satuan_riwayat_stok_dan_foto(): void
    {
        $p = $this->produkLewatForm(['initial_stock' => 20]);
        $this->pasangFotoLama($p);
        $this->assertSame(3, ProductUnit::where('product_id', $p->id)->count());
        $this->assertSame(1, StockMovement::where('product_id', $p->id)->count());

        $this->hapus($p)->assertRedirect(route('gudang.produk.index'))->assertSessionHas('success');

        $this->assertNull(Product::find($p->id));
        $this->assertSame(0, ProductUnit::where('product_id', $p->id)->count());
        $this->assertSame(0, StockMovement::where('product_id', $p->id)->count());
        Storage::disk('public')->assertMissing('products/lama.png');
    }

    public function test_produk_yang_sudah_terjual_tidak_bisa_dihapus_dan_fotonya_aman(): void
    {
        $p = $this->makeProduct();
        Storage::disk('public')->put('products/jual.png', 'x');
        $p->forceFill(['image' => 'products/jual.png'])->save();
        $this->makeTransaction([], [['product_id' => $p->id]]);

        $this->hapus($p)->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'sudah pernah terjual'));

        $this->assertNotNull(Product::find($p->id));
        $this->assertSame(3, ProductUnit::where('product_id', $p->id)->count());
        Storage::disk('public')->assertExists('products/jual.png');
    }

    public function test_produk_yang_sudah_dipakai_di_po_tidak_bisa_dihapus_dan_fotonya_aman(): void
    {
        $p = $this->makeProduct();
        Storage::disk('public')->put('products/po.png', 'x');
        $p->forceFill(['image' => 'products/po.png'])->save();
        $this->makePurchaseOrder(['status' => 'draft'], [['product' => $p]]);

        $this->hapus($p)->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'Purchase Order'));

        $this->assertNotNull(Product::find($p->id));
        Storage::disk('public')->assertExists('products/po.png');
    }

    public function test_penghapusan_yang_gagal_di_tengah_tidak_menghilangkan_foto_produk_yang_masih_ada(): void
    {
        $p = $this->makeProduct();
        Storage::disk('public')->put('products/aman.png', 'x');
        $p->forceFill(['image' => 'products/aman.png'])->save();

        // Simulasi delete() gagal. Foto TIDAK boleh sudah terhapus lebih dulu
        // (urutan lama: hapus file dulu, baru baris DB -> produk tersisa tanpa foto).
        Product::deleting(function () {
            throw new \RuntimeException('simulasi kegagalan database');
        });
        $this->withoutExceptionHandling();

        try {
            $this->hapus($p);
            $this->fail('Seharusnya melempar exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulasi kegagalan database', $e->getMessage());
        }

        $this->assertNotNull(Product::find($p->id));
        Storage::disk('public')->assertExists('products/aman.png');
    }

    // =====================================================================
    // Index & show
    // =====================================================================

    public function test_daftar_produk_terurut_nama_dipaginasi_10_dan_menyertakan_produk_nonaktif(): void
    {
        // Dibuat TERBALIK (12 -> 1) supaya urutan id berlawanan dengan urutan nama.
        foreach (range(12, 1) as $i) {
            $this->makeProduct(['name' => sprintf('Barang %02d', $i), 'is_active' => $i !== 3]);
        }

        $hal1 = $this->actingAs($this->gudang)->get(route('gudang.produk.index'))->assertOk()->viewData('products');
        $hal2 = $this->actingAs($this->gudang)->get(route('gudang.produk.index', ['page' => 2]))->viewData('products');

        $this->assertSame(12, $hal1->total(), 'daftar produk Gudang memuat semua produk, termasuk yang nonaktif');
        $this->assertCount(10, $hal1);
        $this->assertSame('Barang 01', $hal1->first()->name);
        $this->assertSame('Barang 10', $hal1->last()->name);
        $this->assertSame(['Barang 11', 'Barang 12'], $hal2->pluck('name')->all());
    }

    public function test_filter_pencarian_nama_sku_barcode_kategori_status_dan_stok_menipis(): void
    {
        $katA = $this->makeCategory('Minuman');
        $katB = $this->makeCategory('Makanan');

        $this->makeProduct(['name' => 'Teh Botol', 'sku' => 'MNM-001', 'category_id' => $katA->id, 'stock' => 50], [
            ['unit_name' => 'btl', 'conversion_to_base' => 1, 'barcode' => '899111', 'selling_price' => 5000, 'is_base_unit' => true],
        ]);
        $this->makeProduct(['name' => 'Kopi Sachet', 'sku' => 'MNM-002', 'category_id' => $katA->id, 'stock' => 2, 'is_active' => false]);
        $this->makeProduct(['name' => 'Roti Tawar', 'sku' => 'MKN-001', 'category_id' => $katB->id, 'stock' => 5, 'min_stock' => 5]);

        $cari = fn (array $q) => $this->actingAs($this->gudang)->get(route('gudang.produk.index', $q))
            ->assertOk()->viewData('products')->pluck('name')->all();

        $this->assertSame(['Teh Botol'], $cari(['search' => 'botol']));                       // nama
        $this->assertSame(['Roti Tawar'], $cari(['search' => 'MKN-001']));                    // sku
        $this->assertSame(['Teh Botol'], $cari(['search' => '899111']));                      // barcode persis
        $this->assertSame([], $cari(['search' => '8991']), 'barcode dicocokkan PERSIS, bukan sebagian');
        $this->assertSame(['Kopi Sachet', 'Teh Botol'], $cari(['category_id' => $katA->id]));
        $this->assertSame(['Kopi Sachet'], $cari(['status' => 'inactive']));
        $this->assertSame(['Roti Tawar', 'Teh Botol'], $cari(['status' => 'active']));
        $this->assertSame(['Kopi Sachet', 'Roti Tawar'], $cari(['low_stock' => 1]));           // 2<=5 dan 5<=5 (batas inklusif)
        $this->assertSame(['Kopi Sachet'], $cari(['category_id' => $katA->id, 'low_stock' => 1])); // AND
    }

    public function test_satuan_di_daftar_dan_halaman_detail_terurut_dari_terbesar_ke_terkecil_apa_pun_sort_order_nya(): void
    {
        // Data legacy: sort_order sengaja berlawanan dengan urutan konversi.
        $p = $this->makeProduct([], [
            ['unit_name' => 'sachet', 'conversion_to_base' => 1, 'is_base_unit' => true, 'sort_order' => 0],
            ['unit_name' => 'dus', 'conversion_to_base' => 144, 'is_purchase_unit' => true, 'sort_order' => 1],
            ['unit_name' => 'renceng', 'conversion_to_base' => 12, 'sort_order' => 2],
        ]);

        $index = $this->actingAs($this->gudang)->get(route('gudang.produk.index'))->assertOk()->viewData('products')->first();
        $show = $this->actingAs($this->gudang)->get(route('gudang.produk.show', $p))->assertOk()->viewData('product');

        $this->assertSame(['dus', 'renceng', 'sachet'], $index->units->pluck('unit_name')->all());
        $this->assertSame(['dus', 'renceng', 'sachet'], $show->units->pluck('unit_name')->all());
    }

    // =====================================================================
    // Akses
    // =====================================================================

    public function test_admin_punya_akses_penuh_dan_atribusi_stok_awal_atas_nama_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('gudang.produk.index'))->assertOk();
        $this->simpan(['initial_stock' => 5], $admin)->assertSessionHas('success');

        $p = Product::sole();
        $this->actingAs($admin)->get(route('gudang.produk.show', $p))->assertOk();
        $this->assertSame($admin->id, StockMovement::where('product_id', $p->id)->sole()->user_id);
    }

    public function test_kasir_ditolak_di_semua_endpoint_produk_gudang_dan_tidak_ada_yang_berubah(): void
    {
        $kasir = User::factory()->kasir()->create();
        $p = $this->produkLewatForm(['name' => 'Asli']);
        $jumlah = Product::count();

        $this->actingAs($kasir)->get(route('gudang.produk.index'))->assertForbidden();
        $this->actingAs($kasir)->get(route('gudang.produk.show', $p))->assertForbidden();
        $this->simpan(['name' => 'Selundupan'], $kasir)->assertForbidden();
        $this->ubah($p, ['name' => 'Diubah Kasir'], null, $kasir)->assertForbidden();
        $this->hapus($p, $kasir)->assertForbidden();

        $this->assertSame($jumlah, Product::count());
        $this->assertSame('Asli', $p->fresh()->name);
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $p = $this->makeProduct();

        $this->get(route('gudang.produk.index'))->assertRedirect(route('login'));
        $this->get(route('gudang.produk.show', $p))->assertRedirect(route('login'));
        $this->post(route('gudang.produk.store'), [])->assertRedirect(route('login'));
        $this->put(route('gudang.produk.update', $p), [])->assertRedirect(route('login'));
        $this->delete(route('gudang.produk.destroy', $p))->assertRedirect(route('login'));
    }
}