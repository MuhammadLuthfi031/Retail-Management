document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-image-preview]').forEach(function (input) {
        input.addEventListener('change', function (e) {
            const targetId = input.getAttribute('data-image-preview');
            const target = document.getElementById(targetId);
            const file = e.target.files[0];
            if (!target || !file) return;

            target.src = URL.createObjectURL(file);
            target.classList.remove('hidden');
        });
    });
});