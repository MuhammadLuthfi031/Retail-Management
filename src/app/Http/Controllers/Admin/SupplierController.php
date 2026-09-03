<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $suppliers = Supplier::withCount('purchaseOrders')
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('contact_person', 'like', "%{$request->search}%")
                        ->orWhere('phone', 'like', "%{$request->search}%");
                });
            })
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.supplier.index', compact('suppliers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['is_active'] = $request->boolean('is_active', true);

        Supplier::create($validated);

        return back()->with('success', "Supplier \"{$validated['name']}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['is_active'] = $request->boolean('is_active', true);

        $supplier->update($validated);

        return back()->with('success', "Supplier \"{$supplier->name}\" berhasil diperbarui.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        // supplier_id di purchase_orders bersifat nullOnDelete (lihat migration),
        // jadi secara teknis hapus tidak akan merusak riwayat PO lama. Tapi demi
        // kejelasan riwayat pembelian, kita tetap larang hapus kalau masih ada
        // PO terkait — arahkan ke nonaktifkan saja (pola sama seperti Produk/Kategori).
        if ($supplier->purchaseOrders()->exists()) {
            return back()->with('error', "Supplier \"{$supplier->name}\" tidak bisa dihapus karena masih punya riwayat PO. Nonaktifkan saja supplier ini kalau sudah tidak dipakai.");
        }

        $supplier->delete();

        return back()->with('success', "Supplier \"{$supplier->name}\" berhasil dihapus.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
