<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'TokoApp') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50">

    <!-- Sidebar: desktop & tablet -->
    @include('layouts.partials.sidebar')

    <!-- Topbar: mobile only -->
    @include('layouts.partials.mobile-topbar')

    <!-- Konten utama: geser ke kanan di desktop (md:pl-64), beri jarak bawah di mobile (pb-20) untuk bottom nav -->
    <div class="md:pl-64 pb-20 md:pb-0 min-h-screen">
        <!-- Page Heading -->
        @isset($header)
            <header class="bg-white shadow-sm">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endisset

        <!-- Page Content -->
        <main>
            {{ $slot }}
        </main>
    </div>

    <!-- Bottom Nav: mobile only -->
    @include('layouts.partials.bottom-nav')

    {{-- Script modal & sheet, di-include sekali di sini --}}
    @include('components.modal.script')
</body>
</html>