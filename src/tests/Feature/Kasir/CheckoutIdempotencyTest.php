<?php

namespace Tests\Feature\Kasir;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-002 — checkout POS harus idempotent terhadap retry jaringan.
 *
 * Skenario nyata yang dijaga: kasir klik "Proses Transaksi", server sudah
 * commit, tapi respons hilang di jalan (timeout/Wi-Fi putus). Kasir klik
 * ulang dengan `idempotency_key` yang sama — hasil yang benar: transaksi
 * yang SAMA dikembalikan, stok TIDAK dipotong lagi, omzet TIDAK dobel.
 *
 * Data default: produk 100 sachet; renceng = 12 sachet @Rp 11.000;
 * sachet @Rp 1.000; dus = satuan beli saja (tidak dijual).
 */
class CheckoutIdempotencyTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $kasir;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hindariIdUserPertama();
        $this->kasir = User::factory()->kasir()->create();
        $this->product = $this->makeProduct(['stock' => 100, 'average_cost' => 700]);
    }

    // === Helper ===

    private function cart(int $qty = 1, string $unit = 'renceng', array $extra = []): array
    {
        $u = $this->product->units->firstWhere('unit_name', $unit);

        return [array_merge(['product_id' => $this->product->id, 'unit_id' => $u->id, 'qty' => $qty], $extra)];
    }

    private function checkout(?string $key, ?array $items = null, array $extra = [], ?User $as = null): TestResponse
    {
        $payload = array_merge([
            'items' => $items ?? $this->cart(),
            'payment_method' => 'cash',
            'paid_amount' => 1000000,
        ], $extra);

        if ($key !== null) {
            $payload['idempotency_key'] = $key;
        }

        return $this->actingAs($as ?? $this->kasir)->postJson(route('kasir.pos.checkout'), $payload);
    }

    // === Inti: retry dengan key sama ===

    public function test_retry_with_same_key_returns_the_same_transaction_and_deducts_stock_only_once(): void
    {
        $key = (string) Str::uuid();

        $first = $this->checkout($key)->assertCreated();
        $second = $this->checkout($key)->assertOk();   // 200: sudah ada, bukan dibuat baru

        $this->assertSame($first->json('data'), $second->json('data'));

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, TransactionDetail::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertEquals(88.0, $this->stockOf($this->product)); // 1 renceng = 12, SEKALI saja
    }

    public function test_many_retries_with_same_key_still_produce_a_single_sale(): void
    {
        $key = (string) Str::uuid();

        $this->checkout($key)->assertCreated();
        foreach (range(1, 4) as $_) {
            $this->checkout($key)->assertOk();
        }

        $this->assertSame(1, Transaction::count());
        $this->assertEquals(88.0, $this->stockOf($this->product));
    }

    public function test_replay_ignores_a_different_cart_and_returns_the_original_result(): void
    {
        $key = (string) Str::uuid();

        $first = $this->checkout($key, $this->cart(1, 'renceng'))->assertCreated();
        // Key sama, isi keranjang beda: yang berlaku tetap hasil request PERTAMA.
        $second = $this->checkout($key, $this->cart(5, 'sachet'))->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.grand_total'), $second->json('data.grand_total'));
        $this->assertSame(1, Transaction::count());
        $this->assertEquals(88.0, $this->stockOf($this->product));
    }

    /**
     * Skenario nyata paling menyakitkan: kasir menjual stok TERAKHIR, respons
     * hilang di jalan, kasir klik ulang. Pada saat retry stok sudah 0 — kalau
     * jalur cepat (findOwnTransactionByKey) tidak mengenali transaksi milik
     * sendiri, retry jatuh ke validasi stok di processCheckout() dan kasir
     * melihat "stok tidak cukup" untuk penjualan yang SUDAH tersimpan.
     * Mutation testing (QA-004) membuktikan test lama (stok 100) tidak pernah
     * menyentuh jalur ini.
     */
    public function test_retry_after_the_sale_consumed_the_last_stock_still_returns_the_original_transaction(): void
    {
        Product::whereKey($this->product->id)->update(['stock' => 12]); // pas 1 renceng
        $key = (string) Str::uuid();

        $first = $this->checkout($key, $this->cart(1, 'renceng'))->assertCreated();
        $this->assertEquals(0.0, $this->stockOf($this->product));

        // Selagi menunggu, produk juga dinonaktifkan: retry tetap tidak boleh berubah jadi error.
        Product::whereKey($this->product->id)->update(['is_active' => false]);

        $second = $this->checkout($key, $this->cart(1, 'renceng'))->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertEquals(0.0, $this->stockOf($this->product)); // tidak minus, tidak dipotong lagi
    }

    public function test_replay_is_stored_and_matched_case_insensitively(): void
    {
        $key = (string) Str::uuid();

        $this->checkout(strtoupper($key))->assertCreated();
        $this->checkout(strtolower($key))->assertOk();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(strtolower($key), Transaction::firstOrFail()->idempotency_key);
    }

    // === Penjualan sah yang berulang TIDAK boleh ikut tertahan ===

    public function test_different_keys_create_separate_transactions_even_with_identical_carts(): void
    {
        // Dua pembeli membeli barang yang sama persis = 2 penjualan sah.
        $this->checkout((string) Str::uuid())->assertCreated();
        $this->checkout((string) Str::uuid())->assertCreated();

        $this->assertSame(2, Transaction::count());
        $this->assertEquals(76.0, $this->stockOf($this->product));
    }

    // === Percobaan gagal tidak "menghanguskan" key ===

    public function test_key_is_reusable_after_a_failed_attempt_because_nothing_was_saved(): void
    {
        $key = (string) Str::uuid();

        // Percobaan 1: uang kurang -> 422, tidak ada yang tersimpan.
        $this->checkout($key, extra: ['paid_amount' => 100])->assertUnprocessable();
        $this->assertSame(0, Transaction::count());

        // Percobaan 2 (modal yang sama, key yang sama): sekarang cukup -> berhasil.
        $this->checkout($key, extra: ['paid_amount' => 20000])->assertCreated();

        $this->assertSame(1, Transaction::count());
        $this->assertEquals(88.0, $this->stockOf($this->product));
    }

    public function test_key_is_reusable_after_a_price_mismatch_rejection(): void
    {
        $key = (string) Str::uuid();

        // Harga di layar kasir usang (harusnya 11.000) -> 409, belum ada tulisan DB.
        $stale = $this->cart(1, 'renceng', ['expected_price' => 9999]);
        $this->checkout($key, $stale)->assertStatus(409);
        $this->assertSame(0, Transaction::count());
        $this->assertEquals(100.0, $this->stockOf($this->product));

        // Kasir memeriksa lalu proses ulang di modal yang sama (key tidak berubah).
        $fresh = $this->cart(1, 'renceng', ['expected_price' => 11000]);
        $this->checkout($key, $fresh)->assertCreated();

        $this->assertSame(1, Transaction::count());
    }

    // === Validasi: key WAJIB ===

    private const SESSION_MESSAGE = 'Sesi pembayaran tidak valid. Muat ulang halaman POS lalu coba lagi.';

    public function test_missing_key_is_rejected_with_a_message_that_tells_the_cashier_what_to_do(): void
    {
        // Pemicu nyata: tab POS yang dibuka sebelum update dan belum di-refresh
        // (JS lamanya belum mengirim key). Pesannya ditampilkan apa adanya di modal bayar.
        $this->checkout(null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => self::SESSION_MESSAGE]);

        $this->assertSame(0, Transaction::count());
        $this->assertEquals(100.0, $this->stockOf($this->product)); // stok tidak tersentuh
    }

    public function test_empty_string_key_is_treated_as_missing(): void
    {
        $this->checkout('')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => self::SESSION_MESSAGE]);

        $this->assertSame(0, Transaction::count());
    }

    public function test_malformed_key_is_rejected(): void
    {
        $this->checkout('bukan-uuid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => self::SESSION_MESSAGE]);

        $this->assertSame(0, Transaction::count());
    }

    // === Keamanan: key tidak boleh membocorkan transaksi kasir lain ===

    public function test_another_cashiers_key_is_rejected_and_does_not_leak_their_transaction(): void
    {
        $key = (string) Str::uuid();
        $other = User::factory()->kasir()->create();

        $this->checkout($key)->assertCreated();

        $response = $this->checkout($key, as: $other)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        // Tidak ada data transaksi kasir pertama di balasan, dan tidak ada penjualan tambahan.
        $this->assertNull($response->json('data'));
        $this->assertSame(1, Transaction::count());
        $this->assertSame($this->kasir->id, Transaction::firstOrFail()->user_id);
        $this->assertEquals(88.0, $this->stockOf($this->product));
    }

    // === Lapis 3: race yang lolos dari pengecekan aplikasi ===

    public function test_race_where_another_request_commits_just_before_our_insert_returns_that_transaction(): void
    {
        $key = (string) Str::uuid();
        $raced = false;

        // Mensimulasikan request lain dengan key yang sama yang commit
        // TEPAT setelah pengecekan lapis 1 & 2 kita lolos (belum ada baris),
        // tapi SEBELUM INSERT kita dieksekusi.
        Transaction::creating(function () use ($key, &$raced) {
            if ($raced) {
                return;
            }
            $raced = true;

            DB::table('transactions')->insert([
                'invoice_number' => 'INV-RACE-0001',
                'idempotency_key' => $key,
                'user_id' => $this->kasir->id,
                'total_amount' => 11000,
                'discount_amount' => 0,
                'grand_total' => 11000,
                'paid_amount' => 20000,
                'change_amount' => 9000,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->checkout($key)
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-RACE-0001');

        // Hanya baris "pemenang race" yang ada; request kita mundur SEBELUM menyentuh stok/detail.
        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, TransactionDetail::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertEquals(100.0, $this->stockOf($this->product));
    }
}