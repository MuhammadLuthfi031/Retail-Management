@props(['name', 'maxWidth' => 'lg'])

@php
$maxWidthClass = [
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
][$maxWidth] ?? 'max-w-lg';
@endphp

<div
    id="modal-{{ $name }}"
    data-modal
    class="hidden fixed inset-0 z-50 items-center justify-center px-4 py-6"
>
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-gray-900/50" data-modal-close></div>

    {{-- Panel --}}
    <div class="relative bg-white rounded-lg shadow-xl w-full {{ $maxWidthClass }} mx-auto max-h-[90vh] overflow-y-auto">
        {{ $slot }}
    </div>
</div>