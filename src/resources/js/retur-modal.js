/**
 * Modal Retur Penjualan (retur pelanggan).
 *
 * Dipakai di Riwayat Transaksi (kasir & admin) dan halaman Retur Penjualan (admin).
 * Alur:
 *   1. Klik [data-retur-open="URL"] -> buka #modal-retur, ambil isi form dari server (JSON {html}).
 *   2. Ketik qty -> estimasi uang kembali dihitung di browser (rumus SAMA dengan
 *      SalesReturnService::refundFor; hanya perkiraan, angka resmi dihitung ulang server).
 *   3. Submit -> POST via fetch. Sukses: reload halaman (pesan sukses tampil lewat flash).
 *      Gagal validasi (422): daftar error tampil di dalam modal, isian tetap utuh.
 *
 * Kunci idempotensi (hidden input) ikut terkirim ulang saat user menekan tombol lagi
 * setelah koneksi putus -> server mengembalikan retur yang sama, TIDAK membuat ganda.
 *
 * Buka/tutup mengandalkan sistem modal bawaan (components/modal/script.blade.php):
 * backdrop, tombol [data-modal-close], dan tombol Esc sudah ditangani di sana.
 */
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content;

const modalEl = () => document.getElementById('modal-retur');
const bodyEl = () => modalEl()?.querySelector('[data-retur-body]');
const errorsEl = () => modalEl()?.querySelector('[data-retur-errors]');

const rupiah = (n) => 'Rp ' + n.toLocaleString('id-ID');

function openModal() {
    const modal = modalEl();
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function showErrors(messages) {
    const box = errorsEl();
    if (!box) return;
    box.replaceChildren();

    if (!messages || messages.length === 0) {
        box.classList.add('hidden');
        return;
    }

    // textContent (bukan innerHTML): pesan memuat nama produk dari database.
    const list = document.createElement('ul');
    list.className = 'list-disc list-inside space-y-0.5';
    [...new Set(messages)].forEach((m) => {
        const li = document.createElement('li');
        li.textContent = m;
        list.appendChild(li);
    });
    box.appendChild(list);
    box.classList.remove('hidden');
    // Panel modal yang bisa di-scroll: gulung ke atas supaya pesan error langsung terlihat.
    const panel = modalEl().querySelector('.overflow-y-auto');
    if (panel) panel.scrollTop = 0;
}

function showBodyMessage(text, retryUrl) {
    const body = bodyEl();
    body.replaceChildren();

    const p = document.createElement('p');
    p.className = 'py-10 text-center text-sm text-gray-500';
    p.textContent = text;
    body.appendChild(p);

    if (retryUrl) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.returRetry = retryUrl;
        btn.className = 'mx-auto block min-h-[44px] px-5 rounded-md text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100';
        btn.textContent = 'Coba lagi';
        body.appendChild(btn);
    }
}

async function loadForm(url) {
    showErrors([]);
    showBodyMessage('Memuat data transaksi…');

    try {
        const res = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            const msg = res.status === 419
                ? 'Sesi Anda sudah habis. Muat ulang halaman lalu coba lagi.'
                : (data.message || 'Gagal memuat data transaksi.');
            showBodyMessage(msg, res.status === 403 || res.status === 419 || res.status === 422 ? null : url);
            return;
        }

        // HTML ini dirender server dengan escaping Blade.
        bodyEl().innerHTML = data.html;
        recalc(bodyEl().querySelector('[data-retur-form]'));
    } catch (e) {
        showBodyMessage('Koneksi bermasalah, data transaksi belum termuat.', url);
    }
}

const fmtQty = (milli) => (milli / 1000).toLocaleString('id-ID', { maximumFractionDigits: 3 });

/**
 * Nilai satu baris, atau null kalau qty kosong/tidak valid.
 *
 * mode "supplier" (retur ke supplier): user memilih SATUAN retur; qty dikonversi ke satuan dasar
 *   dan dibandingkan dengan sisa (satuan dasar). Rumus nilai = PurchaseReturnService::valueFor().
 *   Baris untuk Gudang TIDAK punya atribut nilai (Gudang tidak boleh melihat uang) -> nilai 0.
 * default (retur pelanggan): rumus = SalesReturnService::refundFor().
 */
function evaluateLine(line) {
    const input = line.querySelector('[data-retur-qty]');
    if (!input) return null;

    const q = Math.round((parseFloat(input.value) || 0) * 1000);
    if (q <= 0) return null;

    if (line.dataset.mode === 'supplier') {
        const unit = line.querySelector('[data-retur-unit]');
        const convMilli = parseInt(unit?.selectedOptions[0]?.dataset.conv, 10);
        if (!convMilli) return null;

        const baseMilli = Math.round((q * convMilli) / 1000);
        const remaining = parseInt(line.dataset.remainingBase, 10);
        if (baseMilli <= 0 || baseMilli > remaining) return null;

        const received = parseInt(line.dataset.receivedBase, 10);
        const totalValue = parseInt(line.dataset.totalValue, 10);
        const remainingValue = parseInt(line.dataset.remainingValue, 10);
        if (!received || Number.isNaN(totalValue) || Number.isNaN(remainingValue)) return { value: 0 };

        return {
            value: baseMilli === remaining
                ? remainingValue
                : Math.min(Math.round((totalValue * baseMilli) / received), remainingValue),
        };
    }

    const remaining = parseInt(line.dataset.remaining, 10);
    if (q > remaining) return null;

    const sold = parseInt(line.dataset.sold, 10);
    const subtotal = parseInt(line.dataset.subtotal, 10);
    const remainingRefund = parseInt(line.dataset.remainingRefund, 10);

    return {
        value: q === remaining
            ? remainingRefund
            : Math.min(Math.round((subtotal * q) / sold), remainingRefund),
    };
}

/** Petunjuk "Maks. N satuan" mengikuti satuan retur yang sedang dipilih (mode supplier). */
function updateHint(line) {
    const hint = line.querySelector('[data-retur-hint]');
    const unit = line.querySelector('[data-retur-unit]');
    if (!hint || !unit) return;

    const convMilli = parseInt(unit.selectedOptions[0]?.dataset.conv, 10);
    if (!convMilli) return;

    const remaining = parseInt(line.dataset.remainingBase, 10);
    const maxInUnit = Math.floor((remaining * 1000) / convMilli); // milli satuan terpilih, dibulatkan ke bawah
    hint.textContent = 'Maks. ' + fmtQty(maxInUnit) + ' ' + (unit.selectedOptions[0].dataset.name || '');
}

/** Hitung total & apakah ada qty valid. */
function compute(form) {
    let total = 0;
    let anyQty = false;

    form.querySelectorAll('[data-retur-line]').forEach((line) => {
        const result = evaluateLine(line);
        if (!result) return;

        anyQty = true;
        total += result.value;
    });

    return { total, anyQty };
}

/** Perbarui tampilan total + aktif/nonaktifkan tombol. */
function recalc(form) {
    if (!form) return;
    form.querySelectorAll('[data-retur-line][data-mode="supplier"]').forEach(updateHint);
    const { total, anyQty } = compute(form);

    const totalEl = form.querySelector('[data-retur-total]');
    if (totalEl) totalEl.textContent = rupiah(total);

    const submit = form.querySelector('[data-retur-submit]');
    if (submit && form.dataset.busy !== '1') submit.disabled = !anyQty;
}

function setBusy(form, busy) {
    const submit = form.querySelector('[data-retur-submit]');
    form.dataset.busy = busy ? '1' : '0';
    if (submit) {
        submit.disabled = busy;
        submit.textContent = busy ? 'Memproses…' : 'Proses Retur';
    }
    if (!busy) recalc(form);
}

async function submitForm(form) {
    if (form.dataset.busy === '1') return;

    showErrors([]);

    // Tombol sudah nonaktif tanpa qty, tapi Enter di kolom alasan tetap bisa men-submit form.
    if (!compute(form).anyQty) {
        showErrors(['Isi qty retur minimal pada satu barang.']);
        return;
    }

    if (!window.confirm('Retur tidak bisa dibatalkan setelah disimpan. Lanjutkan?')) return;

    setBusy(form, true);

    try {
        const res = await fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: new FormData(form),
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok) {
            // Biarkan tombol tetap "Memproses…" sampai halaman termuat ulang.
            window.location.reload();
            return;
        }

        if (res.status === 422 && data.errors) {
            showErrors(Object.values(data.errors).flat());
        } else if (res.status === 419) {
            showErrors(['Sesi Anda sudah habis. Muat ulang halaman lalu coba lagi.']);
        } else if (res.status === 403) {
            showErrors([data.message || 'Anda tidak punya akses untuk meretur transaksi ini.']);
        } else {
            showErrors([data.message || 'Terjadi kesalahan di server. Coba lagi; hubungi admin bila berulang.']);
        }
    } catch (e) {
        showErrors(['Koneksi terputus. Cek koneksi lalu tekan "Proses Retur" lagi — retur tidak akan tercatat ganda.']);
    }

    setBusy(form, false);
}

// Event delegation: form di dalam modal diganti tiap dibuka, jadi tidak bisa dipasangi listener langsung.
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-retur-open]');
    if (opener) {
        const subtitle = modalEl()?.querySelector('[data-retur-subtitle]');
        if (subtitle) subtitle.textContent = opener.dataset.returInvoice || '';
        const title = modalEl()?.querySelector('[data-retur-title]');
        if (title) title.textContent = opener.dataset.returTitle || 'Retur Penjualan';
        openModal();
        loadForm(opener.dataset.returOpen);
        return;
    }

    const retry = e.target.closest('[data-retur-retry]');
    if (retry) loadForm(retry.dataset.returRetry);
});

document.addEventListener('input', (e) => {
    if (e.target.matches('[data-retur-qty]')) recalc(e.target.closest('[data-retur-form]'));
});

// Ganti satuan retur (mode supplier) mengubah konversi -> hitung ulang.
document.addEventListener('change', (e) => {
    if (e.target.matches('[data-retur-unit]')) recalc(e.target.closest('[data-retur-form]'));
});

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-retur-form]')) return;
    e.preventDefault();
    submitForm(form);
});