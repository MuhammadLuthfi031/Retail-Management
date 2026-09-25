/**
 * Fix bug: form "Terima Barang" (gudang/pembelian/show.blade.php) menampilkan
 * tabel qty untuk desktop DAN kartu qty untuk mobile di dalam SATU <form> yang
 * sama, dengan name="received[...]" yang SENGAJA identik di keduanya (supaya
 * hasil submit konsisten dari sisi Blade). Kedua versi cuma disembunyikan
 * lewat class Tailwind `hidden` (CSS display:none) — BUKAN dihapus dari DOM.
 *
 * Masalahnya: input yang cuma disembunyikan via CSS TETAP ikut ke-submit oleh
 * browser. Jadi setiap kali form ini disubmit, server menerima DUA nilai
 * untuk key `received[<id>]` yang sama — satu dari tabel desktop, satu dari
 * kartu mobile — dan yang menang adalah yang urutannya PALING TERAKHIR di
 * HTML (kartu mobile, karena markupnya ditulis setelah tabel desktop). Kalau
 * gudang mengisi qty di tabel desktop, nilai kosong dari kartu mobile yang
 * tidak diisi siapa pun akan menimpa balik jadi 0 — akibatnya "Konfirmasi
 * Penerimaan" selalu gagal dengan pesan "Isi minimal 1 qty penerimaan",
 * walau qty sudah benar-benar diisi di layar.
 *
 * Perbaikan: nonaktifkan (disabled) semua input pada wrapper yang SEDANG
 * TIDAK TERLIHAT sesuai lebar layar saat ini. Input yang disabled tidak ikut
 * disubmit sama sekali (perilaku standar HTML form) — jadi cuma nilai dari
 * versi yang benar-benar dilihat & diisi user yang benar-benar terkirim.
 * Disinkronkan ulang saat resize/putar layar, supaya tetap benar kalau
 * pengguna mengubah ukuran jendela browser sebelum submit.
 *
 * NOTE: breakpoint 768px di bawah HARUS sama dengan breakpoint `md:` Tailwind
 * (default, tidak di-custom di tailwind.config.js — lihat theme.screens).
 */
(function () {
    const desktopFields = document.getElementById('terimaDesktopFields');
    const mobileFields = document.getElementById('terimaMobileFields');

    if (!desktopFields || !mobileFields) {
        return; // Bukan halaman Terima Barang, atau markup-nya berubah — jangan lakukan apa-apa.
    }

    const mq = window.matchMedia('(min-width: 768px)');

    function sync() {
        const isDesktop = mq.matches;

        desktopFields.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !isDesktop;
        });
        mobileFields.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = isDesktop;
        });
    }

    sync();

    // addEventListener('change', ...) didukung browser modern; fallback ke
    // addListener untuk Safari lama kalau-kalau ada kasir/gudang pakai HP jadul.
    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', sync);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(sync);
    }
})();
