import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

document.addEventListener('DOMContentLoaded', function () {
    const dataEl = document.getElementById('dashboard-chart-data');
    if (!dataEl) return; // bukan halaman dashboard, tidak perlu lanjut

    const data = JSON.parse(dataEl.textContent);
    const currencyTick = (value) => 'Rp ' + new Intl.NumberFormat('id-ID').format(value);

    const penjualanCanvas = document.getElementById('chart-penjualan');
    if (penjualanCanvas) {
        new Chart(penjualanCanvas, {
            type: 'line',
            data: {
                labels: data.penjualan.labels,
                datasets: [{
                    label: 'Omzet',
                    data: data.penjualan.values,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.08)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 2,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: currencyTick }, beginAtZero: true } },
            },
        });
    }

    const barangMasukCanvas = document.getElementById('chart-barang-masuk');
    if (barangMasukCanvas) {
        new Chart(barangMasukCanvas, {
            type: 'bar',
            data: {
                labels: data.barangMasuk.labels,
                datasets: [{
                    label: 'Nilai Barang Masuk',
                    data: data.barangMasuk.values,
                    backgroundColor: '#f59e0b',
                    borderRadius: 4,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: currencyTick }, beginAtZero: true } },
            },
        });
    }
});
