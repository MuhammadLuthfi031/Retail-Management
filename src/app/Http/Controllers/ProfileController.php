<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    // NB: Method destroy() (hapus akun mandiri) sengaja DIHAPUS dari controller
    // ini — bawaan Breeze, tapi bertentangan dengan prinsip sistem (§7.2:
    // "nonaktifkan bukan hapus") dan berisiko cascade-delete riwayat
    // transaksi/stok milik user (lihat transactions.user_id & stock_movements.
    // user_id yang cascadeOnDelete()). Siklus hidup user cuma boleh dikelola
    // Admin lewat Nonaktifkan (admin/users), bukan self-service delete.
}