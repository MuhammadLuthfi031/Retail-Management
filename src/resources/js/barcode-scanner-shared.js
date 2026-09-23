import { Html5Qrcode } from 'html5-qrcode';

document.addEventListener('DOMContentLoaded', function () {
    let lastFocusedTarget = null;
    let activeScanner = null;

    // Ingat kolom barcode baris satuan mana yang terakhir diklik/fokus
    document.addEventListener('focusin', function (e) {
        if (e.target.matches('[data-barcode-row-target]')) {
            lastFocusedTarget = e.target;
        }
    });

    function writeResult(value) {
        if (lastFocusedTarget) {
            lastFocusedTarget.value = value;
            lastFocusedTarget.dispatchEvent(new Event('change'));
        } else {
            alert('Klik dulu kolom Barcode pada baris satuan yang ingin diisi, baru scan/upload.');
        }
    }

    function openModal(formId) {
        const modal = document.getElementById('modal-barcode-camera-' + formId);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(formId) {
        const modal = document.getElementById('modal-barcode-camera-' + formId);
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    function stopScanner() {
        if (activeScanner) {
            const scanner = activeScanner;
            activeScanner = null;
            scanner.stop().then(() => scanner.clear()).catch(() => {});
        }
    }

    function startScanner(formId) {
        stopScanner();

        // Cek dukungan getUserMedia LEBIH DULU sebelum panggil library sama
        // sekali. Kalau halaman ini diakses lewat origin yang browser anggap
        // tidak "secure context" (mis. HTTP biasa, bukan HTTPS — walau di
        // proyek ini nginx sudah paksa redirect ke HTTPS, jaga-jaga kalau
        // suatu saat diakses lewat cara lain), navigator.mediaDevices bisa
        // undefined dan baru ketahuan gagalnya di dalam library. Dicek di sini
        // dulu supaya pesannya jelas & langsung, bukan pesan generik.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Browser ini tidak bisa mengakses kamera dari halaman web (butuh HTTPS/koneksi yang dipercaya browser). Gunakan opsi upload foto sebagai gantinya.');
            closeModal(formId);
            return;
        }

        const regionId = formId + '-shared-camera-region';
        const scanner = new Html5Qrcode(regionId);
        activeScanner = scanner;

        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 150 } },
            function onSuccess(decodedText) {
                writeResult(decodedText);
                stopScanner();
                closeModal(formId);
            },
            function onScanFailure() {}
        ).then(function () {
            // PENTING (bug iOS Safari): html5-qrcode membuat elemen <video>
            // sendiri secara dinamis dan cuma mengisi `muted`, TANPA atribut
            // `playsinline`. Di iOS Safari, video tanpa playsinline dicoba
            // diputar native full-screen — kalau itu diblokir kebijakan
            // autoplay browser, preview kamera jadi diam/kosong TANPA ada
            // error apapun yang bisa ditangkap .catch() di bawah (gejalanya:
            // modal kamera terbuka tapi tidak merespon apa-apa, padahal dari
            // sisi kode tidak ada yang gagal). Set manual di sini, tepat
            // setelah elemen video-nya dibuat library & stream berhasil didapat.
            const videoEl = document.querySelector('#' + regionId + ' video');
            if (videoEl) {
                videoEl.setAttribute('playsinline', 'true');
                videoEl.setAttribute('webkit-playsinline', 'true');
                videoEl.muted = true;
            }
        }).catch(function (err) {
            console.error('Gagal mengakses kamera:', err);
            const detail = (err && err.message) ? err.message : String(err);
            alert('Tidak bisa mengakses kamera (' + detail + '). Pastikan izin kamera sudah diberikan ke browser ini di pengaturan HP/OS, halaman diakses lewat HTTPS, dan tidak ada aplikasi lain yang sedang memakai kamera. Kalau masih gagal, gunakan opsi upload foto.');
            closeModal(formId);
        });
    }

    document.querySelectorAll('[data-barcode-shared-scan]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const formId = btn.getAttribute('data-barcode-shared-scan');
            if (!lastFocusedTarget) {
                alert('Klik dulu kolom Barcode pada baris satuan yang ingin diisi, baru scan.');
                return;
            }
            openModal(formId);
            setTimeout(function () { startScanner(formId); }, 150);
        });
    });

    document.querySelectorAll('[data-barcode-shared-stop]').forEach(function (btn) {
        btn.addEventListener('click', stopScanner);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') stopScanner();
    });

    document.querySelectorAll('[data-barcode-shared-upload]').forEach(function (input) {
        input.addEventListener('change', function (e) {
            const formId = input.getAttribute('data-barcode-shared-upload');
            const file = e.target.files[0];
            if (!file) return;

            if (!lastFocusedTarget) {
                alert('Klik dulu kolom Barcode pada baris satuan yang ingin diisi, baru upload foto.');
                input.value = '';
                return;
            }

            const regionId = formId + '-shared-camera-region';
            const scanner = new Html5Qrcode(regionId);

            scanner.scanFile(file, false)
                .then(function (decodedText) { writeResult(decodedText); })
                .catch(function (err) {
                    console.error('Gagal membaca barcode dari foto:', err);
                    alert('Barcode tidak terdeteksi dari foto tersebut. Coba foto yang lebih jelas, atau input manual.');
                })
                .finally(function () { input.value = ''; });
        });
    });
});