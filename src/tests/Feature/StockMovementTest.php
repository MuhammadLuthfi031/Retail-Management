<?php

namespace tests\Feature;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * StockMovement::record() adalah satu-satunya pintu perubahan stok dan
 * average_cost di seluruh aplikasi (POS, gudang, penerimaan PO, opname).
 *
 * BATASAN: test ini berjalan di SQLite in-memory, jadi TIDAK bisa
 * membuktikan lockForUpdate() / race condition antar 2 proses — itu hanya
 * bisa diuji di MySQL dengan 2 koneksi paralel. Yang diuji di sini:
 * aritmetika, guard anti-stok-minus, dan pencatatan jejak audit.
 */
class StockMovementTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->gudang()->create();
    }

    public function test_in_increases_stock_and_records_before_and_after(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $movement = StockMovement::record($product, 'in', 5, $this->user->id, 'REF-1', 'catatan');

        $this->assertEquals(15.0, $this->stockOf($product));
        $this->assertSame('in', $movement->type);
        $this->assertEquals(10.0, (float) $movement->stock_before);
        $this->assertEquals(15.0, (float) $movement->stock_after);
        $this->assertEquals(5.0, (float) $movement->quantity);
        $this->assertSame('REF-1', $movement->reference);
        $this->assertSame($this->user->id, $movement->user_id);
    }

    public function test_out_mutation_and_sale_all_decrease_stock(): void
    {
        $product = $this->makeProduct(['stock' => 100]);

        StockMovement::record($product, 'out', 10, $this->user->id);
        StockMovement::record($product, 'mutation', 20, $this->user->id);
        StockMovement::record($product, 'sale', 30, $this->user->id);

        $this->assertEquals(40.0, $this->stockOf($product));
    }

    public function test_stock_can_be_reduced_to_exactly_zero(): void
    {
        $product = $this->makeProduct(['stock' => 12]);

        StockMovement::record($product, 'sale', 12, $this->user->id);

        $this->assertEquals(0.0, $this->stockOf($product));
    }

    public function test_decrease_beyond_stock_is_rejected_and_changes_nothing(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        try {
            StockMovement::record($product, 'out', 11, $this->user->id);
            $this->fail('Seharusnya melempar ValidationException karena stok tidak cukup.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertEquals(10.0, $this->stockOf($product));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_positive_adjustment_increases_stock(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $movement = StockMovement::record($product, 'adjustment', 4, $this->user->id);

        $this->assertEquals(14.0, $this->stockOf($product));
        $this->assertEquals(4.0, (float) $movement->quantity);
    }

    public function test_negative_adjustment_decreases_stock_and_stores_absolute_quantity(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $movement = StockMovement::record($product, 'adjustment', -3, $this->user->id);

        $this->assertEquals(7.0, $this->stockOf($product));
        $this->assertSame('adjustment', $movement->type);
        // Kolom quantity selalu positif; arah dibaca dari stock_before -> stock_after.
        $this->assertEquals(3.0, (float) $movement->quantity);
        $this->assertEquals(10.0, (float) $movement->stock_before);
        $this->assertEquals(7.0, (float) $movement->stock_after);
    }

    public function test_negative_adjustment_cannot_push_stock_below_zero(): void
    {
        $product = $this->makeProduct(['stock' => 2]);

        $this->expectException(ValidationException::class);

        StockMovement::record($product, 'adjustment', -5, $this->user->id);
    }

    public function test_incoming_stock_with_cost_recalculates_average_cost(): void
    {
        $product = $this->makeProduct(['stock' => 10, 'average_cost' => 1000]);

        $movement = StockMovement::record(
            $product, 'in', 10, $this->user->id,
            unitCost: 2000, totalCost: 20000,
        );

        $this->assertEquals(1500, $product->fresh()->average_cost);
        $this->assertEquals(2000, $movement->unit_cost); // jejak audit, bukan basis hitung
    }

    public function test_incoming_stock_without_cost_leaves_average_cost_untouched(): void
    {
        $product = $this->makeProduct(['stock' => 10, 'average_cost' => 1000]);

        StockMovement::record($product, 'in', 10, $this->user->id);

        $this->assertEquals(1000, $product->fresh()->average_cost);
    }

    public function test_sales_do_not_change_average_cost(): void
    {
        $product = $this->makeProduct(['stock' => 10, 'average_cost' => 1000]);

        StockMovement::record($product, 'sale', 4, $this->user->id);

        $this->assertEquals(1000, $product->fresh()->average_cost);
    }

    public function test_record_syncs_fresh_values_back_to_the_callers_instance(): void
    {
        // Caller yang loop banyak item untuk produk yang sama (penerimaan PO,
        // checkout POS) bergantung pada perilaku ini — lihat komentar di record().
        $product = $this->makeProduct(['stock' => 10, 'average_cost' => 1000]);

        StockMovement::record($product, 'in', 10, $this->user->id, unitCost: 2000, totalCost: 20000);

        $this->assertEquals(20.0, (float) $product->stock);
        $this->assertEquals(1500, $product->average_cost);
    }

    public function test_consecutive_receipts_chain_average_cost_correctly(): void
    {
        $product = $this->makeProduct(['stock' => 0, 'average_cost' => 0]);

        // Batch 1: 10 unit total 10.000 -> avg 1.000
        StockMovement::record($product, 'in', 10, $this->user->id, unitCost: 1000, totalCost: 10000);
        // Batch 2: 10 unit total 20.000 -> (10.000 + 20.000) / 20 = 1.500
        StockMovement::record($product, 'in', 10, $this->user->id, unitCost: 2000, totalCost: 20000);
        // Batch 3: 20 unit total 60.000 -> (30.000 + 60.000) / 40 = 2.250
        StockMovement::record($product, 'in', 20, $this->user->id, unitCost: 3000, totalCost: 60000);

        $fresh = $product->fresh();
        $this->assertEquals(40.0, (float) $fresh->stock);
        $this->assertEquals(2250, $fresh->average_cost);
    }
}
