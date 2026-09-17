// Toggle fullscreen (Fullscreen API bawaan browser — ini yang bikin address
// bar & tab beneran hilang, sama seperti platform streaming) untuk SEMUA
// tombol [data-fullscreen-toggle] di halaman ini. Bisa ada lebih dari 1
// tombol sekaligus (mis. 1 di sidebar desktop, 1 di sheet "Lainnya" mobile)
// — semuanya disinkronkan lewat event fullscreenchange, bukan cuma tombol
// yang diklik.
document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('[data-fullscreen-toggle]');
    if (buttons.length === 0) return;

    const docEl = document.documentElement;

    // Beberapa browser (paling terkenal: Safari di iPhone) tidak dukung
    // Fullscreen API sama sekali untuk elemen sembarang. Deteksi dulu —
    // kalau tidak didukung, sembunyikan tombolnya daripada nampilin tombol
    // yang diam kalau diklik dan bikin bingung.
    const requestFn = docEl.requestFullscreen || docEl.webkitRequestFullscreen
        || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;

    if (! requestFn) {
        buttons.forEach((btn) => btn.classList.add('hidden'));
        return;
    }

    function isFullscreen() {
        return !!(
            document.fullscreenElement
            || document.webkitFullscreenElement
            || document.mozFullScreenElement
            || document.msFullscreenElement
        );
    }

    function enterFullscreen() {
        const result = requestFn.call(docEl);
        // requestFullscreen() modern me-return Promise; versi lama/prefixed
        // tidak. Tangani dua-duanya tanpa error kalau browser MENOLAK
        // (mis. bukan dipicu langsung dari user gesture).
        if (result && typeof result.catch === 'function') {
            result.catch(() => {});
        }
    }

    function exitFullscreen() {
        const exitFn = document.exitFullscreen || document.webkitExitFullscreen
            || document.mozCancelFullScreen || document.msExitFullscreen;
        if (exitFn) exitFn.call(document);
    }

    function syncButtons() {
        const active = isFullscreen();
        buttons.forEach((btn) => {
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');

            const label = btn.querySelector('[data-fullscreen-label]');
            if (label) label.textContent = active ? 'Keluar Fullscreen' : 'Fullscreen';

            const iconExpand = btn.querySelector('[data-fullscreen-icon-expand]');
            const iconCompress = btn.querySelector('[data-fullscreen-icon-compress]');
            if (iconExpand) iconExpand.classList.toggle('hidden', active);
            if (iconCompress) iconCompress.classList.toggle('hidden', ! active);
        });
    }

    buttons.forEach((btn) => {
        btn.addEventListener('click', function () {
            if (isFullscreen()) {
                exitFullscreen();
            } else {
                enterFullscreen();
            }
        });
    });

    ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach((evt) => {
        document.addEventListener(evt, syncButtons);
    });

    syncButtons();
});