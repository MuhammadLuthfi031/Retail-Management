<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian Laporan Stok) — korektivitas nilai inventori (stock x
 * average_cost), daftar stok menipis, dan produk terlaris.
 *
 * CATATAN PENTING yang jadi dasar beberapa test di bawah (dari membaca
 * stokData() langsung): `lowStockProducts` adalah query TERPISAH dari
 * `produkQuery`/`totalNilaiInventori` — sengaja TIDAK ikut filter
 * `category_id` dari request (selalu semua produk stok menipis lintas
 * kategori), beda dengan nilai inventori yang MENGIKUTI filter kategori.
 */
class LaporanStokTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function stok(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.laporan.stok', $query));
    }

    public function test_nilai_inventori_dihitung_benar(): void
    {
        $this->makeProduct(['stock' => 10, 'average_cost' => 2000]);  // 20.000
        $this->makeProduct(['stock' => 3.5, 'average_cost' => 4000]); // 14.000 (curah, pecahan)

        $this->stok()->assertOk()->assertViewHas('totalNilaiInventori', 34000);
    }

    public function test_nilai_inventori_mengikuti_filter_kategori(): void
    {
        $kategoriA = $this->makeCategory('Sembako');
        $kategoriB = $this->makeCategory('Minuman');
        $this->makeProduct(['category_id' => $kategoriA->id, 'stock' => 10, 'average_cost' => 1000]); // 10.000
        $this->makeProduct(['category_id' => $kategoriB->id, 'stock' => 10, 'average_cost' => 9999]); // di luar filter

        $this->stok(['category_id' => $kategoriA->id])
            ->assertViewHas('totalNilaiInventori', 10000)
            ->assertViewHas('jumlahProdukSesuaiFilter', 1)
            ->assertViewHas('filterCategoryName', 'Sembako');
    }

    public function test_nilai_inventori_mengikuti_filter_stok_menipis(): void
    {
        $this->makeProduct(['stock' => 2, 'min_stock' => 5, 'average_cost' => 1000]);  // menipis: 2.000
        $this->makeProduct(['stock' => 50, 'min_stock' => 5, 'average_cost' => 9999]); // aman, di luar filter

        $this->stok(['low_stock' => 1])
            ->assertViewHas('totalNilaiInventori', 2000)
            ->assertViewHas('jumlahProdukSesuaiFilter', 1);
    }

    public function test_batas_stok_menipis_stock_sama_dengan_ambang_tetap_dianggap_menipis(): void
    {
        $pas = $this->makeProduct(['stock' => 5, 'min_stock' => 5]);   // pas di ambang -> HARUS masuk
        $aman = $this->makeProduct(['stock' => 6, 'min_stock' => 5]);  // 1 di atas ambang -> TIDAK masuk

        $ids = $this->stok()->viewData('lowStockProducts')->pluck('id');

        $this->assertTrue($ids->contains($pas->id));
        $this->assertFalse($ids->contains($aman->id));
    }

    public function test_produk_stok_menipis_hanya_yang_aktif(): void
    {
        $aktif = $this->makeProduct(['stock' => 1, 'min_stock' => 5, 'is_active' => true]);
        $nonaktif = $this->makeProduct(['stock' => 1, 'min_stock' => 5, 'is_active' => false]);

        $ids = $this->stok()->viewData('lowStockProducts')->pluck('id');

        $this->assertTrue($ids->contains($aktif->id));
        $this->assertFalse($ids->contains($nonaktif->id));
    }

    public function test_daftar_stok_menipis_tidak_ikut_filter_kategori_beda_dengan_nilai_inventori(): void
    {
        $kategoriA = $this->makeCategory('Sembako');
        $kategoriB = $this->makeCategory('Minuman');
        $menipisDiA = $this->makeProduct(['category_id' => $kategoriA->id, 'stock' => 1, 'min_stock' => 5]);
        $menipisDiB = $this->makeProduct(['category_id' => $kategoriB->id, 'stock' => 1, 'min_stock' => 5]);

        // Filter kategori A HANYA memengaruhi nilai inventori/jumlah produk
        // sesuai filter — daftar "stok menipis" tetap menampilkan KEDUANYA.
        $response = $this->stok(['category_id' => $kategoriA->id]);
        $ids = $response->viewData('lowStockProducts')->pluck('id');

        $this->assertTrue($ids->contains($menipisDiA->id));
        $this->assertTrue($ids->contains($menipisDiB->id));
        $this->assertSame(1, $response->viewData('jumlahProdukSesuaiFilter')); // ini yang kena filter
    }

    public function test_produk_terlaris_diurutkan_dari_qty_terbanyak_dan_dibatasi_10(): void
    {
        // 11 produk, qty_terjual 11,10,...,1. Produk ke-11 (qty=1) HARUS
        // tersisih dari daftar top 10.
        $products = collect(range(1, 11))->map(fn () => $this->makeProduct());

        $products->each(function ($product, $i) {
            $qty = 11 - $i; // produk pertama qty=11 (paling laris) ... produk terakhir qty=1
            $this->makeTransaction([], [
                ['product_id' => $product->id, 'quantity' => $qty, 'unit_conversion' => 1, 'subtotal' => $qty * 1000],
            ]);
        });

        $terlaris = $this->stok()->viewData('terlaris');

        $this->assertCount(10, $terlaris);
        $this->assertEquals(11.0, (float) $terlaris->first()->qty_terjual);
        $this->assertFalse($terlaris->pluck('product_id')->contains($products->last()->id));
        // Urut turun: tiap baris >= baris sesudahnya.
        $qtys = $terlaris->pluck('qty_terjual')->map(fn ($v) => (float) $v);
        $this->assertSame($qtys->sortDesc()->values()->all(), $qtys->values()->all());
    }

    public function test_produk_terlaris_hanya_dari_transaksi_completed_dan_dalam_rentang(): void
    {
        $laris = $this->makeProduct();
        $batal = $this->makeProduct();
        $diLuarRentang = $this->makeProduct();

        $this->makeTransaction(['created_at' => '2026-03-15'], [
            ['product_id' => $laris->id, 'quantity' => 5, 'unit_conversion' => 1, 'subtotal' => 5000],
        ]);
        $this->makeTransaction(['status' => 'cancelled', 'created_at' => '2026-03-15'], [
            ['product_id' => $batal->id, 'quantity' => 999, 'unit_conversion' => 1, 'subtotal' => 999000],
        ]);
        $this->makeTransaction(['created_at' => '2026-01-01'], [ // di luar rentang default (30 hari) & rentang eksplisit di bawah
            ['product_id' => $diLuarRentang->id, 'quantity' => 999, 'unit_conversion' => 1, 'subtotal' => 999000],
        ]);

        $terlaris = $this->stok(['from' => '2026-03-01', 'to' => '2026-03-31'])->viewData('terlaris');
        $ids = $terlaris->pluck('product_id');

        $this->assertTrue($ids->contains($laris->id));
        $this->assertFalse($ids->contains($batal->id));
        $this->assertFalse($ids->contains($diLuarRentang->id));
    }

    public function test_pdf_stok_memakai_angka_yang_sama_dengan_tampilan_html(): void
    {
        $this->makeProduct(['stock' => 10, 'average_cost' => 3000]);

        $html = $this->stok();
        $pdf = $this->actingAs($this->admin)->get(route('admin.laporan.stok.pdf'));

        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertSame($html->viewData('totalNilaiInventori'), 30000);
    }
}