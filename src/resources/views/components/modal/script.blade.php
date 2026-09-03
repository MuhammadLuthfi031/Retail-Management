<script>
    document.addEventListener('DOMContentLoaded', function () {
        // ===== MODAL (center, fade) =====
        function openModal(name) {
            var modal = document.getElementById('modal-' + name);
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal(modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal(trigger.getAttribute('data-modal-open'));
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (closer) {
            closer.addEventListener('click', function () {
                var modal = closer.closest('[data-modal]');
                if (modal) closeModal(modal);
            });
        });

        // ===== SHEET (slide up from bottom, untuk mobile) =====
        function openSheet(name) {
            var sheet = document.getElementById('sheet-' + name);
            if (!sheet) return;
            var panel = sheet.querySelector('[data-sheet-panel]');
            sheet.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            // beri jeda 1 frame supaya transisi CSS "translate-y-full -> translate-y-0" jalan
            requestAnimationFrame(function () {
                panel.classList.remove('translate-y-full');
            });
        }

        function closeSheet(sheet) {
            var panel = sheet.querySelector('[data-sheet-panel]');
            panel.classList.add('translate-y-full');
            document.body.classList.remove('overflow-hidden');
            setTimeout(function () {
                sheet.classList.add('hidden');
            }, 300); // samakan dengan duration-300 di CSS
        }

        document.querySelectorAll('[data-sheet-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openSheet(trigger.getAttribute('data-sheet-open'));
            });
        });

        document.querySelectorAll('[data-sheet-close]').forEach(function (closer) {
            closer.addEventListener('click', function () {
                var sheet = closer.closest('[data-sheet]');
                if (sheet) closeSheet(sheet);
            });
        });

        // ===== Tutup semua (modal & sheet) dengan Escape =====
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('[data-modal]:not(.hidden)').forEach(closeModal);
            document.querySelectorAll('[data-sheet]:not(.hidden)').forEach(closeSheet);
        });
    });
</script>