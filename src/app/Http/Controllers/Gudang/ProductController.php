<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::with(['category', 'units' => fn ($q) => $q->orderByDesc('conversion_to_base')->orderBy('sort_order')])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%")
                        ->orWhereHas('units', fn ($q3) => $q3->where('barcode', $request->search));
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('gudang.produk.index', compact('products', 'categories'));
    }

    public function show(Product $product): View
    {
        // PENTING: urutkan berdasarkan conversion_to_base TERBESAR ke TERKECIL,
        // BUKAN kolom sort_order. Alasannya: produk yang dibuat SEBELUM sistem
        // "baris paling bawah = satuan dasar" ini ada (data lama/legacy) bisa
        // saja punya urutan sort_order yang tidak menaruh satuan dasarnya di
        // posisi terakhir — kalau kita tetap pakai sort_order, form Edit akan
        // menampilkan (dan submit ulang) satuan dalam urutan yang salah, lalu
        // proteksi "satuan dasar tidak boleh berubah" di update() akan salah
        // duga produknya "mau diubah satuan dasarnya" padahal user cuma mau
        // ubah field lain (misal status aktif) — akibatnya SELURUH update
        // ditolak. Mengurutkan dari conversion_to_base selalu menaruh satuan
        // dasar (nilainya selalu 1, angka terkecil yang mungkin) di posisi
        // PALING BAWAH secara konsisten, apa pun nilai sort_order-nya —
        // sekaligus jadi "self-healing": begitu produk ini disimpan ulang
        // (edit apa saja), sort_order-nya otomatis diperbaiki mengikuti
        // urutan submit yang sekarang sudah benar (lihat extractUnits()).
        $product->load(['category', 'units' => fn ($q) => $q->orderByDesc('conversion_to_base')->orderBy('sort_order')]);
        $categories = Category::orderBy('name')->get();

        return view('gudang.produk.show', compact('product', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedProduct($request);
        $units = $this->extractUnits($request);

        $validated['sku'] = $validated['sku'] ?: $this->generateSku();
        // Form sudah kirim hidden input fallback ("0") sebelum kedua checkbox ini
        // (lihat _form.blade.php), jadi field SELALU ada di request — tidak perlu
        // (dan tidak boleh) diberi default di sini. Sebelumnya is_active diberi
        // default(true) yang membuatnya TIDAK PERNAH bisa tersimpan false, karena
        // saat checkbox di-uncheck field memang absen dan langsung jatuh ke
        // default(true) itu, sama seperti saat checkbox dicentang.
        $validated['is_active'] = $request->boolean('is_active');
        $validated['allow_fractional_sale'] = $request->boolean('allow_fractional_sale');

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product = DB::transaction(function () use ($validated, $units, $request) {
            /** @var Product $product */
            $product = Product::create($validated);
            $this->syncUnits($product, $units);

            $initialStock = (float) $request->input('initial_stock', 0);
            if ($initialStock > 0) {
                StockMovement::record(
                    product: $product,
                    type: 'in',
                    quantity: $initialStock,
                    userId: auth()->id(),
                    note: 'Stok awal saat produk pertama kali dibuat',
                    unitCost: $request->filled('initial_cost') ? (int) $request->input('initial_cost') : null,
                );
            }

            return $product;
        });

        return redirect()->route('gudang.produk.show', $product)->with('success', "Produk \"{$product->name}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validatedProduct($request, $product->id);

        // Satuan dasar SAAT INI (sebelum diproses) — dipakai buat pertahanan
        // berlapis di bawah (lihat catatan panjang di extractUnits()).
        $currentBaseUnitId = $product->units()->where('is_base_unit', true)->value('id');

        $units = $this->extractUnits($request);

        // PERTAHANAN BERLAPIS: satuan dasar (baris terakhir di tabel) dikunci
        // sejak produk dibuat, sama seperti tracking_mode — supaya stok,
        // average_cost, dan seluruh riwayat StockMovement/PurchaseOrderItem
        // yang sudah tercatat dalam satuan dasar LAMA tidak pernah diam-diam
        // "dibaca ulang" sebagai satuan dasar BARU tanpa konversi apa pun.
        // Form sudah mengunci ini secara visual (baris satuan dasar tidak
        // punya tombol Hapus & baris baru selalu disisipkan SEBELUM baris
        // terakhir, bukan sesudahnya — lihat unit-rows.js), ini jaga-jaga di
        // server kalau markup form di-modifikasi manual dari luar.
        if ($currentBaseUnitId !== null) {
            $submittedBaseUnit = end($units);
            $submittedBaseIsSameRow = $submittedBaseUnit && (int) ($submittedBaseUnit['id'] ?? 0) === (int) $currentBaseUnitId;

            if (! $submittedBaseIsSameRow) {
                throw ValidationException::withMessages([
                    'units' => 'Satuan dasar (baris paling bawah) tidak bisa diubah setelah produk dibuat — ini mengunci konsistensi stok & riwayat pergerakan yang sudah tercatat. Buat produk baru kalau struktur satuannya memang perlu dirombak total.',
                ]);
            }
        }

        $validated['sku'] = $validated['sku'] ?: $product->sku;
        // Lihat catatan panjang di store() — field ini selalu ada di request
        // berkat hidden input fallback di form, jangan diberi default lagi.
        $validated['is_active'] = $request->boolean('is_active');
        $validated['allow_fractional_sale'] = $request->boolean('allow_fractional_sale');

        // Cara jual (tracking_mode) TIDAK boleh diubah setelah produk dibuat —
        // supaya riwayat stok & transaksi lama tetap konsisten secara historis.
        // Field-nya dikirim disabled/hidden dari form, tapi kita jaga juga di
        // sisi server untuk berjaga-jaga (defense in depth).
        unset($validated['tracking_mode']);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($product, $validated, $units) {
            $product->update($validated);
            $this->syncUnits($product, $units);
        });

        return back()->with('success', "Produk \"{$product->name}\" berhasil diperbarui.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->transactionDetails()->exists()) {
            return back()->with('error', "Produk \"{$product->name}\" tidak bisa dihapus karena sudah pernah terjual. Nonaktifkan saja produk ini jika sudah tidak dijual.");
        }

        if ($product->purchaseOrderItems()->exists()) {
            return back()->with('error', "Produk \"{$product->name}\" tidak bisa dihapus karena sudah pernah dipakai di Purchase Order. Nonaktifkan saja produk ini jika sudah tidak dipesan lagi.");
        }

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete(); // product_units ikut terhapus (cascadeOnDelete)

        return redirect()->route('gudang.produk.index')->with('success', "Produk \"{$product->name}\" berhasil dihapus.");
    }

    /**
     * Validasi field inti produk (di luar array satuan, yang divalidasi
     * terpisah di extractUnits() karena strukturnya dinamis).
     */
    private function validatedProduct(Request $request, ?int $ignoreId = null): array
    {
        $uniqueSku = 'unique:products,sku' . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'tracking_mode' => ['required', 'in:unit,weight'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', $uniqueSku],
            'min_stock' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);
    }

    /**
     * Ambil & validasi array satuan dari request.
     *
     * DESAIN: tidak ada penanda "Satuan Dasar" manual sama sekali (dulu radio
     * `is_base_unit_index_*`, sekarang dihapus) — baris PALING TERAKHIR yang
     * dikirim dari form SELALU otomatis jadi satuan dasar. Ini menghilangkan
     * dua kelas bug sekaligus: (1) lupa menandai satuan dasar, dan (2) tidak
     * sengaja memindahkan status dasar ke baris lain pas edit — karena tidak
     * ada tombol/radio apa pun yang bisa "dipencet salah" untuk itu.
     *
     * Setiap baris SELAIN yang terakhir mengirim `relative_qty`: "isi ke
     * satuan TEPAT DI BAWAHNYA" (bukan langsung ke satuan dasar). Nilai
     * absolut ke satuan dasar dihitung berjenjang di sini.
     */
    private function extractUnits(Request $request): array
    {
        $formId = $request->input('form_id');
        $rawUnits = $request->input('units', []);
        $purchaseIndex = $request->input("is_purchase_unit_index_{$formId}");

        if (empty($rawUnits)) {
            throw ValidationException::withMessages(['units' => 'Minimal harus ada 1 satuan produk.']);
        }

        $cleanRows = [];
        foreach ($rawUnits as $index => $row) {
            $unitName = trim((string) ($row['unit_name'] ?? ''));

            if ($unitName === '') {
                continue; // lewati baris kosong (misal sempat ditambah lalu dikosongkan)
            }

            $rawQty = ($row['relative_qty'] ?? '') !== '' ? (float) $row['relative_qty'] : null;

            $cleanRows[] = [
                'id' => $row['id'] ?? null,
                'unit_name' => $unitName,
                'relative_qty' => $rawQty,
                'selling_price' => ($row['selling_price'] ?? '') !== '' ? (int) $row['selling_price'] : null,
                'barcode' => ($row['barcode'] ?? '') !== '' ? trim($row['barcode']) : null,
                'is_purchase_unit' => (string) $index === (string) $purchaseIndex,
            ];
        }

        if (empty($cleanRows)) {
            throw ValidationException::withMessages(['units' => 'Minimal harus ada 1 satuan produk yang valid.']);
        }

        // Baris PALING BAWAH (terakhir dalam urutan pengiriman form, yang
        // mengikuti urutan visual tabel dari atas ke bawah) = satuan dasar.
        $baseArrayIndex = count($cleanRows) - 1;

        foreach ($cleanRows as $i => $row) {
            if ($i === $baseArrayIndex) {
                continue;
            }
            if ($row['relative_qty'] === null || $row['relative_qty'] <= 0) {
                throw ValidationException::withMessages([
                    'units' => "Isi kolom \"Isi\" untuk satuan \"{$row['unit_name']}\" (harus lebih besar dari 0).",
                ]);
            }
        }

        // Hitung conversion_to_base ABSOLUT (ke satuan dasar) secara BERJENJANG:
        // mulai dari satuan dasar (=1), lalu menaik ke satuan yang lebih besar
        // dengan mengalikan "isi ke satuan di bawahnya" satu per satu. Ini yang
        // membuat input "1 dus = 24 renceng, 1 renceng = 12 sachet" otomatis
        // tersimpan sebagai "1 dus = 288 sachet" — bukan cuma 24 sachet.
        $count = count($cleanRows);
        $absolute = array_fill(0, $count, 1.0);
        for ($i = $baseArrayIndex - 1; $i >= 0; $i--) {
            $absolute[$i] = $cleanRows[$i]['relative_qty'] * $absolute[$i + 1];
        }

        $units = [];
        foreach ($cleanRows as $i => $row) {
            $units[] = [
                'id' => $row['id'],
                'unit_name' => $row['unit_name'],
                'conversion_to_base' => $i === $baseArrayIndex ? 1 : $absolute[$i],
                'selling_price' => $row['selling_price'],
                'barcode' => $row['barcode'],
                'is_base_unit' => $i === $baseArrayIndex,
                'is_purchase_unit' => $row['is_purchase_unit'],
                'sort_order' => $i,
            ];
        }

        // Validasi barcode unik (lintas produk lain maupun sesama baris di form ini)
        $barcodes = array_filter(array_column($units, 'barcode'));
        if (count($barcodes) !== count(array_unique($barcodes))) {
            throw ValidationException::withMessages(['units' => 'Ada barcode yang sama dipakai di lebih dari satu satuan pada form ini.']);
        }
        foreach ($barcodes as $index => $barcode) {
            $exists = ProductUnit::where('barcode', $barcode)
                ->when($units[$index]['id'] ?? null, fn ($q) => $q->where('id', '!=', $units[$index]['id']))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['units' => "Barcode \"{$barcode}\" sudah dipakai produk/satuan lain."]);
            }
        }

        return $units;
    }

    /** Simpan/update/hapus baris product_units sesuai data yang dikirim dari form. */
    private function syncUnits(Product $product, array $units): void
    {
        $keepIds = [];

        foreach ($units as $unitData) {
            $id = $unitData['id'] ?? null;
            unset($unitData['id']);

            if ($id) {
                $unit = ProductUnit::find($id);

                if ($unit) {
                    // PENTING (data-integrity): rasio konversi yang sudah dipakai di
                    // Purchase Order tidak boleh diam-diam berubah — unit_price &
                    // subtotal PO lama dihitung berdasarkan rasio SAAT itu, dan
                    // recalculateAverageCost() juga sudah memakainya untuk hitung
                    // harga pokok. Kalau rasio berubah belakangan, riwayat itu jadi
                    // tidak konsisten dengan kondisi produk yang sekarang, padahal
                    // datanya sendiri tidak salah — cuma jadi tidak bisa dipercaya.
                    // Field lain (nama, harga jual, barcode) tetap bebas diubah,
                    // cuma conversion_to_base yang dikunci di sini.
                    $conversionChanged = abs(
                        (float) $unit->conversion_to_base - (float) $unitData['conversion_to_base']
                    ) > 0.0005;

                    if ($conversionChanged && $unit->purchaseOrderItems()->exists()) {
                        throw ValidationException::withMessages([
                            'units' => "Rasio/isi satuan \"{$unit->unit_name}\" tidak bisa diubah karena sudah dipakai di Purchase Order — mengubahnya akan membuat riwayat harga beli & qty PO lama jadi tidak konsisten dengan kondisi produk sekarang. Kalau rasionya memang salah dari awal, tambahkan sebagai satuan baru dan kosongkan harga jual satuan lama.",
                        ]);
                    }

                    $unit->update($unitData);
                    $keepIds[] = $unit->id;
                }
            } else {
                $unit = $product->units()->create($unitData);
                $keepIds[] = $unit->id;
            }
        }

        // Baris satuan yang tadinya ada di DB tapi sudah dihapus user dari form (mode edit).
        $unitsToDelete = $product->units()->whereNotIn('id', $keepIds)->get();

        // Satuan yang sudah pernah dipakai di Purchase Order TIDAK BOLEH dihapus —
        // purchase_order_items.product_unit_id adalah foreign key tanpa cascade,
        // jadi penghapusan paksa akan gagal di level database (500 error) kalau
        // tidak dicegah di sini dulu dengan pesan yang jelas.
        $blocked = $unitsToDelete->filter(fn (ProductUnit $unit) => $unit->purchaseOrderItems()->exists());

        if ($blocked->isNotEmpty()) {
            $names = $blocked->pluck('unit_name')->implode('", "');
            throw ValidationException::withMessages([
                'units' => "Satuan \"{$names}\" tidak bisa dihapus karena sudah pernah dipakai di Purchase Order. Biarkan barisnya tetap ada (boleh kosongkan harga jual kalau sudah tidak dijual), atau kosongkan qty/hapus itemnya dulu dari PO terkait.",
            ]);
        }

        foreach ($unitsToDelete as $unit) {
            $unit->delete();
        }
    }

    private function generateSku(): string
    {
        $number = (Product::max('id') ?? 0) + 1;
        $sku = 'PRD-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        while (Product::where('sku', $sku)->exists()) {
            $number++;
            $sku = 'PRD-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        }

        return $sku;
    }
}
