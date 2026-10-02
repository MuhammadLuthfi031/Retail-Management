<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionDetail;
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

    /**
     * Panggil di AWAL setUp() — SEBELUM membuat user pelaku — untuk menggeser
     * id user berikutnya supaya BUKAN 1.
     *
     * Kenapa perlu: SQLite me-reset sequence tiap test (transaksi di-rollback),
     * jadi user pertama selalu ber-id 1. Kode yang salah mencatat pelaku dengan
     * konstanta (mis. `'user_id' => 1`) lalu kebetulan "benar" di test dan
     * lolos dari asersi `assertSame($user->id, $row->user_id)` — padahal di
     * produksi mencatat karyawan yang keliru = jejak audit rusak diam-diam.
     * Terbukti lewat mutation testing (QA-004): 5 titik pencatat user lolos.
     */
    protected function hindariIdUserPertama(): void
    {
        User::factory()->kasir()->create();
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

    /**
     * Transaksi (+ opsional baris transaction_details) dengan angka yang
     * SENGAJA dikontrol penuh — dipakai test korektivitas Laporan (QA-004).
     * Sengaja BUKAN lewat alur checkout sungguhan (mekanisme checkout itu
     * sendiri sudah dijaga CheckoutTest/CheckoutIdempotencyTest terpisah) —
     * di sini yang diuji murni rumus agregasi LaporanController.
     *
     * forceCreate() supaya `created_at` bisa di-backdate (perlu utk menguji
     * batas rentang tanggal filter) dan `status` bisa diisi selain
     * 'completed' (perlu utk menguji laporan MENGECUALIKAN transaksi
     * batal/refund — lihat catatan di migration transactions: enum status
     * sudah menyiapkan 'refunded'/'cancelled' walau fitur void §7.5 belum
     * ada UI-nya).
     *
     * @param  array<int, array<string, mixed>>  $items  baris transaction_details, tiap baris di-merge di atas default di bawah.
     */
    protected function makeTransaction(array $attributes = [], array $items = []): Transaction
    {
        $n = ++$this->retailSeq;

        $transaction = Transaction::forceCreate(array_merge([
            'invoice_number' => sprintf('TST-INV-%05d', $n),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => User::factory()->kasir()->create()->id,
            'total_amount' => 0,
            'discount_amount' => 0,
            'grand_total' => 0,
            'paid_amount' => 0,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ], $attributes));

        foreach ($items as $item) {
            // product_id adalah foreign key NOT NULL (lihat migration) — kalau
            // pemanggil tidak menyebutkan produk sendiri, buatkan satu di sini
            // supaya default tetap valid tanpa tiap test harus urus produk.
            $productId = $item['product_id'] ?? $this->makeProduct()->id;
            unset($item['product_id']); // supaya array_merge di bawah tidak menimpanya balik

            TransactionDetail::forceCreate(array_merge([
                'transaction_id' => $transaction->id,
                'product_id' => $productId,
                'product_name' => 'Item Uji',
                'unit_name' => 'unit',
                'unit_conversion' => 1,
                'price' => 0,
                // null = simulasikan baris data LAMA (sebelum kolom unit_cost
                // ada) kalau tidak diisi eksplisit oleh pemanggil.
                'unit_cost' => null,
                'discount_amount' => 0,
                'quantity' => 1,
                'subtotal' => 0,
            ], $item));
        }

        return $transaction->fresh();
    }

    /**
     * PO uji (+ opsional item) dibuat LANGSUNG lewat model — dipakai test
     * korektivitas status PO/pembayaran/penerimaan (QA-004) yang butuh PO
     * dalam status tertentu (mis. 'ordered') tanpa mesti melalui seluruh
     * alur form store(). Alur store()/update() SUNGGUHAN (termasuk
     * extractItems()) sudah diuji terpisah lewat request HTTP asli di
     * PurchaseOrderStoreTest/PurchaseOrderUpdateTest — helper ini tidak
     * menggantikan itu, cuma bikin fixture untuk test LAIN.
     *
     * Tidak perlu forceCreate() — semua kolom yang dipakai di sini memang
     * $fillable (beda dengan Product::stock/Transaction, lihat helper lain
     * di trait ini).
     *
     * @param  array<int, array<string, mixed>>  $items  tiap baris boleh berisi 'product' (instance Product, default: produk baru) dan 'unit' (instance ProductUnit, default: satuan pembelian produk itu), sisanya di-merge di atas default (pesan 10, harga 11.000, subtotal 110.000).
     */
    protected function makePurchaseOrder(array $attributes = [], array $items = []): PurchaseOrder
    {
        $n = ++$this->retailSeq;

        $po = PurchaseOrder::create(array_merge([
            'po_number' => sprintf('TST-PO-%05d', $n),
            'supplier_id' => Supplier::create(['name' => "Supplier Uji {$n}"])->id,
            'created_by' => User::factory()->admin()->create()->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDay()->toDateString(),
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
        ], $attributes));

        foreach ($items as $item) {
            $product = $item['product'] ?? $this->makeProduct();
            $unit = $item['unit'] ?? $product->units->firstWhere('is_purchase_unit', true) ?? $product->units->first();
            unset($item['product'], $item['unit']);

            PurchaseOrderItem::create(array_merge([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity_ordered' => 10,
                'quantity_received' => 0,
                'unit_price' => 11000,
                'subtotal' => 110000,
            ], $item));
        }

        // total_amount PO SEHARUSNYA selalu SUM(subtotal) itemnya — jaga
        // fixture tetap konsisten dengan invarian itu sejak dibuat, KECUALI
        // pemanggil memang sengaja mengisi total_amount sendiri (test yang
        // justru mau membuktikan angka itu dihitung ULANG oleh server).
        if ($items !== [] && ! array_key_exists('total_amount', $attributes)) {
            $po->update(['total_amount' => $po->items()->sum('subtotal')]);
        }

        return $po->fresh('items');
    }
}