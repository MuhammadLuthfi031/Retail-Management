<header class="md:hidden sticky top-0 z-30 bg-white border-b border-gray-200 h-14 flex items-center justify-between px-4">
    <a href="{{ route('dashboard') }}" class="font-bold text-lg text-indigo-600">
        Toko<span class="text-gray-800">App</span>
    </a>

    <span @class([
        'text-xs font-semibold px-2 py-1 rounded-full',
        'bg-indigo-100 text-indigo-700' => auth()->user()->isAdmin(),
        'bg-emerald-100 text-emerald-700' => auth()->user()->isKasir(),
        'bg-amber-100 text-amber-700' => auth()->user()->isGudang(),
    ])>
        {{ ucfirst(auth()->user()->role) }}
    </span>
</header>