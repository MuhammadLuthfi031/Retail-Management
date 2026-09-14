<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('admin.laporan.pdf._style')
</head>
<body>
    <div class="header">
        <h1>Laporan Penjualan</h1>
        <div class="meta">
            Periode: {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
            @if ($filterKasirName) &middot; Kasir: {{ $filterKasirName }} @endif
            @if ($filterPaymentLabel) &middot; Metode Bayar: {{ $filterPaymentLabel }} @endif
            &middot; Dicetak: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    <table class="summary">
        <tr>
            <td style="width: 33%;">
                <span class="label">Total Omzet</span>
                <span class="value">Rp {{ number_format($total_omzet, 0, ',', '.') }}</span>
            </td>
            <td style="width: 33%;">
                <span class="label">Jumlah Transaksi</span>
                <span class="value">{{ number_format($jumlah_transaksi, 0, ',', '.') }}</span>
            </td>
            <td style="width: 34%;">
                <span class="label">Rata-rata / Transaksi</span>
                <span class="value">Rp {{ number_format($rata_rata, 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    @if ($dibatasi)
        <div class="note">
            Menampilkan {{ number_format($transaksi->count()) }} transaksi pertama sesuai filter (dari total {{ number_format($jumlah_transaksi) }}).
            Persempit rentang tanggal untuk laporan yang lebih lengkap.
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Tanggal</th>
                <th>Kasir</th>
                <th class="center">Metode Bayar</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transaksi as $trx)
                <tr>
                    <td>{{ $trx->invoice_number }}</td>
                    <td>{{ $trx->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $trx->user->name }}</td>
                    <td class="center">{{ ucfirst($trx->payment_method) }}</td>
                    <td class="right">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center">Tidak ada transaksi pada rentang & filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">Sistem Manajemen Toko — dokumen ini digenerate otomatis, tidak memerlukan tanda tangan.</div>
</body>
</html>
