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
        ).catch(function (err) {
            console.error('Gagal mengakses kamera:', err);
            alert('Tidak bisa mengakses kamera. Pastikan izin kamera sudah diberikan, atau gunakan opsi upload foto.');
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