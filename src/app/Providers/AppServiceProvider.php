<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lempar LazyLoadingViolationException kalau ada kode yang lupa
        // eager-load relasi (mis. akses $product->units di view/controller
        // tanpa with('units') sebelumnya) — supaya N+1 baru KETAHUAN LANGSUNG
        // saat development (dilempar sbg error yg jelas), bukan diam-diam jalan
        // lambat dan baru disadari nanti setelah data produksi membesar.
        // Dimatikan di production (! isProduction()) supaya kalau ada kasus
        // lolos tak terduga, user tokonya tetap dapat halaman yang jalan
        // (walau lebih lambat) daripada layar error putih.
        Model::preventLazyLoading(! app()->isProduction());

        // Default bawaan Laravel untuk middleware 'guest' (dipakai di /login,
        // /forgot-password, dll — lihat routes/auth.php) akan redirect user
        // yang SUDAH login ke route('dashboard') kalau route itu ada — dan di
        // proyek ini 'dashboard' cuma placeholder generik Breeze, BUKAN
        // halaman kerja user. Override supaya ikut User::homeRouteName(),
        // konsisten dengan redirect login & link logo/menu (satu sumber
        // kebenaran, jangan hardcode role->route lagi di tempat lain).
        RedirectIfAuthenticated::redirectUsing(function ($request) {
            return route(Auth::user()->homeRouteName());
        });
    }
}