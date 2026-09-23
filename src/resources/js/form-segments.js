/**
 * Tab switcher generik untuk form panjang yang dipecah jadi beberapa bagian
 * di mobile (lihat gudang/produk/_form.blade.php: Info/Satuan/Stok &
 * Harga/Lainnya). Di desktop/tablet (≥768px) tidak berpengaruh apa-apa --
 * semua panel tetap tampil sekaligus lewat class `md:contents` di Blade,
 * script ini murni urusan tampilan mobile.
 *
 * Markup yang dibutuhkan:
 *   <div data-form-segments="ID">
 *     <button data-segment-tab="nama">...</button> (beberapa)
 *     <div data-segment-panel="nama">...</div> (beberapa, nama harus cocok)
 *   </div>
 */
document.addEventListener('DOMContentLoaded', function () {
    function activate(container, name) {
        container.querySelectorAll('[data-segment-tab]').forEach(function (btn) {
            const isActive = btn.getAttribute('data-segment-tab') === name;
            btn.classList.toggle('bg-white', isActive);
            btn.classList.toggle('shadow-sm', isActive);
            btn.classList.toggle('text-indigo-600', isActive);
            btn.classList.toggle('text-gray-500', !isActive);
        });

        container.querySelectorAll('[data-segment-panel]').forEach(function (panel) {
            panel.classList.toggle('hidden', panel.getAttribute('data-segment-panel') !== name);
        });
    }

    document.querySelectorAll('[data-form-segments]').forEach(function (container) {
        container.querySelectorAll('[data-segment-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activate(container, btn.getAttribute('data-segment-tab'));
            });
        });
    });
});