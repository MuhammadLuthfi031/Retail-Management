<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $transaction->invoice_number }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /* Tampilan layar: kertas struk disimulasikan di tengah halaman abu-abu */
        body { background: #f3f4f6; }
        .receipt-paper { width: 320px; }

        /* Tampilan cetak: buang background/bayangan, kertas mengikuti lebar
           printer thermal apa adanya (bukan dipaksa 320px seperti di layar) */
        @media print {
            @page { margin: 4mm; }
            body { background: #fff; }
            .no-print { display: none !important; }
            .receipt-paper { width: 100%; box-shadow: none !important; margin: 0 !important; }
        }
    </style>
</head>
<body class="min-h-screen py-6 flex flex-col items-center font-mono text-[13px] text-gray-800">

    <div class="no-print mb-4 flex gap-2">
        <button type="button" onclick="window.print()"
                class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">
            Cetak Struk
        </button>
        <button type="button" onclick="window.close()"
                class="px-4 py-2 bg-white border border-gray-300 text-gray-600 rounded-md text-sm font-medium hover:bg-gray-50">
            Tutup
        </button>
    </div>

    <div class="receipt-paper bg-white shadow-md mx-auto p-4">
        <div class="text-center mb-3">
            <p class="font-bold text-sm uppercase">TokoApp</p>
            <p class="text-[11px] text-gray-500">Terima kasih telah berbelanja</p>
        </div>

        <div class="border-t border-dashed border-gray-400 my-2"></div>

        <div class="text-[11px] space-y-0.5">
            <div class="flex justify-between"><span>No. Invoice</span><span>{{ $transaction->invoice_number }}</span></div>
            <div class="flex justify-between"><span>Tanggal</span><span>{{ $transaction->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="flex justify-between"><span>Kasir</span><span>{{ $transaction->user->name ?? '—' }}</span></div>
        </div>

        <div class="border-t border-dashed border-gray-400 my-2"></div>

        <div class="space-y-1.5">
            @foreach ($transaction->details as $item)
                <div>
                    <div class="flex justify-between">
                        <span>{{ $item->product_name }}</span>
                    </div>
                    <div class="flex justify-between text-[11px] text-gray-600">
                        <span>{{ rtrim(rtrim(number_format($item->quantity, 3, ',', '.'), '0'), ',') }} {{ $item->unit_name }} x {{ number_format($item->price, 0, ',', '.') }}</span>
                        <span>{{ number_format($item->subtotal, 0, ',', '.') }}</span>
                    </div>
                    @if ($item->discount_amount > 0)
                        <div class="flex justify-between text-[11px] text-gray-500">
                            <span>Diskon</span>
                            <span>-{{ number_format($item->discount_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="border-t border-dashed border-gray-400 my-2"></div>

        <div class="space-y-0.5">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ number_format($transaction->total_amount, 0, ',', '.') }}</span></div>
            @if ($transaction->discount_amount > 0)
                <div class="flex justify-between"><span>Diskon</span><span>-{{ number_format($transaction->discount_amount, 0, ',', '.') }}</span></div>
            @endif
            <div class="flex justify-between font-bold text-sm pt-1"><span>Total</span><span>{{ number_format($transaction->grand_total, 0, ',', '.') }}</span></div>
        </div>

        <div class="border-t border-dashed border-gray-400 my-2"></div>

        <div class="space-y-0.5">
            <div class="flex justify-between"><span>Bayar ({{ ucfirst($transaction->payment_method) }})</span><span>{{ number_format($transaction->paid_amount, 0, ',', '.') }}</span></div>
            @if ($transaction->payment_method === 'cash')
                <div class="flex justify-between"><span>Kembalian</span><span>{{ number_format($transaction->change_amount, 0, ',', '.') }}</span></div>
            @endif
        </div>

        <div class="border-t border-dashed border-gray-400 my-3"></div>

        <p class="text-center text-[11px] text-gray-500">*** Terima Kasih ***</p>
    </div>

    <script>
        // Struk selalu dibuka sebagai halaman/tab baru (bukan bagian alur
        // biasa), jadi langsung tawarkan dialog cetak begitu siap — sesuai
        // kebiasaan software kasir pada umumnya. Tombol "Cetak Struk" di
        // atas tetap ada untuk cetak ulang kalau dialog ini di-batalkan.
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
