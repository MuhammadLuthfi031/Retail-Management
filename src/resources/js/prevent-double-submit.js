/**
 * Proteksi klik-dobel tombol submit di SELURUH form aplikasi.
 *
 * Ini pelengkap (defense in depth) untuk proteksi di level server (row-locking
 * di StockMovement::record() & PurchaseReceiptController) — tapi tetap penting
 * dicegah dari sisi UI juga, supaya user tidak perlu sampai kena pesan error
 * dari server hanya karena tidak sengaja klik 2x atau koneksi lambat.
 *
 * Form yang memang butuh submit ulang cepat (jarang, tapi kalau ada) bisa
 * dikecualikan dengan menambahkan atribut `data-allow-resubmit` di elemen
 * <form>-nya.
 */
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.hasAttribute('data-allow-resubmit')) return;

    // Form pencarian/filter (method GET) boleh disubmit berkali-kali kapan
    // saja (misal ganti filter lagi sebelum hasil sebelumnya selesai load),
    // jadi tidak perlu dan tidak seharusnya diproteksi di sini.
    if (form.method && form.method.toUpperCase() === 'GET') return;

    // Sudah pernah disubmit sebelumnya (klik dobel) -> tolak submit kedua.
    if (form.dataset.submitting === '1') {
        e.preventDefault();
        return;
    }
    form.dataset.submitting = '1';

    const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    submitButtons.forEach(function (btn) {
        btn.disabled = true;
        btn.classList.add('opacity-60', 'cursor-not-allowed');
    });

    // Jaga-jaga: kalau navigasi gagal (misal validasi server balik ke form
    // yang sama tanpa reload penuh, jarang tapi mungkin di beberapa alur),
    // lepas lagi proteksinya setelah beberapa detik supaya form tidak
    // permanen macet.
    setTimeout(function () {
        form.dataset.submitting = '0';
        submitButtons.forEach(function (btn) {
            btn.disabled = false;
            btn.classList.remove('opacity-60', 'cursor-not-allowed');
        });
    }, 8000);
});
