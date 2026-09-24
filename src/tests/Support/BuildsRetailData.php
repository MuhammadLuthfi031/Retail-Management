<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Helper pembuat data uji. Sengaja pakai forceCreate() (bukan create()) supaya
 * test tidak ikut pecah kalau $fillable model berubah — yang diuji di sini
 * perilaku bisnis, bukan mass-assignment.
 */
trait BuildsRetailData
{
    private int $retailSeq = 0;

    protected function makeCategory(?string $name = null): Category
    {
        $n = ++$this->retailSeq;
        $name ??= "Kategori Uji {$n}";

        return Category::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name) . "-{$n}",
        ]);
    }

    /**
     * Produk uji. Kalau $units tidak diisi, dibuat 3 level satuan:
     *   dus     = 144 satuan dasar (TIDAK dijual — selling_price null, satuan beli)
     *   renceng =  12 satuan dasar (dijual Rp 11.000)
     *   sachet  =   1 satuan dasar (dijual Rp  1.000, satuan dasar)
     */
    protected function makeProduct(array $attributes = [], ?array $units = null): Product
    {
        $n = ++$this->retailSeq;

        $product = Product::forceCreate(array_merge([
            'category_id' => $this->makeCategory()->id,
            'tracking_mode' => 'unit',
            'name' => "Produk Uji {$n}",
            'sku' => sprintf('TST-%04d', $n),
            'stock' => 0,
            'min_stock' => 5,
            'average_cost' => 0,
            'allow_fractional_sale' => false,
            'is_active' => true,
        ], $attributes));

        $units ??= [
            ['unit_name' => 'dus', 'conversion_to_base' => 144, 'selling_price' => null, 'is_purchase_unit' => true],
            ['unit_name' => 'renceng', 'conversion_to_base' => 12, 'selling_price' => 11000],
            ['unit_name' => 'sachet', 'conversion_to_base' => 1, 'selling_price' => 1000, 'is_base_unit' => true],
        ];

        foreach ($units as $i => $unit) {
            ProductUnit::forceCreate(array_merge([
                'product_id' => $product->id,
                'selling_price' => null,
                'is_base_unit' => false,
                'is_purchase_unit' => false,
                'sort_order' => $i,
            ], $unit));
        }

        // Ambil ulang dari DB supaya tipe atribut sama persis dengan yang
        // dilihat controller di produksi, lengkap dengan relasi units.
        return Product::with('units')->findOrFail($product->id);
    }

    protected function signInAs(string $role): User
    {
        $user = User::factory()->{$role}()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Stok produk saat ini (satuan dasar) langsung dari DB. */
    protected function stockOf(Product|int $product): float
    {
        $id = $product instanceof Product ? $product->id : $product;

        return (float) Product::query()->whereKey($id)->value('stock');
    }
}
