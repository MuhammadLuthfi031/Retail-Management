<?php

namespace tests\Feature\Kasir;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * PosController::checkout() — jalur uang & stok paling sering dipanggil.
 * Prinsip yang diuji: server TIDAK PERNAH percaya angka dari client, dan
 * transaksi bersifat atomik (gagal 1 baris = batal semua, stok tidak berubah).
 *
 * Data default: produk 100 sachet; renceng = 12 sachet @Rp 11.000;
 * sachet @Rp 1.000; dus = satuan beli saja (tidak dijual).
 */
class CheckoutTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $kasir;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->kasir()->create();
        $this->product = $this->makeProduct(['stock' => 100, 'average_cost' => 700]);
    }

    // === Helper ===

    private function line(Product $product, string $unitName, float|int $qty, array $extra = []): array
    {
        $unit = $product->units->firstWhere('unit_name', $unitName);

        return array_merge([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'qty' => $qty,
        ], $extra);
    }

    private function checkout(array $items, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->kasir)->postJson(route('kasir.pos.checkout'), array_merge([
            'items' => $items,
            'payment_method' => 'cash',
            'paid_amount' => 1000000,
        ], $extra));
    }

    private function assertNothingWasSaved(): void
    {
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, TransactionDetail::count());
        $this->assertSame(0, StockMovement::count());
    }

    // === Jalur sukses ===

    public function test_successful_cash_checkout_saves_transaction_snapshot_and_deducts_stock(): void
    {
        $this->travelTo(Carbon::create(2026, 9, 24, 10));

        $response = $this->checkout(
            [$this->line($this->product, 'renceng', 2)],
            ['paid_amount' => 25000],
        );

        $response->assertCreated()
            ->assertJsonPath('data.total_amount', 22000)
            ->assertJsonPath('data.discount_amount', 0)
            ->assertJsonPath('data.grand_total', 22000)
            ->assertJsonPath('data.paid_amount', 25000)
            ->assertJsonPath('data.change_amount', 3000)
            ->assertJsonPath('data.invoice_number', 'INV-20260924-0001');

        // 2 renceng x 12 = 24 sachet keluar dari stok
        $this->assertEquals(76.0, $this->stockOf($this->product));

        $detail = TransactionDetail::firstOrFail();
        $this->assertSame($this->product->name, $detail->product_name);   // snapshot nama
        $this->assertSame('renceng', $detail->unit_name);                 // snapshot satuan
        $this->assertEquals(12.0, (float) $detail->unit_conversion);      // snapshot konversi
        $this->assertEquals(2.0, (float) $detail->quantity);
        $this->assertEquals(11000, $detail->price);                       // snapshot harga jual
        $this->assertEquals(700, $detail->unit_cost);                     // snapshot HPP (basis laba/rugi)
        $this->assertEquals(22000, $detail->subtotal);

        $movement = StockMovement::firstOrFail();
        $this->assertSame('sale', $movement->type);
        $this->assertEquals(24.0, (float) $movement->quantity);
        $this->assertEquals(100.0, (float) $movement->stock_before);
        $this->assertEquals(76.0, (float) $movement->stock_after);
        $this->assertSame('INV-20260924-0001', $movement->reference);
        $this->assertSame($this->kasir->id, $movement->user_id);
    }

    public function test_non_cash_payment_ignores_client_paid_amount(): void
    {
        $response = $this->checkout(
            [$this->line($this->product, 'renceng', 1)],
            ['payment_method' => 'qris', 'paid_amount' => 999],
        );

        $response->assertCreated()
            ->assertJsonPath('data.grand_total', 11000)
            ->assertJsonPath('data.paid_amount', 11000)
            ->assertJsonPath('data.change_amount', 0);
    }

    public function test_invoice_numbers_increment_within_the_same_day(): void
    {
        $this->travelTo(Carbon::create(2026, 9, 24, 10));

        $this->checkout([$this->line($this->product, 'sachet', 1)])
            ->assertJsonPath('data.invoice_number', 'INV-20260924-0001');
        $this->checkout([$this->line($this->product, 'sachet', 1)])
            ->assertJsonPath('data.invoice_number', 'INV-20260924-0002');
    }

    public function test_different_units_of_the_same_product_deduct_from_one_stock(): void
    {
        $this->checkout([
            $this->line($this->product, 'renceng', 1),  // 12
            $this->line($this->product, 'sachet', 3),   // 3
        ])->assertCreated();

        $this->assertEquals(85.0, $this->stockOf($this->product));
        $this->assertSame(2, StockMovement::count());
    }

    public function test_same_unit_appearing_on_two_cart_lines_is_summed(): void
    {
        $this->checkout([
            $this->line($this->product, 'sachet', 2),
            $this->line($this->product, 'sachet', 3),
        ])->assertCreated();

        $this->assertEquals(95.0, $this->stockOf($this->product));
    }

    public function test_selling_exactly_the_remaining_stock_is_allowed(): void
    {
        $product = $this->makeProduct(['stock' => 12]);

        $this->checkout([$this->line($product, 'renceng', 1)])->assertCreated();

        $this->assertEquals(0.0, $this->stockOf($product));
    }

    public function test_fractional_quantity_is_allowed_only_when_product_permits_it(): void
    {
        $curah = $this->makeProduct(
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 10],
            [['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 20000, 'is_base_unit' => true]],
        );

        $this->checkout([$this->line($curah, 'kg', 0.5)])
            ->assertCreated()
            ->assertJsonPath('data.grand_total', 10000);

        $this->assertEquals(9.5, $this->stockOf($curah));
    }

    // === Server tidak percaya client ===

    public function test_price_and_subtotal_sent_by_client_are_ignored(): void
    {
        $response = $this->checkout([
            $this->line($this->product, 'renceng', 1, ['price' => 1, 'subtotal' => 1, 'selling_price' => 1]),
        ], ['total_amount' => 1, 'grand_total' => 1]);

        $response->assertCreated()->assertJsonPath('data.grand_total', 11000);
        $this->assertEquals(11000, TransactionDetail::firstOrFail()->price);
    }

    public function test_item_whose_unit_belongs_to_another_product_is_rejected(): void
    {
        $other = $this->makeProduct(['stock' => 50]);

        // product_id milik produk utama, tapi unit_id milik produk lain (form dimanipulasi).
        $tampered = [
            'product_id' => $this->product->id,
            'unit_id' => $other->units->firstWhere('unit_name', 'renceng')->id,
            'qty' => 1,
        ];

        $this->checkout([$tampered])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product));
        $this->assertEquals(50.0, $this->stockOf($other));
    }

    public function test_nonexistent_unit_is_rejected(): void
    {
        $this->checkout([['product_id' => $this->product->id, 'unit_id' => 999999, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertNothingWasSaved();
    }

    public function test_unit_without_selling_price_cannot_be_sold(): void
    {
        // "dus" cuma satuan beli (selling_price NULL).
        $this->checkout([$this->line($this->product, 'dus', 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product));
    }

    public function test_inactive_product_cannot_be_sold(): void
    {
        $inactive = $this->makeProduct(['stock' => 10, 'is_active' => false]);

        $this->checkout([$this->line($inactive, 'sachet', 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertNothingWasSaved();
    }

    public function test_fractional_quantity_is_rejected_for_products_that_disallow_it(): void
    {
        $this->checkout([$this->line($this->product, 'sachet', 1.5)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertNothingWasSaved();
    }

    // === Diskon ===

    public function test_percent_discount_is_computed_server_side(): void
    {
        $response = $this->checkout([
            $this->line($this->product, 'renceng', 1, ['discount_type' => 'percent', 'discount_value' => 10]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_amount', 11000)
            ->assertJsonPath('data.discount_amount', 1100)
            ->assertJsonPath('data.grand_total', 9900);

        $detail = TransactionDetail::firstOrFail();
        $this->assertEquals(1100, $detail->discount_amount);
        $this->assertEquals(9900, $detail->subtotal);
    }

    public function test_nominal_discount_is_subtracted(): void
    {
        $this->checkout([
            $this->line($this->product, 'renceng', 1, ['discount_type' => 'nominal', 'discount_value' => 500]),
        ])->assertCreated()->assertJsonPath('data.grand_total', 10500);
    }

    public function test_discount_can_never_exceed_the_line_amount(): void
    {
        $nominal = $this->line($this->product, 'renceng', 1, ['discount_type' => 'nominal', 'discount_value' => 999999]);
        $percent = $this->line($this->product, 'renceng', 1, ['discount_type' => 'percent', 'discount_value' => 150]);

        foreach ([$nominal, $percent] as $line) {
            $this->checkout([$line], ['payment_method' => 'qris'])
                ->assertCreated()
                ->assertJsonPath('data.discount_amount', 11000)
                ->assertJsonPath('data.grand_total', 0);
        }
    }

    // === Pembayaran ===

    public function test_cash_payment_below_total_is_rejected_and_nothing_is_saved(): void
    {
        $this->checkout([$this->line($this->product, 'renceng', 1)], ['paid_amount' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount');

        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product));
    }

    // === Atomicity ===

    public function test_insufficient_stock_on_one_line_rolls_back_the_entire_transaction(): void
    {
        $scarce = $this->makeProduct(['stock' => 1]);

        // Baris 1 valid (produk utama), baris 2 minta 5 sachet padahal stok cuma 1.
        $this->checkout([
            $this->line($this->product, 'renceng', 1),
            $this->line($scarce, 'sachet', 5),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        // Tidak ada separuh-berhasil: baris 1 yang sudah sempat memotong stok harus ikut batal.
        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product));
        $this->assertEquals(1.0, $this->stockOf($scarce));
    }

    // === Validasi input dasar ===

    public function test_empty_cart_is_rejected(): void
    {
        $this->checkout([])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->checkout([$this->line($this->product, 'sachet', 0)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.qty');
    }

    public function test_unknown_payment_method_is_rejected(): void
    {
        $this->checkout([$this->line($this->product, 'sachet', 1)], ['payment_method' => 'kredit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    // === Hak akses ===

    public function test_admin_may_use_the_pos(): void
    {
        $admin = User::factory()->admin()->create();

        $this->checkout([$this->line($this->product, 'sachet', 1)], as: $admin)->assertCreated();
    }

    public function test_gudang_role_cannot_checkout(): void
    {
        $gudang = User::factory()->gudang()->create();

        $this->checkout([$this->line($this->product, 'sachet', 1)], as: $gudang)->assertForbidden();

        $this->assertNothingWasSaved();
    }

    public function test_inactive_kasir_cannot_checkout(): void
    {
        $inactive = User::factory()->kasir()->inactive()->create();

        $this->checkout([$this->line($this->product, 'sachet', 1)], as: $inactive)->assertForbidden();

        $this->assertNothingWasSaved();
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson(route('kasir.pos.checkout'), [])->assertUnauthorized();
    }
}
