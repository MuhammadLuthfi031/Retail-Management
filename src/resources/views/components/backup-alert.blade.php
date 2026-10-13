@if ($issues !== [])
    @php
        $hasDanger = collect($issues)->contains('level', 'danger');
    @endphp
    <div class="rounded-lg border px-4 py-3 text-sm {{ $hasDanger ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
        <p class="font-semibold mb-1">{{ $hasDanger ? 'Perhatian: data belum aman' : 'Perhatian: backup perlu diperiksa' }}</p>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($issues as $issue)
                <li>{{ $issue['message'] }}</li>
            @endforeach
        </ul>
        <a href="{{ route('admin.backup.index') }}" class="inline-block mt-2 font-semibold underline">Buka halaman Backup</a>
    </div>
@endif