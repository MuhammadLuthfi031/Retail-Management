<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Manajemen User/Karyawan (§7.2 spesifikasi).
 *
 * SENGAJA tidak ada method destroy()/hapus permanen sama sekali — spek
 * eksplisit minta "nonaktifkan akun (bukan hapus)". Ini juga menghindari
 * masalah data-integrity: `transactions.user_id` & `stock_movements.user_id`
 * di-set cascadeOnDelete ke user, jadi menghapus user akan ikut menghapus
 * SELURUH riwayat transaksi/pergerakan stok yang pernah dia buat — jelas
 * tidak boleh terjadi untuk sistem yang butuh jejak audit. Nonaktifkan
 * (is_active = false) cukup untuk mencegah user itu login lagi (lihat
 * CheckRole middleware) tanpa merusak riwayat apa pun.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->when($request->role, fn ($q) => $q->where('role', $request->role))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,kasir,gudang'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        return back()->with('success', "User \"{$validated['name']}\" berhasil ditambahkan.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'role' => ['required', 'in:admin,kasir,gudang'],
        ]);

        // PENTING: cegah admin yang login mengubah ROLE akun-nya SENDIRI
        // menjadi bukan admin, kalau dia satu-satunya admin aktif yang
        // tersisa — supaya sistem tidak pernah berakhir dengan 0 admin aktif
        // (lockout total dari Modul Admin, tidak bisa dipulihkan lewat UI).
        if ($user->id === auth()->id() && $user->role === 'admin' && $validated['role'] !== 'admin' && $this->isLastActiveAdmin($user)) {
            throw ValidationException::withMessages([
                'role' => 'Tidak bisa mengubah role akun sendiri karena ini satu-satunya akun Admin aktif. Buat atau aktifkan Admin lain dulu sebelum mengubah ini.',
            ]);
        }

        $user->update($validated);

        return back()->with('success', "User \"{$user->name}\" berhasil diperbarui.");
    }

    /** Toggle aktif/nonaktif — TIDAK PERNAH menghapus data user. */
    public function toggleActive(User $user): RedirectResponse
    {
        $activating = ! $user->is_active;

        if (! $activating) {
            if ($user->id === auth()->id()) {
                return back()->with('error', 'Tidak bisa menonaktifkan akun sendiri yang sedang login.');
            }

            if ($user->role === 'admin' && $this->isLastActiveAdmin($user)) {
                return back()->with('error', "Tidak bisa menonaktifkan \"{$user->name}\" karena ini satu-satunya akun Admin aktif yang tersisa.");
            }
        }

        $user->update(['is_active' => $activating]);

        return back()->with('success', $activating
            ? "User \"{$user->name}\" berhasil diaktifkan kembali."
            : "User \"{$user->name}\" berhasil dinonaktifkan. User ini tidak akan bisa login lagi sampai diaktifkan ulang.");
    }

    /**
     * Reset password paksa oleh Admin (§7.2: "reset password"). Bukan alur
     * "lupa password" via email — ini toko internal, admin langsung set
     * password baru untuk disampaikan ke karyawan bersangkutan.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        return back()->with('success', "Password untuk \"{$user->name}\" berhasil direset. Sampaikan password baru ini ke yang bersangkutan secara langsung/pribadi.");
    }

    /** True kalau $user adalah SATU-SATUNYA akun role admin yang masih aktif. */
    private function isLastActiveAdmin(User $user): bool
    {
        return ! User::where('role', 'admin')
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
