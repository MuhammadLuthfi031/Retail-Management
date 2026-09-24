<?php

namespace Tests\Feature\Kasir;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * GET kasir/pos/katalog — data preload untuk pencarian instan di halaman POS.
 * Fokus: hanya produk yang BOLEH dijual yang keluar, tidak ada data sensitif
 * (harga pokok), hak akses, dan pengaman ukuran (too_large).
 */
class KatalogTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private function katalog(?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->kasir()->create())
            ->getJson(route('kasir.pos.katalog'));
    }

    public function test_catalog_lists_products_ordered_by_name_with_category_ids(): void
    {
        $minuman = $this->makeCategory('Minuman');
        $makanan = $this->makeCategory('Makanan');
        $this->makeCategory('Kosong'); // tidak punya produk -> tidak ikut daftar kategori

        $this->makeProduct(['name' => 'Teh Botol', 'category_id' => $minuman->id, 'stock' => 5]);
        $this->makeProduct(['name' => 'Aqua', 'category_id' => $minuman->id, 'stock' => 5]);
        $this->makeProduct(['name' => 'Roti', 'category_id' => $makanan->id, 'stock' => 5]);

        $response = $this->katalog()->assertOk()->assertJsonPath('too_large', false);

        $this->assertSame(['Aqua', 'Roti', 'Teh Botol'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame($minuman->id, $response->json('data.0.category_id'));
        $this->assertSame(['Makanan', 'Minuman'], collect($response->json('categories'))->pluck('name')->all());
    }

    public function test_catalog_only_includes_sellable_units(): void
    {
        $this->makeProduct(['stock' => 5]); // default: dus (tidak dijual), renceng, sachet

        $units = $this->katalog()->assertOk()->json('data.0.units');

        $this->assertSame(['renceng', 'sachet'], collect($units)->pluck('unit_name')->all());
    }

    public function test_catalog_excludes_inactive_and_unsellable_products(): void
    {
        $this->makeProduct(['name' => 'Aktif', 'stock' => 5]);
        $this->makeProduct(['name' => 'Nonaktif', 'stock' => 5, 'is_active' => false]);
        $this->makeProduct(['name' => 'Hanya Satuan Beli', 'stock' => 5], [
            ['unit_name' => 'dus', 'conversion_to_base' => 1, 'selling_price' => null, 'is_base_unit' => true],
        ]);

        $names = collect($this->katalog()->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Aktif'], $names);
    }

    public function test_catalog_never_exposes_cost_price(): void
    {
        $this->makeProduct(['stock' => 5, 'average_cost' => 987654]);

        $response = $this->katalog()->assertOk();

        $response->assertJsonMissingPath('data.0.average_cost');
        $this->assertStringNotContainsString('987654', $response->getContent());
    }

    public function test_catalog_reports_too_large_instead_of_sending_everything(): void
    {
        config(['pos.catalog_limit' => 2]);

        foreach (['A', 'B', 'C'] as $name) {
            $this->makeProduct(['name' => $name, 'stock' => 5]);
        }

        $this->katalog()
            ->assertOk()
            ->assertJsonPath('too_large', true)
            ->assertJsonPath('data', []);
    }

    public function test_catalog_within_the_limit_is_sent_in_full(): void
    {
        config(['pos.catalog_limit' => 3]);

        foreach (['A', 'B', 'C'] as $name) {
            $this->makeProduct(['name' => $name, 'stock' => 5]);
        }

        $response = $this->katalog()->assertOk()->assertJsonPath('too_large', false);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_only_kasir_and_admin_may_load_the_catalog(): void
    {
        $this->katalog(User::factory()->kasir()->create())->assertOk();
        $this->katalog(User::factory()->admin()->create())->assertOk();
        $this->katalog(User::factory()->gudang()->create())->assertForbidden();
        $this->katalog(User::factory()->kasir()->inactive()->create())->assertForbidden();
    }

    public function test_guests_cannot_load_the_catalog(): void
    {
        $this->getJson(route('kasir.pos.katalog'))->assertUnauthorized();
    }
}