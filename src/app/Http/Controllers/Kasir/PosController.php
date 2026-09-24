<?php

namespace App\Http\Controllers\Kasir;

use App\Exceptions\PriceMismatchException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Modul Kasir (POS).
 * - Tahap 1: pencarian produk (nama/SKU/barcode) & bangun keranjang di client.
 * - Tahap 2: diskon per item & validasi stok (di sisi tampilan/client).
 * - Tahap 3: checkout() — di sinilah transaksi BENAR-BENAR disimpan ke DB
 *   dan stok BENAR-BENAR dipotong. Semua yang dikirim client (harga,
 *   subtotal, diskon) dianggap TIDAK TERPERCAYA dan dihitung ULANG di sini
 *   dari data produk/satuan yang diambil fresh dari database — client cuma
 *   boleh menentukan product_id, unit_id, qty, dan diskon yang DIAJUKAN.
 *
 * PENTING soal satuan yang boleh dijual (lihat migration product_units):
 * `selling_price` di sebuah baris satuan bisa NULL, artinya satuan itu cuma
 * dipakai untuk pembelian (mis. "dus" cuma satuan beli dari supplier, tidak
 * pernah dijual utuh ke pembeli). Semua query di sini WAJIB memfilter hanya
 * satuan dengan `selling_price` terisi — kalau tidak, kasir bisa "berhasil"
 * menjual satuan yang harusnya tidak boleh dijual langsung, dengan harga
 * null/0.
 */
class PosController extends Controller
{
    public function index(): View
    {
        return view('kasir.pos');
    }

    /**
     * Lihat Produk & Stok (read-only) — §4.3 spesifikasi. HANYA untuk
     * dilihat: tidak ada aksi tambah/ubah/hapus di halaman ini sama sekali
     * (itu wewenang Gudang/Admin lewat Gudang\ProductController). Kasir
     * pakai halaman ini untuk cek harga per satuan & sisa stok tanpa harus
     * buka POS dulu — mis. saat pembeli tanya harga sebelum checkout.
     */
    public function produk(Request $request): View
    {
        $products = Product::with(['category', 'units' => fn ($u) => $u->orderByDesc('conversion_to_base')])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%")
                        ->orWhereHas('units', fn ($q3) => $q3->where('barcode', $request->search));
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            // Default cuma tampilkan produk aktif (yang relevan buat kasir jual) —
            // checkbox "Tampilkan nonaktif" untuk kasus kasir perlu cek produk lama.
            ->when(! $request->boolean('show_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('kasir.produk', compact('products', 'categories'));
    }

    /**
     * Pencarian manual (fallback) by nama/SKU — dipakai live-search saat
     * kasir mengetik nama produk. Kembalikan hanya produk yang punya
     * MINIMAL 1 satuan yang boleh dijual (selling_price terisi).
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $products = Product::query()
            ->active()
            ->whereHas('units', fn ($u) => $u->whereNotNull('selling_price'))
            ->with(['units' => fn ($u) => $u->whereNotNull('selling_price')->orderByDesc('conversion_to_base')])
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->formatProduct($p)),
        ]);
    }

    /**
     * Katalog untuk preload di halaman POS: SEMUA produk aktif yang punya
     * minimal 1 satuan jual, dikirim sekali saat halaman dibuka. Pencarian
     * nama/SKU dan filter kategori lalu dilakukan di browser (kasir-pos.js)
     * tanpa request per ketikan.
     *
     * Bentuk tiap produk SAMA dengan hasil search()/lookup() (formatProduct),
     * jadi tidak ada field baru yang bocor — terutama TIDAK ada average_cost
     * (harga pokok hanya boleh dilihat Admin lewat laporan).
     *
     * Data ini SNAPSHOT: stok/harga bisa berubah setelahnya. Aman karena
     * checkout() menghitung ulang semuanya dari database, bukan dari angka
     * yang dikirim client.
     *
     * Pengaman ukuran: kalau produk aktif melebihi batas (default 5000,
     * bisa diubah lewat config('pos.catalog_limit')), katalog TIDAK dikirim
     * (`too_large: true`) dan client otomatis kembali ke pencarian server —
     * lebih baik pencarian lambat daripada halaman POS yang berat.
     */
    public function katalog(): JsonResponse
    {
        $limit = (int) config('pos.catalog_limit', 5000);

        $products = Product::query()
            ->active()
            ->whereHas('units', fn ($u) => $u->whereNotNull('selling_price'))
            ->with(['units' => fn ($u) => $u->whereNotNull('selling_price')->orderByDesc('conversion_to_base')])
            ->orderBy('name')
            ->limit($limit + 1)
            ->get();

        if ($products->count() > $limit) {
            return response()->json(['data' => [], 'categories' => [], 'too_large' => true]);
        }

        $categories = Category::query()
            ->whereIn('id', $products->pluck('category_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->formatProduct($p))->values(),
            'categories' => $categories->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'too_large' => false,
        ]);
    }

    /**
     * Lookup EXACT by kode — dipakai scanner USB/kamera/upload foto (semua
     * ujung-ujungnya kirim string kode ke sini) dan saat kasir ketik barcode
     * manual lalu tekan Enter.
     *
     * Urutan pencarian:
     *  1) Barcode di level SATUAN (paling spesifik — barcode dus != barcode
     *     sachet dari pabrik, § 3.2 spesifikasi). Kalau ketemu tapi satuan
     *     itu tidak boleh dijual (selling_price null), tolak dengan pesan
     *     jelas — JANGAN fallback diam-diam ke satuan lain, supaya kasir
     *     tidak salah kira sedang menjual satuan yang benar.
     *  2) Fallback: SKU produk (untuk produk tanpa barcode fisik).
     */
    public function lookup(string $code): JsonResponse
    {
        $code = trim($code);

        $unit = ProductUnit::where('barcode', $code)
            ->with(['product' => fn ($q) => $q->active()])
            ->first();

        if ($unit && $unit->product) {
            if ($unit->selling_price === null) {
                return response()->json([
                    'message' => "Satuan \"{$unit->unit_name}\" tidak dijual langsung (cuma satuan beli). Cek satuan lain untuk produk ini lewat pencarian nama.",
                ], 422);
            }

            $unit->product->setRelation(
                'units',
                $unit->product->units()->whereNotNull('selling_price')->orderByDesc('conversion_to_base')->get()
            );

            return response()->json([
                'data' => $this->formatProduct($unit->product),
                'matched_unit_id' => $unit->id,
            ]);
        }

        $product = Product::query()
            ->active()
            ->where('sku', $code)
            ->whereHas('units', fn ($u) => $u->whereNotNull('selling_price'))
            ->with(['units' => fn ($u) => $u->whereNotNull('selling_price')->orderByDesc('conversion_to_base')])
            ->first();

        if ($product) {
            return response()->json([
                'data' => $this->formatProduct($product),
                'matched_unit_id' => null,
            ]);
        }

        return response()->json([
            'message' => "Produk dengan kode \"{$code}\" tidak ditemukan.",
        ], 404);
    }

    /**
     * Proses transaksi penjualan: hitung ulang semua harga/diskon dari data
     * server (BUKAN dari angka yang dikirim client), simpan Transaction +
     * TransactionDetail (dengan snapshot satuan & rasio konversi), lalu
     * potong stok lewat StockMovement::record() (type 'sale') yang sudah
     * punya lock + guard anti-stok-minus dari perbaikan sebelumnya.
     *
     * Kalau salah satu baris gagal (stok kurang, satuan tidak boleh dijual,
     * dll), SELURUH transaksi dibatalkan (DB::transaction rollback) — tidak
     * ada skenario "separuh item berhasil separuh gagal".
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.discount_type' => ['nullable', 'in:nominal,percent'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            // Harga satuan SAAT kasir menambahkan produk ke keranjang di layar
            // (dari katalog preload/hasil pencarian) — BUKAN sumber harga (itu
            // tetap selalu dari DB di bawah), cuma pembanding untuk mendeteksi
            // harga yang sudah usang di layar (lihat PriceMismatchException).
            // Nullable supaya klien yang belum kirim field ini tidak ikut
            // divalidasi (mismatch price tidak dicek untuk item itu).
            'items.*.expected_price' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,debit,qris,transfer'],
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $transaction = DB::transaction(fn () => $this->processCheckout($validated));
        } catch (PriceMismatchException $e) {
            // Dilempar SEBELUM Transaction::create/StockMovement::record apa pun
            // dipanggil (lihat processCheckout) — tidak ada tulisan DB untuk
            // di-rollback selain exception ini sendiri membatalkan transaksi.
            return response()->json([
                'message' => $e->getMessage(),
                'mismatches' => $e->mismatches,
            ], 409);
        }

        return response()->json([
            'message' => 'Transaksi berhasil disimpan.',
            'data' => [
                'id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'total_amount' => $transaction->total_amount,
                'discount_amount' => $transaction->discount_amount,
                'grand_total' => $transaction->grand_total,
                'paid_amount' => $transaction->paid_amount,
                'change_amount' => $transaction->change_amount,
                'payment_method' => $transaction->payment_method,
                'created_at' => $transaction->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Isi transaksi sebenarnya, dipanggil dari dalam DB::transaction() di
     * checkout(). Dipisah jadi method sendiri (bukan closure inline) supaya
     * PriceMismatchException di tengah bisa dilempar lalu ditangkap dengan
     * bersih di checkout(), tanpa closure bersarang yang panjang.
     */
    private function processCheckout(array $validated): Transaction
    {
        $totalAmount = 0;   // gross, sebelum diskon
        $totalDiscount = 0;
        $lines = [];
        $mismatches = [];

        // Ambil & lock SEMUA ProductUnit yang direferensikan keranjang dalam 1
        // query (sebelumnya: 1 query per baris di dalam loop di bawah — N+1
        // di jalur paling sering dipanggil di seluruh aplikasi, tiap transaksi
        // kasir). orderBy('id') sengaja ditambahkan supaya urutan pengambilan
        // lock antar baris SELALU konsisten (naik dari id terkecil) — tanpa
        // ini, 2 kasir checkout nyaris bersamaan dengan produk sama tapi
        // urutan keranjang berbeda bisa saling tunggu (deadlock) di database.
        // lockForUpdate di sini + lock lagi di dalam StockMovement::record()
        // di bawah aman (savepoint bersarang, koneksi & lock yang sama).
        $unitIds = collect($validated['items'])->pluck('unit_id')->unique();
        $units = ProductUnit::with('product')->lockForUpdate()->whereIn('id', $unitIds)->orderBy('id')->get()->keyBy('id');

        foreach ($validated['items'] as $item) {
            $unit = $units->get($item['unit_id']);

            if (! $unit || ! $unit->product || $unit->product_id !== (int) $item['product_id']) {
                throw ValidationException::withMessages([
                    'items' => 'Salah satu produk di keranjang sudah tidak valid. Muat ulang halaman dan coba lagi.',
                ]);
            }
            if ($unit->selling_price === null) {
                throw ValidationException::withMessages([
                    'items' => "Satuan \"{$unit->unit_name}\" untuk \"{$unit->product->name}\" tidak dijual langsung.",
                ]);
            }
            if (! $unit->product->is_active) {
                throw ValidationException::withMessages([
                    'items' => "Produk \"{$unit->product->name}\" sedang nonaktif, tidak bisa dijual.",
                ]);
            }

            $qty = (float) $item['qty'];
            if (! $unit->product->allow_fractional_sale && abs($qty - round($qty)) > 0.0001) {
                throw ValidationException::withMessages([
                    'items' => "Produk \"{$unit->product->name}\" tidak boleh dijual dengan kuantitas pecahan.",
                ]);
            }

            // Deteksi harga usang di layar kasir (lihat PriceMismatchException).
            // Dikumpulkan dulu (bukan langsung throw) supaya kasir sekali lihat
            // SEMUA baris yang berubah, bukan cuma baris pertama yang ketemu.
            if (array_key_exists('expected_price', $item) && $item['expected_price'] !== null
                && (int) $item['expected_price'] !== (int) $unit->selling_price) {
                $mismatches[] = [
                    'product_id' => $unit->product->id,
                    'product_name' => $unit->product->name,
                    'unit_id' => $unit->id,
                    'unit_name' => $unit->unit_name,
                    'expected_price' => (int) $item['expected_price'],
                    'current_price' => (int) $unit->selling_price,
                ];
            }

            $gross = (int) round($unit->selling_price * $qty);
            $discountAmount = $this->computeDiscount(
                $gross,
                $item['discount_type'] ?? null,
                (float) ($item['discount_value'] ?? 0)
            );
            $subtotal = $gross - $discountAmount;

            $totalAmount += $gross;
            $totalDiscount += $discountAmount;

            $lines[] = [
                'product' => $unit->product,
                'unit' => $unit,
                'qty' => $qty,
                'price' => (int) $unit->selling_price,
                'discount_amount' => $discountAmount,
                'subtotal' => $subtotal,
            ];
        }

        // Belum ada SATU PUN tulisan ke DB di titik ini (Transaction/StockMovement
        // baru dibuat di bawah) — melempar di sini membatalkan seluruh proses
        // tanpa perlu me-rollback data apa pun secara eksplisit.
        if (! empty($mismatches)) {
            throw new PriceMismatchException($mismatches);
        }

        $grandTotal = $totalAmount - $totalDiscount;
        $isCash = $validated['payment_method'] === 'cash';

        if ($isCash && $validated['paid_amount'] < $grandTotal) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Uang diterima kurang dari total belanja.',
            ]);
        }

        $paidAmount = $isCash ? $validated['paid_amount'] : $grandTotal;
        $changeAmount = $isCash ? $paidAmount - $grandTotal : 0;

        $transaction = Transaction::createWithUniqueInvoice([
            'user_id' => auth()->id(),
            'total_amount' => $totalAmount,
            'discount_amount' => $totalDiscount,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'change_amount' => $changeAmount,
            'payment_method' => $validated['payment_method'],
            'status' => 'completed',
        ]);

        foreach ($lines as $line) {
            // Kuantitas jual (dalam satuan yang dipilih kasir) WAJIB
            // dikonversi ke satuan dasar dulu sebelum memotong stok —
            // StockMovement::record() selalu bekerja di satuan dasar.
            $qtyInBase = round($line['qty'] * $line['unit']->conversion_to_base, 3);

            StockMovement::record(
                product: $line['product'],
                type: 'sale',
                quantity: $qtyInBase,
                userId: auth()->id(),
                reference: $transaction->invoice_number,
                note: "Penjualan POS: {$line['qty']} {$line['unit']->unit_name}",
            );

            // PENTING: baca average_cost SETELAH StockMovement::record() di
            // atas (yang lockForUpdate baris produk ini), BUKAN dari nilai
            // yang sudah basi sejak awal request — supaya snapshot cost basis
            // di transaction_details akurat walau ada pembelian lain yang
            // barusan mengubah average_cost produk ini persis di detik yang
            // sama. Snapshot ini basis Laporan Laba/Rugi (§7.3) supaya tetap
            // akurat historis meski average_cost produk berubah lagi nanti.
            //
            // TIDAK perlu query refresh() lagi di sini (dulu ada, sudah
            // dihapus): StockMovement::record() di atas sudah mengembalikan
            // attribute produk yang fresh langsung ke objek $line['product']
            // ini lewat setRawAttributes() — refresh() cuma mengulang query
            // yang hasilnya sudah pasti sama, jadi murni query berlebih.
            $costBasis = $line['product']->average_cost;

            $transaction->details()->create([
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->name,
                'unit_name' => $line['unit']->unit_name,
                'unit_conversion' => $line['unit']->conversion_to_base,
                'price' => $line['price'],
                'unit_cost' => $costBasis,
                'discount_amount' => $line['discount_amount'],
                'quantity' => $line['qty'],
                'subtotal' => $line['subtotal'],
            ]);
        }

        return $transaction;
    }

    /** Sama persis dengan logika di frontend (kasir-pos.js computeLineAmounts) — SENGAJA diduplikasi, bukan dipercaya dari client. */
    private function computeDiscount(int $gross, ?string $type, float $value): int
    {
        $amount = match ($type) {
            'percent' => (int) round($gross * (min(100, max(0, $value)) / 100)),
            'nominal' => (int) round(max(0, $value)),
            default => 0,
        };

        return min($amount, $gross);
    }

    private function formatProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'tracking_mode' => $product->tracking_mode,
            'allow_fractional_sale' => (bool) $product->allow_fractional_sale,
            'stock' => (float) $product->stock,
            'image_url' => $product->image ? Storage::url($product->image) : null,
            'units' => $product->units->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'unit_name' => $u->unit_name,
                'selling_price' => (int) $u->selling_price,
                'conversion_to_base' => (float) $u->conversion_to_base,
                'is_base_unit' => (bool) $u->is_base_unit,
            ])->values(),
        ];
    }
}