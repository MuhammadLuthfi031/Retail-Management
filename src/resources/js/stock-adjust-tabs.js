// Toggle tab "Stok Keluar" / "Stok Opname" di dalam modal "Sesuaikan Stok"
// (menu Gudang > Stok). Pakai event delegation di document supaya tetap
// jalan untuk modal mana pun (1 per baris produk) tanpa perlu query ulang
// tiap render — dan otomatis defensif kalau elemen [data-stock-tab] memang
// tidak ada di halaman (mis. halaman lain).
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-stock-tab]');
        if (!btn) return;

        var modal = btn.closest('[data-modal]');
        if (!modal) return;

        var target = btn.getAttribute('data-stock-tab');

        modal.querySelectorAll('[data-stock-tab]').forEach(function (b) {
            var active = b === btn;
            b.classList.toggle('border-indigo-600', active);
            b.classList.toggle('text-indigo-600', active);
            b.classList.toggle('border-transparent', !active);
            b.classList.toggle('text-gray-500', !active);
        });

        modal.querySelectorAll('[data-stock-panel]').forEach(function (panel) {
            panel.classList.toggle('hidden', panel.getAttribute('data-stock-panel') !== target);
        });
    });
});