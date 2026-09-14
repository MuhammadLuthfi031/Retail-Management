<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('admin.laporan.pdf._style')
</head>
<body>
    <div class="header">
        <h1>Laporan Laba/Rugi</h1>
        <div class="meta">
            Periode: {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
            &middot; Dicetak: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    @if ($adaDataLegacy)
        <div class="note">
            Sebagian transaksi di rentang ini dibuat sebelum sistem mencatat snapshot harga pokok per baris.
            Untuk baris tersebut, laporan memakai harga pokok rata-rata TERKINI sebagai perkiraan (bukan harga
            pokok asli saat transaksi itu terjadi).
        </div>
    @endif

    <table class="summary">
        <tr>
            <td style="width: 25%;">
                <span class="label">Total Penjualan</span>
                <span class="value">Rp {{ number_format($totalOmzet, 0, ',', '.') }}</span>
            </td>
            <td style="width: 25%;">
                <span class="label">Total HPP</span>
                <span class="value">Rp {{ number_format($totalHpp, 0, ',', '.') }}</span>
            </td>
            <td style="width: 25%;">
                <span class="label">Laba Kotor</span>
                <span class="value {{ $totalLaba >= 0 ? 'positive' : 'negative' }}">Rp {{ number_format($totalLaba, 0, ',', '.') }}</span>
            </td>
            <td style="width: 25%;">
                <span class="label">Margin</span>
                <span class="value">{{ $margin }}%</span>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Produk</th>
                <th class="right">Qty Terjual*</th>
                <th class="right">Omzet</th>
                <th class="right">HPP</th>
                <th class="right">Laba</th>
                <th class="right">Margin</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($perProduk as $row)
                <tr>
                    <td>{{ $row->product_name }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($row->qty_terjual, 3, ',', '.'), '0'), ',') }}</td>
                    <td class="right">Rp {{ number_format($row->omzet, 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($row->hpp, 0, ',', '.') }}</td>
                    <td class="right {{ $row->laba >= 0 ? 'positive' : 'negative' }}">Rp {{ number_format($row->laba, 0, ',', '.') }}</td>
                    <td class="right">{{ $row->margin }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center">Belum ada penjualan pada rentang tanggal ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">*Qty dalam satuan dasar produk masing-masing. Dokumen ini digenerate otomatis.</div>
</body>
</html>
