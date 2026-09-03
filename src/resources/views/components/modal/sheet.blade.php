@props(['name'])

<div id="sheet-{{ $name }}" data-sheet class="hidden fixed inset-0 z-40">
    <div class="absolute inset-0 bg-gray-900/50" data-sheet-close></div>

    <div data-sheet-panel
         class="absolute bottom-0 inset-x-0 bg-white rounded-t-2xl shadow-xl transform translate-y-full transition-transform duration-300 ease-out max-h-[75vh] overflow-y-auto pb-[env(safe-area-inset-bottom)]">
        {{ $slot }}
    </div>
</div>