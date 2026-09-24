<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Pilar #2 (Harga Pokok Rata-Rata Tertimbang) dan pilar #1 (multi-satuan
 * berjenjang). Salah hitung di sini = laporan laba/rugi salah tanpa ada
 * error apa pun, jadi angkanya diuji satu per satu.
 */
class ProductCostingTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    // === Harga pokok rata-rata tertimbang ===

    public function test_average_cost_equals_incoming_cost_when_stock_is_empty(): void
    {
        $product = new Product(['stock' => 0, 'average_cost' => 0]);

        // 12 sachet, total biaya batch Rp 12.000 -> Rp 1.000 per sachet
        $this->assertSame(1000, $product->recalculateAverageCost(12, 12000));
    }

    public function test_average_cost_ignores_stale_cost_when_stock_is_empty(): void
    {
        // Stok habis -> average_cost lama tidak boleh ikut menarik angka baru.
        $product = new Product(['stock' => 0, 'average_cost' => 999999]);

        $this->assertSame(1000, $product->recalculateAverageCost(12, 12000));
    }

    public function test_average_cost_is_weighted_by_quantity(): void
    {
        // 30 unit @1.000 + 10 unit @2.000 (total 20.000) = 50.000 / 40 = 1.250
        $product = new Product(['stock' => 30, 'average_cost' => 1000]);

        $this->assertSame(1250, $product->recalculateAverageCost(10, 20000));
    }

    public function test_average_cost_with_equal_quantities_is_simple_mean(): void
    {
        $product = new Product(['stock' => 10, 'average_cost' => 1000]);

        $this->assertSame(1500, $product->recalculateAverageCost(10, 20000));
    }

    public function test_average_cost_rounds_only_once_at_the_end(): void
    {
        // (1 x 100 + 101) / 2 = 100,5 -> dibulatkan sekali di akhir -> 101
        $product = new Product(['stock' => 1, 'average_cost' => 100]);

        $this->assertSame(101, $product->recalculateAverageCost(1, 101));
    }

    public function test_average_cost_supports_fractional_stock(): void
    {
        // Produk timbang: 2,5 kg @2.000 + 0,5 kg total 3.000 = 8.000 / 3 = 2.666,67
        $product = new Product(['stock' => 2.5, 'average_cost' => 2000]);

        $this->assertSame(2667, $product->recalculateAverageCost(0.5, 3000));
    }

    // === Konversi satuan ===

    public function test_unit_converts_to_and_from_base_quantity(): void
    {
        $renceng = new ProductUnit(['conversion_to_base' => 12]);

        $this->assertEquals(30.0, $renceng->toBase(2.5));
        $this->assertEquals(2.5, $renceng->fromBase(30));
    }

    // === Breakdown stok berjenjang (dus -> renceng -> sachet) ===

    public function test_units_relation_always_puts_base_unit_last(): void
    {
        $product = $this->makeProduct();

        $this->assertSame(['dus', 'renceng', 'sachet'], $product->units->pluck('unit_name')->all());
        $this->assertTrue((bool) $product->units->last()->is_base_unit);
    }

    public function test_format_stock_breaks_quantity_into_largest_units_first(): void
    {
        $product = $this->makeProduct(); // dus=144, renceng=12, sachet=1

        $this->assertSame('2 dus 2 sachet', $product->formatStock(290));      // 288 + 2 (renceng terlewati)
        $this->assertSame('2 dus 1 renceng', $product->formatStock(300));     // 288 + 12
        $this->assertSame('1 renceng 8 sachet', $product->formatStock(20));
        $this->assertSame('5 sachet', $product->formatStock(5));
    }

    public function test_format_stock_omits_zero_base_remainder(): void
    {
        $product = $this->makeProduct();

        $this->assertSame('2 dus', $product->formatStock(288));
    }

    public function test_format_stock_shows_zero_in_base_unit_when_empty(): void
    {
        $product = $this->makeProduct();

        $this->assertSame('0 sachet', $product->formatStock(0));
    }

    public function test_format_stock_falls_back_to_plain_number_without_units(): void
    {
        $product = new Product();
        $product->setRelation('units', collect());

        $this->assertSame('5.5', $product->formatStock(5.5));
    }
}
