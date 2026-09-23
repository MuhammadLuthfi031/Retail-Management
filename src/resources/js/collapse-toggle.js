/**
 * Accordion generik: tombol dengan data-collapse-toggle="targetId" membuka/
 * tutup elemen #targetId (toggle class "hidden"), dan memutar ikon panah yang
 * ditandai data-collapse-chevron="targetId" (kalau ada). Dipakai untuk filter
 * yang dilipat di halaman mobile (mis. Laporan Penjualan) — sengaja dibuat
 * generik/reusable, bukan spesifik ke satu halaman, supaya bisa dipakai ulang
 * di filter lain tanpa duplikasi JS.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-collapse-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-collapse-toggle');
            const target = document.getElementById(targetId);
            if (!target) return;

            target.classList.toggle('hidden');

            const chevron = document.querySelector('[data-collapse-chevron="' + targetId + '"]');
            if (chevron) chevron.classList.toggle('rotate-180');
        });
    });
});