<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cegah browser nge-cache (termasuk bfcache "tombol Back") halaman yang
 * butuh login. Tanpa ini, setelah sesi berakhir — mis. akun baru saja
 * dinonaktifkan Admin lalu user pencet tombol Back di browser — browser bisa
 * sekilas nampilin SNAPSHOT halaman lama dari cache sebelum request baru ke
 * server benar-benar jalan dan diblokir middleware auth/role. Ini bikin
 * bingung ("kok kayak masih login sebentar"), padahal cuma render basi dari
 * cache lokal browser, bukan tanda sesi yang beneran masih aktif — request
 * apa pun yang benar-benar dikirim ke server (klik menu, dsb) tetap diblokir
 * dengan benar seperti biasa.
 */
class PreventBackHistoryCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
