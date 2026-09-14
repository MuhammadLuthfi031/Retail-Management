<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('admin.laporan.pdf._style')
</head>
<body>
    <div class="header">
        <h1>Laporan Stok</h1>
        <div class="meta">
            @if ($filterCategoryName) Kategori: {{ $filterCategoryName }} &middot; @endif
            Produk Terlaris periode: {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
            &middot; Dicetak: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    <table class="summary">
        <tr>
            <td style="width: 50%;">
                <span class="label">Nilai Inventori (stok &times; HPP)</span>
                <span class="value">Rp {{ number_format($totalNilaiInventori, 0, ',', '.') }}</span>
            </td>
            <td style="width: 50%;">
                <span class="label">Jumlah SKU Sesuai Filter</span>
                <span class="value">{{ number_format($jumlahProdukSesuaiFilter) }}</span>
            </td>
        </tr>
    </table>

    @if ($dibatasi)
        <div class="note">
            Tabel inventori dibatasi {{ number_format($produk->count()) }} baris pertama (dari total {{ number_format($jumlahProdukSesuaiFilter) }}).
            Ringkasan nilai inventori di atas tetap dihitung dari SELURUH produk sesuai filter, bukan cuma yang tampil di tabel.
        </div>
    @endif

    <table class="data" style="margin-bottom: 16px;">
        <thead>
            <tr><th colspan="2">Produk Terlaris (periode di atas)</th></tr>
            <tr>
                <th>Produk</th>
                <th class="right">Omzet</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($terlaris as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}. {{ $row->product_name }}</td>
                    <td class="right">Rp {{ number_format($row->omzet, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="center">Belum ada penjualan di periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Produk</th>
                <th>Kategori</th>
                <th class="center">Stok</th>
                <th class="right">HPP / Satuan Dasar</th>
                <th class="right">Nilai Inventori</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($produk as $p)
                <tr>
                    <td>{{ $p->name }}{{ $p->isLowStock() ? ' (menipis)' : '' }}</td>
                    <td>{{ $p->category->name }}</td>
                    <td class="center">{{ $p->formatStock((float) $p->stock) }}</td>
                    <td class="right">Rp {{ number_format($p->average_cost, 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($p->stock * $p->average_cost, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center">Tidak ada produk yang cocok dengan filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">Dokumen ini digenerate otomatis, tidak memerlukan tanda tangan.</div>
</body>
</html>
